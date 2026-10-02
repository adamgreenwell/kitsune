<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Create the first org and its first site, on an installation that has neither — ADR-039 — and, when asked, its first
 * owner (ADR-026, as amended).
 *
 * @internal
 *
 * ⚠️ THIS IS WHAT MAKES ADR-030's TRIGGER SATISFIABLE, and without it the *done when* cannot be met by any
 * amount of blueprint code. That ADR moves `kitsunecms.org` onto Kitsune when the Marketing Site blueprint
 * "applies to a fresh install with no manual step outside the apply flow — no hand-edited config, no SQL, no
 * *and then you also need to*". A fresh install has no org, so an apply that requires one already existing
 * needs a step outside itself; the operator had to open a console and write two `create()` calls, which is
 * precisely the "and then you also need to" the condition forbids.
 *
 * ⚠️ ONLY WHEN THE INSTALLATION IS EMPTY, which is what makes this safe to do without asking. Zero orgs is a
 * state with nothing to damage and one unambiguous reading. On an installation that already has an org, an
 * unknown `--org` slug stays an error: creating one there would be inventing a customer because somebody
 * mistyped, and the receipt would record a blueprint applied into it.
 *
 * ⚠️ IT CREATES A USER ONLY WHEN ASKED, AND ONLY HERE (ADR-026, as amended by ADR-039's first owner). ~~It creates no
 * user, and that is ADR-026 rather than an omission.~~ What ADR-026 forbids is a *default* administrator — an account
 * the installer invents or ships. `createWithOwner()` creates the installation's first account from an address and a
 * password the operator gave in that run, only where there is no org and no account at all, as the owner of the org it
 * creates — in the same transaction, so there is never an org whose owner half-exists. `create()` still creates none,
 * and the org it makes is one nobody can sign in to.
 *
 * ⚠️ THE SITE CLAIMS NO HOST. `base_url` is left null, which is the admin-only site ADR-021 describes, so
 * none of the host-claim machinery runs: no canonical host, no overlap check, no mutex. A fresh install does
 * not know its own public URL yet, and guessing one would take a claim the operator has not made.
 */
final class FirstOrg
{
    /** The first owner's role: created here, never by a blueprint, which cannot carry the owner flag at all. */
    public const OWNER_ROLE = 'owner';

    public const OWNER_ROLE_NAME = 'Owner';

    /**
     * The first org and its site, with no account.
     *
     * @throws RuntimeException when the installation already has an org
     */
    public static function create(string $slug, ?string $name, ?string $siteSlug, string $locale): Org
    {
        return self::bootstrap($slug, $name, $siteSlug, $locale, null, null, null);
    }

    /**
     * The first org, its site, and its first owner — who can sign in, and owns it — or nothing at all.
     *
     * @throws RuntimeException
     */
    public static function createWithOwner(
        string $slug,
        ?string $name,
        ?string $siteSlug,
        string $locale,
        string $ownerEmail,
        #[\SensitiveParameter] string $ownerPasswordHash,
    ): Org {
        return self::bootstrap($slug, $name, $siteSlug, $locale, self::ownerModel(), $ownerEmail, $ownerPasswordHash);
    }

    /**
     * The host's user model, if core can create the first owner in it and let them in — or a refusal saying why not.
     *
     * ⚠️ ASKED BEFORE ANYTHING IS ASKED FOR OR WRITTEN. Each of these would otherwise surface after the password was typed,
     * as a post-condition failing inside the transaction — or not at all, as an owner Kitsune does not see as one.
     *
     * @return class-string<Model&ProvisionsMembership&HasTenants>
     *
     * @throws RuntimeException
     */
    public static function ownerModel(): string
    {
        $model = Permissions::userModel();

        if ($model === null) {
            throw new RuntimeException(
                'Refusing `--owner`: no user model resolves — neither Kitsune\'s panel nor `auth.providers.users.model` '
                .'names one — so there is no account to create. Nothing was written.'
            );
        }

        $required = [ProvisionsMembership::class, HasTenants::class];

        if (app()->bound(KitsunePanel::PANEL_BINDING)) {
            $required[] = FilamentUser::class;
        }

        $missing = array_values(array_filter($required, static fn (string $contract): bool => ! is_subclass_of($model, $contract)));

        if ($missing !== [] || ! is_subclass_of($model, Model::class)) {
            throw new RuntimeException(sprintf(
                'Refusing `--owner`: the user model [%s] does not implement %s, so Kitsune cannot create an account in '
                .'it and let that account into the admin without guessing at its columns and pivots. Nothing was written.',
                $model,
                implode(' and ', $missing === [] ? [Model::class] : $missing),
            ));
        }

        if (! array_key_exists(OrgMembershipScope::class, (new $model)->getGlobalScopes())) {
            throw new RuntimeException(sprintf(
                'Refusing `--owner`: the user model [%s] registers no membership scope (`#[OrgScopedThroughPivot]` with '
                .'`use EnforcesScope`), so Kitsune could never see the owner as a member of the organisation, and would '
                .'never treat them as its owner. Nothing was written.',
                $model,
            ));
        }

        if (! Permissions::assignmentsAreAbout($model)) {
            throw new RuntimeException(sprintf(
                'Refusing `--owner`: role assignments (`role_user.user_id`) do not refer to [%s] on the default '
                .'connection, so an owner role given to it would name somebody else or nobody (ADR-033). Nothing was written.',
                $model,
            ));
        }

        /** @var class-string<Model&ProvisionsMembership&HasTenants> $model */
        return $model;
    }

    /**
     * Refuse unless the installation is empty: no org, and — when a first owner is to be created — no account.
     *
     * ⚠️ PAST EVERY SCOPE, ON PURPOSE. This asks about the INSTALLATION, from a console with no org in context, where a
     * scoped read answers about nothing whatever is in the table: the users' membership scope is `1 = 0` with no org,
     * so a scoped count of accounts is always zero, and would wave a first owner in beside every account there is.
     * `withTrashed` / `withoutGlobalScopes` count the deleted too: a trashed org is still a row whose slug is taken and
     * whose content is recoverable, and a deleted account is somebody's.
     *
     * @param  class-string<Model>|null  $userModel  null for the path that creates no account: orgs only
     *
     * @throws RuntimeException
     */
    public static function refuseUnlessEmpty(?string $userModel): void
    {
        $orgs = Org::query()->withTrashed()->count();

        if ($userModel === null) {
            if ($orgs > 0) {
                throw new RuntimeException(
                    "Refusing to create an organisation: this installation already has {$orgs}. A blueprint "
                    .'creates the first org and site only on an installation that has neither, because that is the '
                    .'one state with nothing to damage. Name an organisation that exists, or create the one you '
                    .'meant deliberately.'
                );
            }

            return;
        }

        $accounts = $userModel::query()->withoutGlobalScopes()->count();

        if ($orgs > 0 || $accounts > 0) {
            throw new RuntimeException(sprintf(
                'Refusing `--owner`: this installation has %d organisation(s), deleted ones included, and %d account(s). '
                .'A first owner is created only where there are neither, so it can never take over an account or become '
                .'an administrator beside the accounts something else created (ADR-026). Nothing was written.',
                $orgs,
                $accounts,
            ));
        }
    }

    /**
     * @param  class-string<Model&ProvisionsMembership&HasTenants>|null  $userModel  null: create no account
     *
     * @throws RuntimeException
     */
    private static function bootstrap(
        string $slug,
        ?string $name,
        ?string $siteSlug,
        string $locale,
        ?string $userModel,
        ?string $ownerEmail,
        #[\SensitiveParameter] ?string $ownerPasswordHash,
    ): Org {
        $name ??= Str::headline($slug);
        $siteSlug ??= $slug;

        $context = app(Context::class);
        $step = 'checking the installation is empty';

        /*
         * One transaction, because a site slug is globally unique and can fail after the org is written —
         * which is the exact sequence `BenchmarkStorageCommand::fixture()` records paying for: "a site slug
         * another org already held failed the insert after the org was made, and left the org". Here the org
         * being left behind would be worse than untidy: the next run would find one org, refuse to bootstrap,
         * and tell the operator to name an organisation that exists but has no site.
         *
         * ⚠️ AND THE OWNER IN THE SAME ONE. An org whose owner failed half way would be an org `--owner` is refused on
         * for good — so the account, its memberships, the owner role and its assignment commit with the org, or none of
         * them do. Every nested transaction below (the guarded membership, `Role::save`, `assignTo`, the auditor) is a
         * savepoint on this connection: `ownerModel()` has already proved the user model and `role_user` share it.
         */
        /*
         * ⚠️ AND THE CONTEXT IS CLEARED WHEN IT ROLLS BACK. The transaction sets the new org in context, and a
         * rollback took the org away and left the context naming it — an org no row holds any longer. Cleared, not
         * restored: this runs only on an installation with no org, so no org or site was in context before it that
         * a caller could want back.
         */
        try {
            return (new Org)->getConnection()->transaction(static function () use (
                $context, $slug, $name, $siteSlug, $locale, $userModel, $ownerEmail, $ownerPasswordHash, &$step,
            ): Org {
                /* Again inside, where it holds: the check before the password was asked for was an early answer, not this one. */
                self::refuseUnlessEmpty($userModel);

                $step = 'creating the organisation';
                /* `Org` is `#[Unscoped]` — it is the root of the hierarchy, so there is no context to set first. */
                $org = Org::create(['slug' => $slug, 'name' => $name]);

                /* And now there is. Context first, then the row: `EnforcesScope` refuses a scope key nobody vouched for. */
                $context->setOrg($org);

                $step = 'creating its first site';
                $site = Site::create([
                    'handle' => $siteSlug,
                    'slug' => $siteSlug,
                    'name' => $name,
                    'locale' => $locale,
                ]);

                if ($userModel !== null && $ownerEmail !== null && $ownerPasswordHash !== null) {
                    self::seatOwner($userModel, $ownerEmail, $ownerPasswordHash, $org, $site, $step);
                }

                return $org;
            });
        } catch (PDOException $e) {
            $context->forget();

            /* The path that creates no account carries no secret, so it keeps the database's own words. */
            if ($userModel === null) {
                throw $e;
            }

            /*
             * ⚠️ THE DATABASE'S OWN MESSAGE IS NOT REPEATED, AND NOT CHAINED. A query exception's message carries its
             * bindings — the owner's password hash among them — and PostgreSQL's adds the whole failing row; a chained
             * original would be reported, and logged, with them.
             */
            throw new RuntimeException(sprintf(
                'Creating the first organisation and its owner failed while %s (SQLSTATE %s). The database\'s own message '
                .'is not shown, because it can contain the owner\'s password hash. Nothing was written.',
                $step,
                (string) ($e->errorInfo[0] ?? $e->getCode()),
            ));
        } catch (Throwable $e) {
            $context->forget();

            throw $e;
        }
    }

    /**
     * The account, its memberships, the owner role and its assignment — then proof the owner can sign in and owns the org.
     *
     * ⚠️ MEMBERSHIP BEFORE THE ROLE. Assigned first, the role would make the membership that follows an owner joining,
     * audited as `org.owner_added` beside `role.owner_assigned`; in this order the one row is `role.owner_assigned`, with
     * no actor — the system, from the console (ADR-020).
     *
     * @param  class-string<Model&ProvisionsMembership&HasTenants>  $userModel
     *
     * @throws RuntimeException
     */
    private static function seatOwner(
        string $userModel,
        string $email,
        #[\SensitiveParameter] string $passwordHash,
        Org $org,
        Site $site,
        string &$step,
    ): void {
        $step = 'creating the owner\'s account';
        /* The address is the name too: nothing can edit a name yet, and the panel needs one to show. */
        $user = $userModel::provisionAccount($email, $email, $passwordHash);

        /*
         * The stored row, not the instance: a host that hashed the hash again stored a password nobody can type. Read past
         * the scopes, because the account is no member of anything yet, so its own scoped query cannot see it.
         */
        $found = $userModel::query()->withoutGlobalScopes()->whereKey($user->getKey())->first();
        $stored = $found instanceof Authenticatable ? $found->getAuthPassword() : null;

        if (! is_string($stored) || ! hash_equals($passwordHash, $stored)) {
            throw new RuntimeException(sprintf(
                'Refusing to create the owner: %s::provisionAccount() did not store the password hash it was given as the '
                .'account\'s password, so the owner could not sign in with the password typed. Nothing was written.',
                $userModel,
            ));
        }

        $step = 'making the owner a member of the organisation';
        $user->admitToOrg($org);

        $step = 'letting the owner enter the site';
        $user->admitToSite($site);

        $step = 'creating the owner role';
        $role = Role::create(['handle' => self::OWNER_ROLE, 'name' => self::OWNER_ROLE_NAME, 'is_owner' => true]);

        $step = 'assigning the owner role';
        $role->assignTo($user->getAuthIdentifier());

        $step = 'checking the owner can sign in';

        if (! Permissions::isOwner($user)) {
            throw new RuntimeException(sprintf(
                'Refusing to create the owner: Kitsune\'s authorisation does not see the new account as the owner of [%s] — '
                .'%s::admitToOrg() wrote no membership its scope can see. Nothing was written.',
                $org->slug,
                $userModel,
            ));
        }

        if (! $user->canAccessTenant($site)) {
            throw new RuntimeException(sprintf(
                'Refusing to create the owner: the new account cannot enter site [%s] in the admin — %s::admitToSite() '
                .'gave it no access the panel can see. Nothing was written.',
                $site->slug,
                $userModel,
            ));
        }

        if (app()->bound(KitsunePanel::PANEL_BINDING) && $user instanceof FilamentUser && ! $user->canAccessPanel(app(KitsunePanel::PANEL_BINDING))) {
            throw new RuntimeException(sprintf(
                'Refusing to create the owner: the admin panel would not let the new account in — %s::canAccessPanel() '
                .'refused it. Nothing was written.',
                $userModel,
            ));
        }
    }
}
