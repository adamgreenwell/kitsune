<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use InvalidArgumentException;
use Kitsune\Core\Models\Entry;
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
        $refusal = self::refusalFor($permission);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        return $permission;
    }

    /**
     * Why this string is not a permission, or null when it is one.
     *
     * ⚠️ EXTRACTED SO `allows()` CAN ASK THE SAME QUESTION WITHOUT THROWING, which review found it needing:
     * the wildcard match built `entry.*.{third segment}` out of ANY three-segment string, so a holder of
     * `entry.*.view` was granted `site.settings.view` — a subject this vocabulary does not have, and a
     * future one it must not answer for. The direct match above cannot fail that way, because a stored grant
     * went through `validated()`; the wildcard is constructed at check time from the caller's string, so the
     * caller's string is what has to be checked.
     *
     * ⚠️ ONE ENCODING OF THE GRAMMAR, and that is the point of the refactor rather than a tidy-up. A second
     * copy of "is this a permission" inside `allows()` is a copy that drifts from the one grants are stored
     * under — and the drift would be silent in the permissive direction, which is the direction this project
     * refuses to be wrong in.
     *
     * ⚠️ AND `allows()` RETURNS FALSE RATHER THAN THROWING, while `validated()` throws. A grant being WRITTEN
     * is a decision somebody is making and a typo there must be loud; an authorization question is asked from
     * a Gate, where an exception is a 500 on a page that should have rendered a refusal. Same rule, two
     * answers, because the callers are asking different things.
     */
    private static function refusalFor(string $permission): ?string
    {
        $outside = self::outsideTheVocabulary($permission);

        if ($outside !== null) {
            return $outside;
        }

        $type = explode('.', $permission)[1];

        /*
         * ⚠️ THE TYPE SEGMENT IS CHECKED FOR SHAPE AND NOT FOR EXISTENCE, deliberately. A blueprint seeds
         * permissions alongside the entry type it creates, and the two arrive in one operation — so a grant
         * naming a type that does not exist yet is the normal case rather than a mistake. What cannot be
         * allowed is an empty or a decorated segment, because `entry..view` and `entry.art*cle.view` are
         * strings that would never match anything and would look like grants.
         */
        if ($type !== self::ANY_TYPE && preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            return "Refusing the permission [{$permission}]: [{$type}] is not an entry type handle or "
                .'['.self::ANY_TYPE.'] — ADR-033.';
        }

        return null;
    }

    /**
     * Why this string names nothing this vocabulary can ever grant, or null when it does.
     *
     * ⚠️ THE SUBJECT, THE ARITY AND THE ACTION — AND DELIBERATELY NOT THE TYPE'S SHAPE, which is the one
     * part of the grammar that belongs to WRITING a grant rather than to answering one. `entry.*.view`
     * legitimately covers every entry type, including a handle no form would have accepted: the panel's
     * `regex:/^[a-z][a-z0-9_]*$/` guards the create screen, and a seeder or a blueprint reaches the model
     * directly. Refusing `entry.blog-post.update` at CHECK time would therefore deny an owner an entry type
     * the installation really has — a wrong answer, from a rule whose job was to prevent one.
     *
     * What is left is what the wildcard construction actually got wrong: the subject and the action.
     */
    private static function outsideTheVocabulary(string $permission): ?string
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 3 || $parts[0] !== self::ENTRY) {
            return "Refusing the permission [{$permission}]: it must read ".self::ENTRY
                .'.{type_handle}.{action} — ADR-033.';
        }

        if (! in_array($parts[2], self::ACTIONS, true)) {
            return "Refusing the permission [{$permission}]: [{$parts[2]}] is not a registered action. "
                .'The registry is '.implode(', ', self::ACTIONS).' — ADR-033.';
        }

        return null;
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

        /*
         * ⚠️ A STRING THIS VOCABULARY DOES NOT DEFINE IS REFUSED BEFORE ANYTHING ELSE, which review found
         * the wildcard failing open on: `site.settings.view` is three segments ending in a registered
         * action, so the match below built `entry.*.view` and a wildcard holder was granted a subject that
         * does not exist. Checked before the owner bypass as well, so the answer to a question nobody can
         * ask is the same for everybody — an owner who is told yes about `site.settings.view` is an owner
         * whose caller now believes such a permission is real.
         */
        if (self::outsideTheVocabulary($permission) !== null) {
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
        [, , $action] = explode('.', $permission);

        return in_array(self::forEntryType(self::ANY_TYPE, $action), $held, true);
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
        // ⚠️ The CLASS, captured as a scalar, is what makes the membership check belong to the user who is
        // actually signed in — see `isMemberOfCurrentOrg()`. It is also a better memo key: two hosts' user
        // models would otherwise share one.
        $class = $user::class;

        return once(fn (): array => self::resolveHeld($userId, $orgId, $class));
    }

    /**
     * @return list<string>
     */
    private static function resolveHeld(int $userId, int $orgId, string $class): array
    {
        if (! self::isMemberOfCurrentOrg($userId, $class)) {
            return [];
        }

        return Role::query()
            // ⚠️ Explicit, though `OrgScope` filters the same column. It can only ever NARROW what the
            // scope already allows — a foreign org id here returns nothing rather than that org's roles —
            // and its real job is to keep `$orgId` from becoming a parameter nobody reads, which is what
            // the memo above is keyed on. See the note there.
            ->where('org_id', $orgId)
            ->whereIn('id', self::roleIdsFor($userId, $class))
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('permission')->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The entry type handles this user may view, or null when the answer is "any".
     *
     * ⚠️ A QUERY CONSTRAINT, BECAUSE A POLICY IS NOT ONE. Review found the gap: `EntryPolicy::view()` is
     * asked about a record somebody already has, and Eloquent never consults it while BUILDING a query — so
     * a relation picker that queried `Entry` directly happily returned titles of a type the user is refused
     * at the URL. An article editor could enumerate product names through the picker's search box.
     *
     * ⚠️ NULL IS "UNRESTRICTED" AND AN EMPTY ARRAY IS "NOTHING", which is the distinction a caller must not
     * collapse. `whereIn('type_handle', [])` matches no rows, which is the correct answer for a user who may
     * view nothing — and it is exactly the wrong answer for an owner, who may view everything and holds no
     * grants at all.
     *
     * ⚠️ DERIVED FROM THE GRANTS RATHER THAN FROM THE TYPE TABLE, so it costs no query of its own and cannot
     * drift from what `allows()` would answer for the same handle.
     *
     * @return list<string>|null
     */
    public static function viewableEntryTypes(?Authenticatable $user): ?array
    {
        if ($user === null || app(Context::class)->orgId() === null) {
            return [];
        }

        if (self::isOwner($user)) {
            return null;
        }

        $held = self::held($user);

        if (in_array(self::forEntryType(self::ANY_TYPE, 'view'), $held, true)) {
            return null;
        }

        $handles = [];

        foreach ($held as $permission) {
            $parts = explode('.', $permission);

            if (count($parts) === 3 && $parts[0] === self::ENTRY && $parts[2] === 'view' && $parts[1] !== self::ANY_TYPE) {
                $handles[] = $parts[1];
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * The signed-in user, asked of the panel when there is one and of the guard otherwise.
     *
     * ⚠️ THE BINDING CHECK IS NOT DEFENSIVE STYLE, it is the difference between a class the package suite
     * can reach and one it cannot. `Filament::auth()` resolves the `filament` binding, which core's tests
     * never register — ADR-024 puts the panel layer in the browser — so reaching for it inside anything the
     * PHP suite exercises fails with `Target class [filament] does not exist`. That happened twice on this
     * branch before it was worth a method.
     *
     * ⚠️ AND `auth()->user()` IS NOT A WEAKER ANSWER, because a panel is the only place these call sites run
     * in production, and outside one there is no panel guard to prefer. What it is NOT is a licence to skip
     * the user: every caller still treats null as "may view nothing".
     */
    public static function currentUser(): ?Authenticatable
    {
        return app()->bound('filament') ? Filament::auth()->user() : auth()->user();
    }

    /**
     * The user model this panel authenticates against, asked of the panel rather than of the config.
     *
     * ⚠️ THE PROVIDER NAMES IT, AND `config('auth.providers.users.model')` GUESSES. A panel may authenticate
     * through a provider that is not named `users` — the name is the host's to choose — and review found the
     * guess failing open in the membership check. The panel's own provider cannot be wrong about which model
     * it loads.
     *
     * ⚠️ IT IS WHAT `Role::assignee()` RESOLVES AN AUDIT TARGET WITH, so an assignment row names the person
     * whose authority changed rather than an unrelated model with the same id — review found that hard-coded
     * to `users` after the membership check had already been fixed the same way.
     *
     * ⚠️ AND KNOWING IT IS WHAT LETS CORE OFFER ROLE ASSIGNMENT AT ALL, which is a decision #84 reversed on
     * this evidence: the assignment UI was going to live in the skeleton because core must not own a user
     * MODEL. It still does not own one — it asks the panel — and putting the screen here avoids opening an
     * extension point in core's navigation before the extension API exists (Standing Principle #1).
     *
     * @return class-string<Model>|null
     */
    public static function userModel(): ?string
    {
        /*
         * ⚠️ THE PANEL HANDLING THE REQUEST, NOT THE DEFAULT ONE — review found the second version still
         * asking for the default. A host may run several panels, and a non-default one may authenticate
         * through another provider entirely; asking the default then names a model from somebody else's
         * panel, so an audit row records an unrelated row with the same id.
         */
        if (app()->bound('filament')) {
            $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
            $provider = $panel->auth()->getProvider();

            if (method_exists($provider, 'getModel')) {
                $model = $provider->getModel();

                if (is_string($model) && is_subclass_of($model, Model::class)) {
                    return $model;
                }
            }
        }

        /*
         * ⚠️ THE CONFIGURED PROVIDER IS THE PANEL-LESS FALLBACK, AND IT LIVES HERE SO THERE IS ONE ANSWER.
         * A seeder, a console command and the package test suite have no panel at all, and two callers
         * needed this — the audit target and the lock-out guard's membership test — so resolving it twice
         * was two places for the rule to drift. What it is NOT is a licence to skip the user: every caller
         * still treats null as "cannot tell", and `isMemberOfCurrentOrg()` deliberately asks the
         * AUTHENTICATED instance's own class instead, because there a wrong answer fails open.
         */
        $configured = config('auth.providers.users.model');

        return is_string($configured) && class_exists($configured) && is_subclass_of($configured, Model::class)
            ? $configured
            : null;
    }

    /**
     * Narrow an entry query to the types this user may view.
     *
     * ⚠️ ONE PREDICATE, EVERY PLACE ENTRIES ARE LISTED, and the relation picker's own docblock already
     * records why: a constraint written three times is two places for it to be forgotten, which is exactly
     * how that picker ended up applying its target filter to the search and not to the label resolvers.
     * Review found the permission version of the same shape — the picker, the relations table and the
     * attach dialog each query `Entry`, and a policy governs none of them.
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public static function constrainToViewable(Builder $query, ?Authenticatable $user): Builder
    {
        $viewable = self::viewableEntryTypes($user);

        return $viewable === null ? $query : $query->whereIn('type_handle', $viewable);
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

        // The same key discipline as `held()`: scalars only, every one used by the body it calls.
        $class = $user::class;

        return once(fn (): bool => self::resolveIsOwner($userId, $orgId, $class));
    }

    private static function resolveIsOwner(int $userId, int $orgId, string $class): bool
    {
        if (! self::isMemberOfCurrentOrg($userId, $class)) {
            return false;
        }

        return Role::query()
            ->where('org_id', $orgId)
            ->whereIn('id', self::roleIdsFor($userId, $class))
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
     * ⚠️ THE AUTHENTICATED USER'S OWN CLASS, NOT `config('auth.providers.users.model')`, and review found
     * what the config lookup cost. A panel may authenticate through a provider that is not named `users` —
     * the name is the host's to choose — and then this read an unrelated model, or no model at all and took
     * the permissive fallback. A `role_user` row would have conferred grants on somebody who is not a member
     * of the org. Asking the instance cannot be wrong about which model it is.
     *
     * ⚠️ AND IT FAILS CLOSED WHEN IT CANNOT ASK. An `Authenticatable` that is not an Eloquent model has no
     * scope to apply, and the earlier version returned true for it — "no check" reading as "allowed", which
     * is the wrong direction for the one question with no framework safety net. Such a host resolves no
     * permissions at all, loudly, rather than silently resolving everything.
     *
     * The membership question itself still has an owner: `#[OrgScopedThroughPivot]` on the host's user
     * model, enforced by `OrgMembershipScope`. Naming its pivot table here would be a second copy of a name
     * the host configures, so this asks the model's own scoped query instead.
     *
     * @param  class-string  $class
     */
    private static function isMemberOfCurrentOrg(int $userId, string $class): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        return $class::query()->whereKey($userId)->exists();
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
     * ⚠️ AND A NUMERIC ID IS NOT AN IDENTITY, which review found: the memo and the membership check learned to
     * carry the authenticated model's class, and this still matched on `user_id` alone. A host running two
     * panels through two providers has two user models on two tables with two independent sequences — so
     * `role_user` row (role 7, user 1) is about the FIRST model's user 1, and the second model's user 1 was
     * being handed the same roles the moment they belonged to the current org. That is a grant resolving for
     * the wrong person, which is the one failure this layer exists to prevent.
     *
     * The table `role_user.user_id` REFERENCES is what says whose ids these are, so the class is checked
     * against it. Asked of the schema rather than of a config value, because the skeleton's migration is what
     * decides — `constrained()` on `users` — and a config key would be a second place for the answer to live.
     *
     * @param  class-string  $class
     * @return list<int>
     */
    private static function roleIdsFor(int $userId, string $class): array
    {
        if (! self::assignmentsAreAbout($class)) {
            return [];
        }

        return array_map('intval', DB::table('role_user')->where('user_id', $userId)->pluck('role_id')->all());
    }

    /**
     * Do the rows in `role_user` name instances of THIS model?
     *
     * ⚠️ WHAT CANNOT BE DISCOVERED IS ALLOWED, and that is a deliberate line rather than an oversight. A host
     * whose `role_user` declares no foreign key — the skeleton's does, with `constrained()` — gives nothing
     * to compare, and refusing every assignment on an installation this cannot inspect would break RBAC
     * outright for a schema that is merely undocumented rather than wrong. The narrower failure mode stays
     * open there and is stated in ADR-033 rather than implied here.
     *
     * ⚠️ THE TABLE NAME ONLY, not the schema qualifier Postgres also reports. An Eloquent model carries no
     * schema of its own, so there is nothing to compare it against; two schemas each holding a `users` table
     * is a shape this does not distinguish.
     *
     * ⚠️ AND THE CONNECTION, WHICH THE NAME ALONE DID NOT SETTLE — review found the next layer of the same
     * mistake. A host may authenticate against an identity database on its own connection whose table is
     * also called `users`, while `role_user` and the FK read here belong to the default one: the names then
     * agree, membership is checked on the identity database, and `roleIdsFor()` reads the default — so an
     * overlapping numeric id collects the DEFAULT user's roles. The assignments live wherever `role_user`
     * lives, which is the default connection, so a model reading from anywhere else is not what they are
     * about.
     *
     * @param  class-string  $class
     */
    public static function assignmentsAreAbout(string $class): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        /** @var Model $prototype */
        $prototype = new $class;

        // `getConnectionName()` is null for a model that takes whatever the default is, which is the
        // connection `role_user` and the schema read below both use.
        if (($prototype->getConnectionName() ?? DB::getDefaultConnection()) !== DB::getDefaultConnection()) {
            return false;
        }

        $referenced = self::assignmentTable();

        return $referenced === null || $referenced === $prototype->getTable();
    }

    /**
     * The table `role_user.user_id` points at, read from the schema once.
     *
     * Memoised through `once()` like the rest of this class: it is a fact about the installation rather than
     * about a request, and `forget()` flushing it costs one introspection after a role write.
     */
    private static function assignmentTable(): ?string
    {
        return once(function (): ?string {
            foreach (Schema::getForeignKeys('role_user') as $key) {
                if (! in_array('user_id', (array) ($key['columns'] ?? []), true)) {
                    continue;
                }

                $table = $key['foreign_table'] ?? null;

                return is_string($table) ? $table : null;
            }

            return null;
        });
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
