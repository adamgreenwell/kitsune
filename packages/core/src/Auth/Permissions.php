<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use InvalidArgumentException;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;

/**
 * The permission vocabulary, and the only place a permission is resolved — ADR-033.
 *
 * `architecture.md` §4 fixes the naming: `entry.{type_handle}.{action}`, resolved against `type_handle`
 * because one `Entry` model means one Eloquent policy for every type. This class owns the grammar of that
 * string, the registry it is validated against, and the resolution that answers it.
 *
 * ⚠️ EVERY READ GOES THROUGH `Role`, WHICH IS THE ONLY SCOPED THING IN THE LAYER. `role_permissions`
 * carries no `org_id` and `role_user` carries no membership constraint; both are safe only because the
 * query starts at an `#[OrgScoped]` model under the current org context. A resolver that joined from
 * `role_user` straight to `role_permissions` would answer with another customer's grants, which is the
 * class of defect with no framework safety net (ADR-021).
 */
final class Permissions
{
    /**
     * The actions a permission may name.
     *
     * ⚠️ A REGISTRY BECAUSE THE SUBJECT CANNOT BE ONE. The type segment is an entry type handle created at
     * runtime, so nothing can enumerate it — which is exactly why the action segment must be enumerable:
     * with both halves free, `entry.article.viwe` is a grant that parses, stores, and is never held by
     * anybody. It fails closed and invisibly, and only a closed vocabulary on one segment makes it visible.
     *
     * @var list<string>
     */
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'publish'];

    /** The subject prefix. One today; a module's own subjects are a v1.2 question, with the API. */
    public const ENTRY = 'entry';

    /**
     * The type segment that means "every entry type, including ones added later".
     *
     * ⚠️ IT IS A GRANT SOMEBODY WRITES, NEVER ONE A ROLE GETS BY DEFAULT, and that distinction is the whole
     * of ADR-033's wildcard decision. Applied silently it means a type created next month grants access to
     * data that did not exist when the role was written — a privacy failure mode, and this project fails
     * closed on those. Written explicitly it is a decision, visible in the row.
     *
     * ⚠️ AND IT IS RESOLVED AT CHECK TIME RATHER THAN EXPANDED AT GRANT TIME. Expanding it would make it
     * cover exactly the types that existed when it was written, which is the one thing it is for not doing.
     */
    public const ANY_TYPE = '*';

    /** The permission string for one action on one entry type. */
    public static function forEntryType(string $typeHandle, string $action): string
    {
        return self::ENTRY.'.'.$typeHandle.'.'.$action;
    }

    /**
     * The permission string, or a refusal naming what is wrong with it.
     *
     * @throws InvalidArgumentException
     */
    public static function validated(string $permission): string
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 3 || $parts[0] !== self::ENTRY) {
            throw new InvalidArgumentException(
                "Refusing the permission [{$permission}]: it must read ".self::ENTRY
                .'.{type_handle}.{action} — ADR-033.'
            );
        }

        [, $type, $action] = $parts;

        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(
                "Refusing the permission [{$permission}]: [{$action}] is not a registered action. "
                .'The registry is '.implode(', ', self::ACTIONS).' — ADR-033.'
            );
        }

        /*
         * ⚠️ THE TYPE SEGMENT IS CHECKED FOR SHAPE AND NOT FOR EXISTENCE, deliberately. A blueprint seeds
         * permissions alongside the entry type it creates, and the two arrive in one operation — so a grant
         * naming a type that does not exist yet is the normal case rather than a mistake. What cannot be
         * allowed is an empty or a decorated segment, because `entry..view` and `entry.art*cle.view` are
         * strings that would never match anything and would look like grants.
         */
        if ($type !== self::ANY_TYPE && preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException(
                "Refusing the permission [{$permission}]: [{$type}] is not an entry type handle or "
                .'['.self::ANY_TYPE.'] — ADR-033.'
            );
        }

        return $permission;
    }

    /**
     * Does this user hold that permission in the current org?
     *
     * ⚠️ NO USER AND NO ORG CONTEXT BOTH MEAN NO, rather than meaning "unconstrained". A console command
     * or a queue job with no org set resolves nothing, which is the same answer `OrgScope` gives a query
     * in that state — and the opposite of the answer that would be convenient.
     */
    public static function allows(?Authenticatable $user, string $permission): bool
    {
        if ($user === null || app(Context::class)->orgId() === null) {
            return false;
        }

        if (self::isOwner($user)) {
            return true;
        }

        $held = self::held($user);

        if (in_array($permission, $held, true)) {
            return true;
        }

        // The explicit wildcard, resolved here rather than expanded at grant time.
        $parts = explode('.', $permission);

        if (count($parts) !== 3) {
            return false;
        }

        return in_array(self::forEntryType(self::ANY_TYPE, $parts[2]), $held, true);
    }

    /**
     * Every permission this user holds in the current org.
     *
     * @return list<string>
     */
    public static function held(Authenticatable $user): array
    {
        [$userId, $orgId] = self::key($user);

        if ($userId === null || $orgId === null) {
            return [];
        }

        /*
         * ⚠️ SCALARS ONLY, AND BOTH OF THEM USED BY THE BODY — AGENTS.md invariant 13, which exists because
         * this exact mistake shipped once. `once()` hashes the variables the closure CAPTURES, and
         * `ReflectionClosure::getClosureUsedVariables()` reports only the ones the body actually mentions.
         * A first version of this captured `$orgId` and never referenced it, so the memo was keyed on the
         * user alone: the same person resolving permissions in org A and then in org B inside one process
         * got org A's grants both times — a cross-org read, from a cache.
         *
         * What makes the key right is that the closure MENTIONS `$orgId` — it passes it as an argument, so
         * reflection reports it. Measured both ways: dropping it from the call fails the regression test,
         * and leaving the argument in place while `resolveHeld()` ignores it does NOT, because the key is
         * computed from the closure and not from the body.
         *
         * ⚠️ WHICH IS WHY `resolveHeld()` FILTERS ON IT EXPLICITLY. An argument the body never reads is an
         * unused parameter, and an unused parameter is the same hazard invariant 13 records for an unused
         * `use` variable: the next reader deletes it, tidily, and the memo silently narrows to the user.
         * The filter is what makes the parameter load-bearing, so the key cannot be removed by accident.
         */
        return once(fn (): array => self::resolveHeld($userId, $orgId));
    }

    /**
     * @return list<string>
     */
    private static function resolveHeld(int $userId, int $orgId): array
    {
        if (! self::isMemberOfCurrentOrg($userId)) {
            return [];
        }

        return Role::query()
            // ⚠️ Explicit, though `OrgScope` filters the same column. It can only ever NARROW what the
            // scope already allows — a foreign org id here returns nothing rather than that org's roles —
            // and its real job is to keep `$orgId` from becoming a parameter nobody reads, which is what
            // the memo above is keyed on. See the note there.
            ->where('org_id', $orgId)
            ->whereIn('id', self::roleIdsFor($userId))
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('permission')->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Drop every memoised permission set, because a grant has changed one.
     *
     * ⚠️ "I changed the role and nothing happened" is the support burden `SettingsResolver::forget()` exists
     * to avoid, and the same one applies here — a check that has already resolved in this process would
     * answer from before the write.
     *
     * ⚠️ IT FLUSHES EVERYTHING `once()` HOLDS, not one key, and that is the price of using `once()` rather
     * than a static array of its own. A static array would invalidate precisely — and would also survive
     * between requests under Octane, where a stale permission set is not a slow answer but a wrong one.
     * `once()` is flushed per request by the runtime, so the primitive that cannot be invalidated precisely
     * is the primitive that cannot go stale across a boundary. A role write is rare; an over-broad flush of
     * it costs a few re-resolved memos on the next read.
     */
    public static function forget(): void
    {
        Once::flush();
    }

    /** Does this user hold a role in the current org that bypasses permission checks? */
    public static function isOwner(Authenticatable $user): bool
    {
        [$userId, $orgId] = self::key($user);

        if ($userId === null || $orgId === null) {
            return false;
        }

        // The same key discipline as `held()`: both scalars, both used by the body it calls.
        return once(fn (): bool => self::resolveIsOwner($userId, $orgId));
    }

    private static function resolveIsOwner(int $userId, int $orgId): bool
    {
        if (! self::isMemberOfCurrentOrg($userId)) {
            return false;
        }

        return Role::query()
            ->where('org_id', $orgId)
            ->whereIn('id', self::roleIdsFor($userId))
            ->where('is_owner', true)
            ->exists();
    }

    /**
     * Is this user a member of the org the context names?
     *
     * ⚠️ A ROLE ASSIGNMENT IS NOT MEMBERSHIP, and without this it would be. `role_user` lives in the
     * skeleton and knows nothing about orgs, so a row pairing a user with a role in an org they have never
     * belonged to is a row nothing rejects — and the `Role` query would resolve it happily the moment that
     * org is the current one. In the panel the user could not get there, because Filament's tenancy gates
     * on `site_user` first; relying on that is exactly the reasoning ADR-021 says has no safety net.
     *
     * ⚠️ ASKED THROUGH THE USER MODEL'S OWN SCOPED QUERY rather than by reading `org_user`. The membership
     * question already has an owner — `#[OrgScopedThroughPivot]` on the host's user model, enforced by
     * `OrgMembershipScope` — and naming the pivot table here would be a second copy of a name the host
     * configures. A host whose user model declares `#[Unscoped]` gets no check, which is that host's
     * declared choice: invariant 2 makes the declaration the contract.
     *
     * ⚠️ AND A NON-ELOQUENT `Authenticatable` GETS NO CHECK EITHER, stated rather than hidden. There is no
     * scope to apply to something that is not a model, and refusing outright would make the contract this
     * class accepts a lie.
     */
    private static function isMemberOfCurrentOrg(int $userId): bool
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return true;
        }

        return $model::query()->whereKey($userId)->exists();
    }

    /**
     * The roles assigned to a user, before any scoping.
     *
     * ⚠️ UNSCOPED ON PURPOSE AND SAFE ONLY BECAUSE OF ITS CALLERS. `role_user` lives in the skeleton and
     * knows nothing about orgs, so this returns ids a user holds anywhere on the installation. Every caller
     * feeds it into an `#[OrgScoped]` `Role` query, which is what discards the ones belonging to another
     * customer — so a `role_user` row pairing a user with a foreign role resolves nothing, by construction
     * rather than by a guard. `RoleIsolationTest` asserts that from the attacker's side.
     *
     * @return list<int>
     */
    private static function roleIdsFor(int $userId): array
    {
        return array_map('intval', DB::table('role_user')->where('user_id', $userId)->pluck('role_id')->all());
    }

    /**
     * The two scalars every memo here is keyed on.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function key(Authenticatable $user): array
    {
        $userId = $user->getAuthIdentifier();

        return [
            is_numeric($userId) ? (int) $userId : null,
            app(Context::class)->orgId(),
        ];
    }
}
