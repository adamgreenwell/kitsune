<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Tenancy\Context;

/**
 * Per-type authorization for one `Entry` model — ADR-033, and Phase 4's last unchecked line.
 *
 * ⚠️ ONE MODEL MEANS ONE POLICY, WHICH IS WHY THIS IS NOT FREE. ADR-010 makes `Entry` a single model with a
 * type discriminator, so Laravel would resolve one policy for every entry type in the installation.
 * `architecture.md` §4 settles the shape: the policy resolves against `type_handle`, and the permission
 * names it — `entry.{type_handle}.{action}`.
 *
 * ⚠️ AND THE TYPE COMES FROM TWO PLACES, NEITHER OPTIONAL. With a record in hand it is the record's own
 * `type_handle` — never the request's, because a record reached through a URL for another type is exactly
 * the confusion an attacker would arrange. With no record — `viewAny`, `create` — it is the type
 * `IdentifyEntryType` bound into the container, which has already been validated as existing, belonging to
 * this org, and enabled for this site.
 *
 * ⚠️ NO TYPE AT ALL MEANS NO. A policy that fell back to "allowed" when it could not tell which type it was
 * being asked about would be an open door on every path that forgot to establish one.
 *
 * ⚠️ AND THE TYPE IS NOT THE ONLY QUESTION — THE RECORD HAS TO BE IN THIS SCOPE, which review found this
 * asking nowhere. `SiteScope` constrains the query that LOADS an entry and says nothing about the object
 * afterwards; an instance update or delete writes by primary key without reapplying it. So in a long-lived
 * worker or a multi-site command, an entry loaded under site A survived a `Context` switch to B and a user
 * in B holding the same `entry.{handle}.update` grant authorised the write — B's grant spending itself on
 * A's row, with the audit attributed to B. `Role` carries the same guard for the same reason
 * (`refuseIfNotCurrentOrg()`): a check on the query is not a check on the instance.
 */
class EntryPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->allowsOnCurrentType($user, 'view');
    }

    public function view(Authenticatable $user, Entry $entry): bool
    {
        return $this->allowsOn($user, $entry, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->allowsOnCurrentType($user, 'create');
    }

    public function update(Authenticatable $user, Entry $entry): bool
    {
        return $this->allowsOn($user, $entry, 'update');
    }

    public function delete(Authenticatable $user, Entry $entry): bool
    {
        return $this->allowsOn($user, $entry, 'delete');
    }

    /**
     * ⚠️ RESTORE AND FORCE-DELETE RESOLVE AGAINST `delete`, and that is a decision rather than a shortcut.
     * The vocabulary `architecture.md` publishes has five actions and neither of these is one of them, so
     * the choice is which existing action they belong to. Both are operations on a deleted row, so the
     * authority that removed it is the authority that governs it — and mapping them to `update` would let
     * an editor who may not delete an entry resurrect one, or erase it permanently.
     */
    public function restore(Authenticatable $user, Entry $entry): bool
    {
        return $this->allowsOn($user, $entry, 'delete');
    }

    public function forceDelete(Authenticatable $user, Entry $entry): bool
    {
        return $this->allowsOn($user, $entry, 'delete');
    }

    /**
     * The BULK abilities, which Filament asks instead of the singular ones — review found them missing.
     *
     * ⚠️ A MISSING POLICY METHOD IS A DENIAL, INCLUDING FOR AN OWNER. Filament's own note says bulk actions
     * check `deleteAny`, `forceDeleteAny` and `restoreAny` "for performance" rather than authorizing each
     * record — so `DeleteBulkAction` asked for an ability this policy did not define, Laravel's Gate found no
     * callback, and the toolbar was refused to everybody. And because ADR-033 deliberately keeps the owner
     * bypass inside these methods rather than in `Gate::before`, there was nothing above to rescue it: the
     * narrower blast radius is bought with exactly this, that every ability has to be spelled out.
     *
     * ⚠️ ALL THREE, THOUGH ONLY ONE IS REACHABLE TODAY. The toolbar carries `DeleteBulkAction` alone, but
     * adding a trash filter with restore actions is one line in a Resource — and the failure mode is a
     * refusal for everybody, which reads as a broken button rather than as a missing method. Defining the
     * family closes the trap once instead of leaving it for whoever adds the second action.
     *
     * They resolve against the CURRENT type rather than a record, because there is no record: the whole
     * point of the bulk ability is that it is asked once for the selection.
     */
    public function deleteAny(Authenticatable $user): bool
    {
        return $this->allowsOnCurrentType($user, 'delete');
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return $this->allowsOnCurrentType($user, 'delete');
    }

    public function restoreAny(Authenticatable $user): bool
    {
        return $this->allowsOnCurrentType($user, 'delete');
    }

    /**
     * May this user move an entry of this type into a published state?
     *
     * ⚠️ NOT A LARAVEL POLICY CONVENTION, and it is enforced anyway — `EntryResource` withholds the
     * `published` option from the status control and refuses the value in validation. AGENTS.md #14: a
     * permission that nothing consults is a published constraint that is not enforceable, which is worse
     * than an absent one because a reader believes it.
     */
    public function publish(Authenticatable $user, ?Entry $entry = null): bool
    {
        return $entry instanceof Entry
            ? $this->allowsOn($user, $entry, 'publish')
            : $this->allowsOnCurrentType($user, 'publish');
    }

    private function allowsOn(Authenticatable $user, Entry $entry, string $action): bool
    {
        /*
         * ⚠️ THE TYPE COMES FROM THE STORED ROW TOO, AND FIXING ONLY THE SCOPE WAS HALF A FIX — review found
         * the other half. `type_handle` is a public attribute, so a caller holding `entry.product.update` could
         * load an ARTICLE, set the attribute to `product`, and pass this check: an instance delete then removed
         * the stored article, and a save re-stamps the handle from `entry_type_id` only AFTER authorization.
         * The scope read was already going to the database; the handle comes back in the same query.
         *
         * ⚠️ An empty handle is refused rather than passed through. `type_handle` is denormalised for
         * routing and re-stamped on save, so it is non-empty for anything that went through the model —
         * and a row written around it would otherwise resolve `entry..view`, a permission string nobody
         * can hold but which no longer looks like a missing type.
         */
        if (! $entry->exists) {
            /*
             * An unsaved instance names no row, so the attribute is all there is — and it is the caller's own
             * object rather than somebody else's record. `EnforcesScope` stamps and guards the keys on insert.
             */
            $handle = (string) $entry->type_handle;

            return $handle !== ''
                && Permissions::allows($user, Permissions::forEntryType($handle, $action));
        }

        $handle = $this->storedTypeInScope($entry);

        return $handle !== null && $handle !== ''
            && Permissions::allows($user, Permissions::forEntryType($handle, $action));
    }

    /**
     * Would the current scope's own query return this row?
     *
     * ⚠️ THE SAME CLAUSE `SiteScope` APPLIES, in PHP, and the duplication is deliberate and pinned. The
     * scope's version is SQL inside a `WHERE`, so it cannot be asked about an object already in memory —
     * which is exactly the question here. `EntryPolicyScopeAgreesWithTheScopeTest` asserts the two answer
     * identically for every shape, so the copy cannot drift into a wider or a narrower rule.
     *
     * ⚠️ `site_id IS NULL` IS ORG-SHARED AND STILL FENCED BY ORG (ADR-021). One media library serves eight
     * brands, so a shared row is legitimately reachable from every site in its org — and a bare null check
     * would make it reachable from every org's, which is the leak the scope's own comment warns about.
     *
     * ⚠️ NO CONTEXT MEANS NO, matching the scope: with no site established `SiteScope` adds `1 = 0` and the
     * query returns nothing, so the policy must not answer yes about a row that query could not produce.
     * `Permissions::allows()` already refuses when there is no ORG, and this adds the site half.
     *
     * ⚠️ ONLY FOR A ROW THAT EXISTS. An unsaved instance has no stored identity and no row to mutate — its
     * scope keys are the INSERT's business, and `EnforcesScope` stamps and guards them there. Asking this of
     * a new `Entry` would refuse `$entry->fill(...)`-shaped authorization questions that name nothing yet.
     */
    private function storedTypeInScope(Entry $entry): ?string
    {
        $context = app(Context::class);

        if (! $context->hasSite()) {
            return null;
        }

        /*
         * ⚠️ THE ATTRIBUTES WERE THE ORACLE AND THEY ARE A PENDING EDIT, which review found: set a loaded org
         * A entry's `site_id` and `org_id` to the current ones and this comparison accepted them, so B's grant
         * authorised the write — and `EnforcesScope` accepts a destination that matches the context too, so
         * the transfer landed. The docblock above already said the scoped query was the oracle; it was
         * describing an intention rather than the code.
         *
         * ⚠️ AND THE KEY THE WRITE USES IS THE ORIGINAL ONE, so a changed `id` attribute would have the check
         * ask about one row and the update touch another — the same shape found on `Role`. A record whose key
         * has been edited in memory is refused rather than reconciled.
         *
         * ⚠️ IT COSTS ONE INDEXED `EXISTS` PER AUTHORIZATION, WHICH WAS MEASURED RATHER THAN ASSUMED. The
         * admin list asks this per row, so the cost is per page rather than per request; `kitsune:benchmark-admin`
         * puts it inside the ADR-027 floor budget, and the numbers are in the PR discussion. Trading a
         * measured fraction of a millisecond for a guard that cannot be talked out of its answer is the trade
         * this project keeps making.
         *
         * ⚠️ `withTrashed()`, because `restore()` and `forceDelete()` are asked about rows the default scope
         * hides — without it the policy would refuse exactly the two abilities that only ever concern a
         * deleted row, which is the kind of fix that reads as correct and breaks the trash view.
         */
        $stored = $entry->getKeyForAuthorization();

        if ((string) $stored !== (string) $entry->getKey()) {
            return null;
        }

        $site = $context->siteId();
        $org = $context->orgId();

        /*
         * ⚠️ THE TYPE THIS INSTANCE WAS LOADED WITH IS PART OF THE KEY, AND PART OF THE ANSWER — review found
         * the memo outliving a reload. Keyed on the row and the scope alone, a request that checked an entry
         * while it was an article, saw it retyped, and then RELOADED it got the memoised article handle back:
         * the fresh instance's originals match the retyped row, so the write guard has nothing to refuse, and
         * an article grant was spent on a restricted type.
         *
         * It is used by the body rather than merely mixed into the key — AGENTS.md invariant 13 — and what it
         * is used FOR is the same question the write guard asks: an instance whose loaded type no longer
         * matches the stored row is stale, and a stale instance is refused.
         */
        $loadedType = $entry->getRawOriginal('entry_type_id');

        /*
         * ⚠️ MEMOISED PER ROW, BECAUSE THE FIRST VERSION COST +50 QUERIES A PAGE. Filament asks several
         * abilities of every row it renders, so an unconditional read was one query per CHECK. Measured with
         * `kitsune:benchmark-admin` at 100k entries, `entry list, page 1`:
         *
         *   attributes only (forgeable)   17 queries   63.7 ms
         *   stored row, unmemoised        67 queries   77.3 ms
         *   stored row, memoised          17 queries   64.8 ms
         *
         * and measured on a real list request with the query shapes grouped, the memoised version makes ONE
         * `select site_id, org_id from entries where id = ?` per distinct row rendered. `EntryPolicyScopeCostTest`
         * pins that ratio: thirty checks across ten fresh policy instances on ten rows cost ten reads.
         *
         * ⚠️ THE KEY CARRIES THE SCOPE, NOT ONLY THE ROW — AGENTS.md invariant 13, which this project learned
         * from a memo keyed on a `Site` object. A process that changes orgs — a console command, a queue
         * worker — must not be answered from another scope's memo, so both ids are captured AND used by the
         * body below rather than passed and ignored.
         */
        return self::scopeAllows($stored, $site, $org, $loadedType);
    }

    /**
     * The row's own type handle, or null when the row is not one this scope may see.
     *
     * One read answers both questions, which is why they are one method: the scope keys and the handle come
     * out of the same `first()` and neither can be the caller's pending edit.
     */

    /**
     * Is the row this key names inside that site and org, as the DATABASE holds it?
     *
     * ⚠️ THE STORED KEYS ARE READ AND COMPARED HERE, which is the difference from the version review found:
     * the scope keys come from the row rather than from the object, so a pending edit to `site_id` or
     * `org_id` — or a `syncOriginal()` that makes one look clean — changes nothing. `withoutGlobalScopes()`
     * is what lets the comparison be explicit rather than implied by a `WHERE` this method cannot see, and
     * `EntryPolicyTest` pins the result against `Entry::query()->whereKey(...)->exists()` shape by shape, so
     * the copy cannot drift from the scope it stands in for.
     */
    /**
     * ⚠️ THE MEMO HAS TO BE ENTERED FROM A STATIC FRAME, and this method exists for no other reason. Laravel's
     * `once()` builds its key from the CALLING frame — including that frame's `$this` — and
     * `Gate::resolvePolicy()` constructs a new policy for every single check, so memoising from the instance
     * method keyed every call differently. Measured at the policy: five abilities on one entry cost 5 reads
     * from five instances and 1 from one; on the admin list that was 50 reads a page rather than 10.
     *
     * A static frame has no object to key on, so the key is the call site plus the three arguments — which is
     * also what makes the scope part of it, per invariant 13.
     */
    private static function scopeAllows(mixed $key, ?int $site, ?int $org, mixed $loadedType): ?string
    {
        return once(static fn (): ?string => self::storedRowIsInScope($key, $site, $org, $loadedType));
    }

    private static function storedRowIsInScope(mixed $key, ?int $site, ?int $org, mixed $loadedType): ?string
    {
        $row = Entry::withTrashed()
            ->withoutGlobalScopes()
            ->whereKey($key)
            ->first(['site_id', 'org_id', 'type_handle', 'entry_type_id']);

        if ($row === null) {
            return null;
        }

        /*
         * ⚠️ AN INSTANCE WHOSE LOADED TYPE NO LONGER MATCHES THE ROW IS STALE, and the policy says no to a
         * stale instance rather than answering about a row it is not holding. It is the same sentence
         * `Entry::refuseIfTheRowMovedUnderneath()` enforces at the write, asked one layer earlier — and it is
         * what makes the loaded type load-bearing in the memo key rather than decoration.
         */
        if ((string) $row->entry_type_id !== (string) $loadedType) {
            return null;
        }

        $rowSite = $row->site_id === null ? null : (int) $row->site_id;

        $inScope = $rowSite === null
            ? (int) $row->org_id === $org
            : $rowSite === $site;

        return $inScope ? (string) $row->type_handle : null;
    }

    private function allowsOnCurrentType(Authenticatable $user, string $action): bool
    {
        if (! app()->bound(EntryType::class)) {
            return false;
        }

        $handle = app(EntryType::class)->handle;

        return $handle !== ''
            && Permissions::allows($user, Permissions::forEntryType($handle, $action));
    }
}
