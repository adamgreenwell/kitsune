<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\FirstOwnerCredentials;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Auth\ReaderGuardFault;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderMode;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reader accounts, for whoever runs the installation — ADR-037, as built, and ADR-020's export and erasure of one
 * reader. The operator is the data controller (ADR-020), so this, and no admin page yet, is where a reader is found,
 * exported or erased, and where a site's sign-in is switched on.
 *
 * ⚠️ AN ADDRESS IS NEVER AN OPTION. It is asked at a prompt on a terminal, or read from the first line of standard input
 * (at most 4,096 bytes) — never from an argument, which other local users read through `ps` and which shell history
 * keeps. And nothing printed repeats an address, or a reader's identifier the operator gave.
 *
 * ⚠️ NOBODY IS SIGNED IN HERE, so `export` and `erase` act as the system, as `kitsune:entitlements` does.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReadersCommand extends Command
{
    use ReadsWrittenKeys;

    private const ACTIONS = ['status', 'mode', 'find', 'export', 'erase'];

    private const INHERIT = 'inherit';

    protected $signature = 'kitsune:readers
        {action : status, mode, find, export or erase}
        {value? : For mode — off, sign-in, open or inherit}
        {--org= : The organisation\'s slug}
        {--site= : For mode — the site\'s handle; without it, the organisation\'s own setting}
        {--reader= : For export and erase — the reader\'s identifier, instead of their address on standard input}
        {--force : Confirm erase, which deletes}';

    protected $description = 'Report whether readers can sign in, set where they may, and find, export or erase one reader (ADR-037)';

    public function handle(Context $context, ReaderGuard $guard, ReaderAccounts $accounts): int
    {
        $action = $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->refuse('Refusing: kitsune:readers has five actions — status, mode, find, export and erase. Nothing was read or written.');
        }

        $slug = $this->option('org');

        if ($action === 'status' && ($slug === null || $slug === '')) {
            return $this->status($guard, $accounts, null);
        }

        if (! is_string($slug) || $slug === '') {
            return $this->refuse("Refusing: {$action} needs --org=<slug>. Nothing was read or written.");
        }

        if ($action === 'mode' && ! in_array($this->argument('value'), [...array_column(ReaderMode::cases(), 'value'), self::INHERIT], true)) {
            return $this->refuse('Refusing: a site\'s reader accounts are off, sign-in or open — or inherit, to use the level above. Nothing was written.');
        }

        if ($action === 'erase' && ! $this->option('force')) {
            return $this->refuse("Refusing: erase deletes the reader's account and every entitlement they hold on every site of [{$slug}]. Run it again with --force. Nothing was written.");
        }

        // ⚠️ WITH THE TRASHED: a soft-deleted org keeps its rows for its restore, so its readers' requests stay answerable.
        $org = Org::withTrashed()->where('slug', $slug)->first();

        if ($org === null) {
            // A slug is configuration, not a person, so it is named.
            return $this->refuse("Refusing: no organisation has the slug [{$slug}]. Nothing was read or written.");
        }

        try {
            $context->forget()->setOrg($org);

            return match ($action) {
                'status' => $this->status($guard, $accounts, $org),
                'mode' => $this->mode($org),
                default => $this->forReader($accounts, $org, $action),
            };
        } catch (RuntimeException $refused) {
            // `EntitlementRefused`, and the readers table's SQLSTATE-only failure: neither carries an address or a key.
            return $this->refuse($refused->getMessage());
        } finally {
            $context->forget();
        }
    }

    private function status(ReaderGuard $guard, ReaderAccounts $accounts, ?Org $org): int
    {
        $guardFault = $guard->fault();

        $this->line(match ($guardFault) {
            null => sprintf('Reader guard: declared and usable [%s].', $guard->name()),
            ReaderGuardFault::NotDeclared => 'Reader guard: none declared (kitsune.readers.guard) — no reader can sign in.',
            default => sprintf('Reader guard: declared and NOT usable — %s.', $guard->faultSentence()),
        });

        $fault = $accounts->fault();

        $this->line($fault === null
            ? 'Reader accounts: usable — the model implements ReaderAccount.'
            : sprintf('Reader accounts: NOT usable — %s.', $accounts->faultSentence()));

        if ($org !== null) {
            $resolver = app(SettingsResolver::class);

            foreach (Site::query()->orderBy('handle')->get() as $site) {
                $resolved = $resolver->resolve($site, SettingsGuard::READER_ACCOUNTS);

                $this->line(sprintf(
                    'Site [%s]: %s (%s)%s.',
                    $site->handle,
                    $accounts->mode($site)->value,
                    $resolved?->describe() ?? 'platform default',
                    $site->canonical_host === null ? ' — it has no public address yet, so no reader page can be reached; kitsune:site address gives it one' : '',
                ));
            }
        }

        return $guardFault !== ReaderGuardFault::NotDeclared && $fault !== null ? self::FAILURE : self::SUCCESS;
    }

    private function mode(Org $org): int
    {
        $value = (string) $this->argument('value');
        $handle = $this->option('site');
        $scope = $org;

        if (is_string($handle) && $handle !== '') {
            $scope = Site::query()->where('handle', $handle)->first();

            if ($scope === null) {
                return $this->refuse("Refusing: [{$org->slug}] has no site with the handle [{$handle}]. Nothing was written.");
            }
        }

        $writer = app(SettingsWriter::class);

        if ($value === self::INHERIT) {
            $writer->revert($scope, SettingsGuard::READER_ACCOUNTS);
        } else {
            $writer->set($scope, SettingsGuard::READER_ACCOUNTS, $value);
        }

        $this->line(sprintf('Reader accounts on [%s]: %s.', $scope instanceof Site ? $scope->handle : $org->slug, $value));

        return self::SUCCESS;
    }

    /** find, export and erase: one reader of the org, named by `--reader` or by an address read from the operator. */
    private function forReader(ReaderAccounts $accounts, Org $org, string $action): int
    {
        if ($accounts->fault() !== null) {
            return $this->refuse(sprintf(
                'Refusing: reader accounts are not usable here — %s. `kitsune:entitlements forget` still erases a reader\'s entitlements. Nothing was read or written.',
                $accounts->faultSentence(),
            ));
        }

        $given = $action === 'find' ? null : $this->option('reader');

        if (is_string($given) && $given !== '') {
            $key = app(ReaderGuard::class)->key($given);

            if ($key === null) {
                return $this->refuse('Refusing: that is not an identifier a reader can have here. The value is not repeated. Nothing was read or written.');
            }
        } else {
            $address = $this->address();

            if ($address === null) {
                return self::FAILURE;
            }

            $model = $accounts->model() ?? throw new RuntimeException('Reader accounts are not usable here.');
            $found = ReaderAccounts::mapped(static fn () => $model::findByEmail($address));
            // Fenced on the row's own `org_id`, as sign-in fences it, against a host lookup that strips the scope (review).
            $key = $found !== null && self::writtenKey($found->getAttributes()['org_id'] ?? null) === $org->getKey()
                ? app(ReaderGuard::class)->key($found->getAuthIdentifier())
                : null;

            if ($key === null) {
                $this->error("No reader in [{$org->slug}] has that address.");

                return self::FAILURE;
            }

            if ($action === 'find') {
                $this->line("Reader {$key}.");

                return self::SUCCESS;
            }
        }

        if ($action === 'export') {
            $this->line((string) json_encode(app(ReaderExport::class)->for($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $erased = app(ReaderErasure::class)->erase($key);

        $this->line(sprintf(
            'Erased %d entitlement grant%s; %s.',
            $erased['grants'],
            $erased['grants'] === 1 ? '' : 's',
            $erased['account'] ? 'the account was deleted' : 'no account had that identifier',
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the address is asked at a prompt: on a terminal, when this run may ask.
     *
     * ⚠️ A PIPE OR A FILE IS READ, NEVER PROMPTED ON, whatever `--no-interaction` says. Symfony would print the question
     * to standard output and then read the answer from the pipe — and an export's JSON is what that output is.
     *
     * @param  resource  $stream
     */
    public static function asks($stream, bool $interactive): bool
    {
        return $interactive && @stream_isatty($stream);
    }

    /**
     * The address, normalised — asked at a prompt on a terminal, else the first line of standard input — or null, with
     * the refusal printed.
     */
    private function address(): ?string
    {
        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN;

        if (self::asks($stream, $this->input->isInteractive())) {
            /*
             * ⚠️ ON STANDARD ERROR (review): `export > reader.json` from a terminal wrote the question into the file
             * ahead of the JSON, and asked nothing on screen.
             */
            $console = $this->output->getOutput();
            $typed = (new SymfonyStyle($this->input, $console instanceof ConsoleOutputInterface ? $console->getErrorOutput() : $console))
                ->askQuestion(new Question('The reader\'s email address'));
        } elseif (@stream_isatty($stream)) {
            $this->error('Refusing: the address is asked at a prompt or read from standard input, and this run may not ask. '
                .'Pipe it in, or leave --no-interaction off. It is never read from an option. Nothing was read or written.');

            return null;
        } else {
            $line = fgets($stream, EmailAddress::READ_BOUND + 1);
            $typed = $line === false ? '' : FirstOwnerCredentials::withoutLineEnd($line);
        }

        $address = is_string($typed) ? EmailAddress::normalise($typed) : null;

        if ($address === null) {
            $this->error('Refusing: that is not an address a reader could have signed up with. The value is not repeated. Nothing was read or written.');
        }

        return $address;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
