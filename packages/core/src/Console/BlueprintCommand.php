<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Kitsune\Core\Auth\FirstOwnerCredentials;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * List, apply and report on blueprints — ADR-039.
 *
 * ⚠️ ONE COMMAND, NOT THREE, for the reason `kitsune:module` gives: the split in this repo is by blast radius
 * rather than by verb, and three names is three things frozen at v1.2 for one subject. The work lives in
 * `BlueprintApplier`, so it is testable without a console and this class is argument handling.
 *
 * ⚠️ `--org` IS REQUIRED FOR `apply`, AND ON AN EMPTY INSTALLATION IT IS CREATED. ADR-039's *done when* is
 * one command on a fresh install, and ADR-030 will not move `kitsunecms.org` onto Kitsune until a blueprint
 * applies "with no manual step outside the apply flow — no hand-edited config, no SQL, no *and then you also
 * need to*". A fresh install has no org, so requiring one to exist put a step outside the flow: two `create()`
 * calls in a console. Naming the org in the apply command is inside the flow; writing it by hand first was not.
 *
 * The creation happens only when the installation has NO org at all — see `FirstOrg` for why that condition
 * is the one that makes it safe to do without asking. ~~No user is created: ADR-026 says onboarding creates the
 * first one interactively, and an account is a credential rather than a tenancy row.~~
 *
 * ⚠️ `--owner` CREATES THE FIRST OWNER WITH IT, AND NOWHERE ELSE (ADR-026, as amended by ADR-039). Only in the run that
 * creates the first org, on an installation with no org and no account; anywhere else it is refused before anything is
 * asked for. The password is typed twice at a hidden prompt, or piped on the first line of standard input with
 * `--owner-password-stdin` — never an argument or an environment variable, which other local users read through `ps`
 * and `/proc` and which shell history keeps, and never generated, because a generated value has to be printed to be
 * used. Without `--owner` no account is created, as before, and the org is one nobody can sign in to.
 */
final class BlueprintCommand extends Command
{
    private const ACTIONS = ['list', 'status', 'apply'];

    protected $signature = 'kitsune:blueprint {action=list : list, status or apply} {handle? : the blueprint, e.g. blog} {--org= : the org slug to apply into, created with a first site when the installation has none} {--org-name= : the name for an org this creates, defaulting to a humanised slug} {--site= : the slug for the first site, defaulting to the org slug} {--locale=en : the first site\'s locale} {--owner= : on an installation with no organisation and no account only — the email address of its first owner, created with the organisation; the password is asked twice, hidden (ADR-026)} {--owner-password-stdin : read the first owner\'s password from the first line of standard input instead of asking}';

    protected $description = 'List, apply and report on Kitsune blueprints (ADR-039)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error("`{$action}` is not a blueprint action. Use: ".implode(', ', self::ACTIONS).'.');

