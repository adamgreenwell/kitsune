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
use Kitsune\Core\Auth\PasswordRules;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Auth\ReaderGuardFault;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ConsentPurpose;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderAddresses;
use Kitsune\Core\Readers\ReaderConsents;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderErasureJournal;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Readers\ReaderMode;
use Kitsune\Core\Readers\ReaderModeImpact;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reader accounts, for whoever runs the installation — ADR-037, as built, and ADR-020's export and erasure of one
 * reader. The operator is the data controller (ADR-020), so this, and no admin page yet, is where a reader is found,
 * exported or erased, where a site's sign-in is switched on, and where owners are let see readers' addresses on the
 * entitlements page (ADR-037 decision 1).
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

    private const ACTIONS = ['status', 'mode', 'addresses', 'find', 'export', 'erase', 'consent', 'consent-sweep', 'export-erasure-journal', 'replay-erasure-journal'];

    private const INHERIT = 'inherit';

    protected $signature = 'kitsune:readers
        {action : status, mode, addresses, find, export, erase, consent, consent-sweep, export-erasure-journal or replay-erasure-journal}
        {value? : For mode — off, sign-in, open or inherit; for addresses — hidden, shown or inherit; for consent — profiling or support_widget}
        {--org= : The organisation\'s slug}
        {--site= : For mode — the site\'s handle; without it, the organisation\'s own setting. Addresses are the organisation\'s alone}
        {--notice= : For consent — the version of the notice readers accept}
        {--retention-days= : For consent — days the choice remains effective}
        {--disable : For consent — stop offering a purpose on this site}
        {--reader= : For export and erase — the reader\'s identifier, instead of their address on standard input}
        {--record : Require a replayable account or signup link receipt}
        {--restore-host= : Confirm the restored host reader connection for split-store replay}
        {--force : Confirm erase or replay, or acknowledge closing reader download links when changing mode}';

    protected $description = 'Report whether readers can sign in, set where they may and whether owners see their addresses, and find, export or erase one reader (ADR-037)';

    public function handle(Context $context, ReaderGuard $guard, ReaderAccounts $accounts): int
    {
        $action = $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->refuse('Refusing: kitsune:readers takes status, mode, addresses, find, export, erase, consent, consent-sweep, export-erasure-journal or replay-erasure-journal. Nothing was read or written.');
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

        if ($action === 'addresses' && ! in_array($this->argument('value'), [...array_column(ReaderAddresses::cases(), 'value'), self::INHERIT], true)) {
            return $this->refuse('Refusing: readers\' addresses are hidden or shown to an organisation\'s owners — or inherit, to use the platform\'s default. Nothing was written.');
        }

        // ⚠️ THE ORGANISATION'S ALONE (`SettingsGuard::READER_ADDRESSES`): a reader is the org's, so no site can hide one.
        if ($action === 'addresses' && is_string($this->option('site')) && $this->option('site') !== '') {
            return $this->refuse('Refusing: readers\' addresses are shown or hidden for a whole organisation, whose readers they are — a site\'s owners could name the same readers on a sibling\'s page. Leave --site off. Nothing was written.');
        }

        if ($action === 'erase' && ! $this->option('force')) {
            return $this->refuse("Refusing: erase deletes the reader's account, every entitlement they hold on every site of [{$slug}], their consent choices and any link waiting for them. Run it again with --force. Nothing was written.");
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
                'mode' => $this->setting($org, SettingsGuard::READER_ACCOUNTS, 'Reader accounts'),
                'consent' => $this->consent($org),
                'consent-sweep' => $this->sweepConsents($org),
                'export-erasure-journal', 'replay-erasure-journal' => $this->erasureJournal($action),
                'addresses' => $this->setting($org, SettingsGuard::READER_ADDRESSES, 'Readers\' addresses'),
                default => $this->forReader($accounts, $org, $action),
            };
        } catch (RuntimeException $refused) {
            // `EntitlementRefused`, and the readers table's SQLSTATE-only failure: neither carries an address or a key.
            return $this->refuse($refused->getMessage());
        } finally {
            $context->forget();
        }
    }

    private function consent(Org $org): int
    {
        $value = $this->argument('value');
        $purpose = is_string($value) ? ConsentPurpose::tryFrom($value) : null;
        $handle = $this->option('site');
        if ($purpose === null || ! is_string($handle) || $handle === '') {
            return $this->refuse('Consent needs a purpose (profiling or support_widget) and --site.');
        }

        $notice = $this->option('notice');
        $days = $this->option('retention-days');
        $disable = $this->option('disable');
        $policy = ReaderConsents::parsePolicy([
            'notice_version' => $notice,
            'retention_days' => is_string($days) && preg_match('/\A[1-9][0-9]{0,3}\z/', $days) === 1 ? (int) $days : null,
        ]);
        if (($disable && ($notice !== null || $days !== null)) || (! $disable && $policy === null)) {
            return $this->refuse('Supply --disable by itself, or a valid --notice and --retention-days (1–3650).');
        }

        if ($org->trashed()) {
            return $this->refuse('Restore the organisation before changing its consent policy.');
        }

        $site = Site::query()->where('org_id', $org->id)->where('handle', $handle)->first();
        if ($site === null) {
            return $this->refuse('The selected organisation has no site with that handle.');
        }

        $writer = app(SettingsWriter::class);
        if ($disable) {
            $writer->revert($site, $purpose->setting());
        } else {
            $writer->set($site, $purpose->setting(), $policy);
        }

        $this->info($disable ? 'Consent choice disabled.' : 'Consent notice and retention saved.');

        return self::SUCCESS;
    }

    private function sweepConsents(Org $org): int
    {
        if (! $this->option('force')) {
            return $this->refuse('Run consent-sweep with --force to delete consent records past their retention date.');
        }

        $count = app(ReaderConsents::class)->sweep($org->id);
        $this->info(sprintf('Removed %d expired consent records.', $count));

        return self::SUCCESS;
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

        $mail = ReaderMail::fault();
        $mailer = ReaderMail::mailerName();

        $this->line(match (true) {
            $mail !== null => sprintf('Reader mail: CLOSED — %s, so sign-up and recovery answer 503. Sign-in still works.', $mail),
            ! app()->environment(['local', 'testing']) => sprintf('Reader mail: deliverable through [%s], from [%s].', $mailer, (string) config('mail.from.address')),
            default => sprintf('Reader mail: through [%s], allowed only because the environment is [%s].', $mailer, (string) app()->environment()),
        });

        // Measured (M4): a strict cookie is not sent on a click from another site's page, nor on the redirect after it.
        if (strtolower((string) config('session.same_site')) === 'strict') {
            $this->line('Sessions: SameSite is strict (SESSION_SAME_SITE), so a mailed link opened from a webmail page arrives without its session and answers 410; pasted into the address bar it works. lax, Laravel\'s default, works both ways.');
        }

        // ⚠️ ADR-041's reader downloads: under `none`, another site's page sends the reader's session with what it embeds.
        // `IsolateDownload` refuses what a browser marks as from elsewhere; a browser that marks nothing is not refused.
        if (strtolower((string) config('session.same_site')) === 'none') {
            $this->line('Sessions: SameSite is none (SESSION_SAME_SITE), so another site\'s page sends a reader\'s session with what it asks of this one. A file offered to readers refuses what the browser marks as coming from elsewhere, but a browser that marks nothing could show that page whether its visitor holds the file. lax, Laravel\'s default, sends the session only when a reader follows a link.');
        }

        $passwords = PasswordRules::misconfiguration();

        $this->line($passwords === null
            ? sprintf('Passwords: at least %d characters.', PasswordRules::minCharacters())
            : sprintf('Passwords: at least %d characters — %s.', PasswordRules::minCharacters(), $passwords));

        if ($org !== null) {
            $resolver = app(SettingsResolver::class);

            foreach (Site::query()->orderBy('handle')->get() as $site) {
                $resolved = $resolver->resolve($site, SettingsGuard::READER_ACCOUNTS);
                $addresses = $resolver->resolve($site, SettingsGuard::READER_ADDRESSES);

                // Whether addresses are shown, and where that comes from — never an address.
                $this->line(sprintf(
                    'Site [%s]: %s (%s); addresses %s owners (%s)%s.',
                    $site->handle,
                    $accounts->mode($site)->value,
                    $resolved?->describe() ?? 'platform default',
                    match (true) {
                        ! $accounts->canName() => 'cannot be shown to',
                        $accounts->addresses($site) => 'shown to',
                        default => 'hidden from',
                    },
                    match (true) {
                        ! $accounts->canName() => 'this installation\'s reader accounts cannot name readers',
                        // Written below Eloquent past `SettingsGuard`: it counts for nothing, and is not the site's choice.
                        $addresses !== null && ! in_array($addresses->origin, ['org', 'default'], true) => 'a value stored below the organisation is ignored — the setting is the organisation\'s',
                        default => $addresses?->describe() ?? 'platform default',
                    },
                    match (true) {
                        $site->canonical_host === null => ' — it has no public address yet, so no reader page can be reached; kitsune:site address gives it one',
                        ReaderLinks::origin($site) === null => ' — no link can be mailed from it, because '.($site->canonical_host === '' ? 'it has no host of its own' : 'its address names no scheme').'; kitsune:site address gives it one',
                        default => '',
                    },
                ));
            }
        }

        return $guardFault !== ReaderGuardFault::NotDeclared && $fault !== null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `mode` and `addresses`: one platform key at the org — or, for `mode`, at one of its sites — written through
     * `SettingsWriter`, so audited as every key is, and `inherit` a revert, because absent means inherit (ADR-022).
     *
     * ⚠️ THE SITE IS FOUND WITHIN THE ORG. The org is in context and `Site` is org-scoped, so `golfdom`, a handle two
     * organisations may each have, names this org's alone.
     */
    private function setting(Org $org, string $setting, string $label): int
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

        if ($setting === SettingsGuard::READER_ACCOUNTS) {
            $impact = app(ReaderModeImpact::class)->count($scope, $value);

            if ($impact['files'] > 0) {
                $this->warn(__('kitsune::readers.mode_warning.impact', $impact));
                $this->line(__('kitsune::readers.mode_warning.consequence'));

                if (! $this->option('force')) {
                    return $this->refuse(__('kitsune::readers.mode_warning.confirm'));
                }
            }
        }

        if ($value === self::INHERIT) {
            $writer->revert($scope, $setting);
        } else {
            $writer->set($scope, $setting, $value);
        }

        $this->line(sprintf('%s on [%s]: %s.', $label, $scope instanceof Site ? $scope->handle : $org->slug, $value));

        // Written, and said to have no effect yet where it has none (review): the page shows identifiers until then.
        if ($setting === SettingsGuard::READER_ADDRESSES && ! app(ReaderAccounts::class)->canName()) {
            $this->line('This installation\'s reader accounts cannot name readers yet, so the entitlements page shows identifiers until they can — kitsune:readers status says why.');
        }

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

            if ($key === null && $action === 'find') {
                $this->error("No reader in [{$org->slug}] has that address.");

                return self::FAILURE;
            }

            // ⚠️ AN ADDRESS WITH NO ACCOUNT: nothing held under a reader's identifier can be looked for by it — a reader
            // the host deleted is found by `--reader` — so this says so and exits 1; only a sign-up link it never used is
            // held under the address itself, and that is reported or erased (review).
            if ($key === null) {
                $missing = sprintf('No reader in [%s] has that address, so nothing held under a reader\'s identifier was looked for — for a reader the host deleted, run again with --reader=<id>.', $org->slug);

                if ($action === 'export') {
                    $links = app(ReaderExport::class)->forAddress($address);

                    $this->error($missing.' '.($links === []
                        ? 'No sign-up link mailed to it is waiting.'
                        : sprintf('A sign-up link mailed to it waits unused at [%s], until %s.', (string) $links[0]['site'], $links[0]['expires_at'])));

                    return self::FAILURE;
                }

                $erased = app(ReaderErasure::class)->eraseAddress($address);
                if ($erased > 0 && $this->option('record')) {
                    $this->info('Erased the pending signup link and recorded its replay receipt.');

                    return self::SUCCESS;
                }
                $this->error(sprintf('%s Erased %d pending sign-up link%s.', $missing, $erased, $erased === 1 ? '' : 's'));

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

        $erased = app(ReaderErasure::class)->erase($key, requireReceipt: $this->option('record') === true);

        $this->line(sprintf(
            'Erased %d entitlement grant%s and %d pending link%s; %s.',
            $erased['grants'],
            $erased['grants'] === 1 ? '' : 's',
            $erased['links'],
            $erased['links'] === 1 ? '' : 's',
            $erased['account'] ? 'the account was deleted' : 'no account had that identifier',
        ));

        return self::SUCCESS;
    }

    private function erasureJournal(string $action): int
    {
        if (is_string($this->option('site')) && $this->option('site') !== '') {
            return $this->refuse('Reader erasure journals cover the whole Org. Leave --site off.');
        }

        $journal = app(ReaderErasureJournal::class);
        if ($action === 'export-erasure-journal') {
            $this->output->writeln($journal->export(), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            return $this->refuse('Run replay-erasure-journal with --force.');
        }

        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN;
        if (@stream_isatty($stream) || ($input = fgets($stream)) === false) {
            return $this->refuse('Supply the reader erasure journal on standard input.');
        }

        $restoreHost = $this->option('restore-host');
        $result = $journal->replay(rtrim($input, "\r\n"), is_string($restoreHost) ? $restoreHost : null);
        $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return $result['unavailable'] + $result['module_unavailable'] + $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
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