            return self::FAILURE;
        }

        return match ($action) {
            'list' => $this->list(),
            'status' => $this->status(),
            default => $this->apply(),
        };
    }

    /** An option as a string, or null when it was not given — the narrowing PHPStan wants, written once. */
    private function optionAsString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** What this installation knows about, which is what some PHP has registered — never a remote index. */
    private function list(): int
    {
        $rows = [];

        foreach (app(BlueprintRegistry::class)->all() as $handle => $definition) {
            $rows[] = [$handle, $definition->version(), $definition::class];
        }

        if ($rows === []) {
            $this->info('No blueprints are registered.');

            return self::SUCCESS;
        }

        $this->table(['Handle', 'Version', 'Defined by'], $rows);

        return self::SUCCESS;
    }

    /** What each org has had applied, including an apply that started and did not finish. */
    private function status(): int
    {
        $rows = [];

        /*
         * Past the org scope on purpose: this is an operator's question about the whole installation, asked
         * from a console with no org in context, where a scoped read returns nothing whatever is in the table.
         */
        $receipts = Blueprint::query()->withoutGlobalScopes()->orderBy('org_id')->orderBy('handle')->get();

        foreach ($receipts as $receipt) {
            $rows[] = [
                (string) $receipt->org_id,
                (string) $receipt->handle,
                (string) $receipt->version,
                $receipt->applied_at?->toDateTimeString() ?? ($receipt->manifest === null
                    ? 'INTERRUPTED — no rows written; re-run to apply'
                    : 'INTERRUPTED — rows written, not finished; re-run to finish'),
            ];
        }

        if ($rows === []) {
            $this->info('No blueprint has been applied in any organisation.');

            return self::SUCCESS;
        }

        $this->table(['Org', 'Handle', 'Version', 'Applied'], $rows);

        return self::SUCCESS;
    }

    private function apply(): int
    {
        $handle = $this->argument('handle');

        if (! is_string($handle) || $handle === '') {
            $this->error('`kitsune:blueprint apply` needs a blueprint, e.g. `kitsune:blueprint apply blog --org=acme`.');

            return self::FAILURE;
        }

        $definition = app(BlueprintRegistry::class)->get($handle);

        if ($definition === null) {
            $this->error("No blueprint is registered under [{$handle}]. `kitsune:blueprint list` shows what is.");

            return self::FAILURE;
        }

        /*
         * ⚠️ BEFORE THE ORG, NOT ONLY BEFORE THE RECEIPT. What a definition says of itself needs no database to be
         * found wrong, and on an empty installation the next step writes the first org — which a malformed
         * definition would otherwise leave behind, and with it an installation that is no longer empty.
         */
        try {
            BlueprintApplier::refuseMalformed($definition);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $slug = $this->option('org');

        if (! is_string($slug) || $slug === '') {
            $this->error('`kitsune:blueprint apply` needs an organisation, e.g. `--org=acme`. A blueprint is applied into one (ADR-039).');

            return self::FAILURE;
        }

        /* `--owner=` as given, bare or empty included — not `--owner-password-stdin`, which shares its first letters. */
        $ownerEmail = null;

        if ($this->input->hasParameterOption('--owner', true) || $this->option('owner-password-stdin') === true) {
            $seated = $this->seatFirstOwner($definition, $slug);

            if ($seated === null) {
                return self::FAILURE;
            }

            [$org, $ownerEmail] = $seated;
        } else {
            $org = Org::query()->where('slug', $slug)->first();

            if ($org === null) {
                try {
                    $org = FirstOrg::create(
                        $slug,
                        $this->optionAsString('org-name'),
                        $this->optionAsString('site'),
                        $this->optionAsString('locale') ?? 'en',
                    );
                } catch (Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                $this->info(sprintf(
                    'Created organisation %s and its first site, with no owner because `--owner` was not given: nobody '
                    .'can sign in to it, and `--owner` is now refused on this installation, because it creates a first '
                    .'owner only where there is no organisation and no account (ADR-026).',
                    $slug,
                ));
            }
        }

        $context = app(Context::class);

        try {
            $context->setOrg($org);

            $result = BlueprintApplier::apply($definition);

            /* Asked while the org is in context: whether anybody here can assign what was just created. */
            $hasOwner = Role::query()->where('is_owner', true)->exists();
        } catch (Throwable $e) {
            /*
             * The reason, not a bare failure. An apply that stops part-way leaves a receipt with no
             * `applied_at`, and the message is the only thing that tells an operator which step it was.
             */
            $this->error($e->getMessage());

            /* The owner committed before the apply began, and stays: it is the apply that is owed, not the owner. */
            if ($ownerEmail !== null) {
                $this->line(sprintf(
                    'Organisation %1$s, its first site and its first owner %2$s were created before the apply and remain. '
                    .'Run the same command again without `--owner` to finish applying [%3$s].',
                    $slug,
                    $ownerEmail,
                    $definition->handle(),
                ));
            }

            return self::FAILURE;
        } finally {
            /* Given back however this ends, and cleared rather than left pointing at the applied org. */
            $context->forget();
        }

        foreach (['created', 'adopted', 'skipped'] as $kind) {
            foreach ($result[$kind] as $what) {
                $this->line(sprintf('  %-8s %s', $kind, $what));
            }
        }

        $this->info(sprintf(
            'Applied %s %s into %s. %d indexed.',
            $result['handle'],
            $result['version'],
            $slug,
            $result['indexed'],
        ));

        /* Created with no holders: who holds a role is a person's decision, audited as one (ADR-033). */
        if ($result['roles_created'] !== []) {
            $one = count($result['roles_created']) === 1;

            $this->line(sprintf(
                $hasOwner
                    ? '%d %s created and nobody holds %s: an owner assigns %3$s under Roles (ADR-033).'
                    : '%d %s created and nobody holds %s, and this organisation has no owner yet to assign %3$s (ADR-033).',
                count($result['roles_created']),
                $one ? 'role was' : 'roles were',
                $one ? 'it' : 'them',
            ));
        }

        if ($ownerEmail !== null) {
            $this->info($this->signInLine($ownerEmail, $slug));
        }

        return self::SUCCESS;
    }

    /**
     * The first org, its site and its first owner — or a refusal, printed, and null.
     *
     * ⚠️ EVERY REFUSAL BEFORE THE PASSWORD IS ASKED FOR. What can be known without it — the address, the blueprint, the
     * user model, whether the installation is empty — is checked first, so nobody types a password into a run that was
     * always going to be refused, and nothing is read from standard input that a refusal then leaves behind.
     *
     * @return array{0: Org, 1: string}|null
     */
    private function seatFirstOwner(BlueprintDefinition $definition, string $slug): ?array
    {
        $fromStdin = $this->option('owner-password-stdin') === true;

        if (! $this->input->hasParameterOption('--owner', true)) {
            $this->error('`--owner-password-stdin` reads the first owner\'s password, and no `--owner` was given. Add '
                .'`--owner=<email>`, or drop `--owner-password-stdin`. Nothing was written.');

            return null;
        }

        /* Raw, not `optionAsString()`: an empty `--owner=` is a mistake to name, not an option to drop. */
        $email = $this->option('owner');

        if (! is_string($email) || $email === '') {
            $this->error('`--owner` needs the first owner\'s email address, e.g. `--owner=you@example.com`. Nothing was written.');

            return null;
        }

        if (($refusal = FirstOwnerCredentials::emailRefusal($email)) !== null) {
            $this->error($refusal);

            return null;
        }

        foreach ($definition->roles() as $role) {
            if ($role->handle === FirstOrg::OWNER_ROLE && $role->onCollision === OnCollision::Fail) {
                $this->error(sprintf(
                    'Refusing `--owner`: blueprint [%s] declares a role [%s] with onCollision: fail, and `--owner` creates '
                    .'the organisation\'s owner role under that handle first — so the apply would be refused after the '
                    .'owner existed. Nothing was written.',
                    $definition->handle(),
                    FirstOrg::OWNER_ROLE,
                ));

                return null;
            }
        }

        if (! $this->input->isInteractive() && ! $fromStdin) {
            $this->error('Refusing `--owner` under `--no-interaction`: the password is asked at a hidden prompt, and this '
                .'run may not ask. Pipe it in with `--owner-password-stdin`, or leave `--no-interaction` off to type it, '
                .'hidden, twice. It is never read from an argument or an environment variable. Nothing was written.');

            return null;
        }

        try {
            $model = FirstOrg::ownerModel();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if (Org::query()->where('slug', $slug)->exists()) {
            $this->error(sprintf(
                'Refusing `--owner`: organisation [%1$s] already exists, and a first owner is created only by the run that '
                .'creates the first organisation on an empty installation (ADR-026). If an earlier run created %1$s with '
                .'`--owner`, its owner exists: run the same command without `--owner` to apply, or finish applying, '
                .'[%2$s]. Nothing was written.',
                $slug,
                $definition->handle(),
            ));

            return null;
        }

        try {
            /* Early, so nobody types a password for an installation that will refuse it; again inside the transaction. */
            FirstOrg::refuseUnlessEmpty($model);

            $password = $fromStdin
                ? FirstOwnerCredentials::fromStream($this->ownerPasswordStream(), $email)
                : FirstOwnerCredentials::ask($this->output, $email);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }

        /*
         * ⚠️ HASHED ONCE, HERE, AND OUTSIDE ANY TRANSACTION: no write lock is held while bcrypt works, nor while a
         * person types. Only the hash goes further.
         */
        try {
            $hash = Hash::make($password);
        } catch (Throwable $e) {
            $this->error($e->getMessage().' Nothing was written.');

            return null;
        } finally {
            unset($password);
        }

        try {
            $org = FirstOrg::createWithOwner(
                $slug,
                $this->optionAsString('org-name'),
                $this->optionAsString('site'),
                $this->optionAsString('locale') ?? 'en',
                $email,
                $hash,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }

        $this->info(sprintf(
            'Created organisation %1$s, its first site %2$s and its first owner %3$s (ADR-026).',
            $slug,
            $this->optionAsString('site') ?? $slug,
            $email,
        ));

        return [$org, $email];
    }

    /** @return resource */
    private function ownerPasswordStream()
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return $stream ?? STDIN;
    }

    /** Where the owner signs in, from the panel itself — or that there is no panel to sign in to. */
    private function signInLine(string $email, string $slug): string
    {
        $login = null;

        if (app()->bound(KitsunePanel::PANEL_BINDING)) {
            try {
                $login = app(KitsunePanel::PANEL_BINDING)->getLoginUrl();
            } catch (Throwable) {
                $login = null;
            }
        }

        return is_string($login) && $login !== ''
            ? sprintf('Sign in at %1$s as %2$s.', $login, $email)
            : sprintf('%1$s owns %2$s. No Kitsune admin panel is configured, so there is no admin to sign in to yet.', $email, $slug);
    }
}
