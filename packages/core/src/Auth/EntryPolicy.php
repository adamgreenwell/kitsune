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
        if ($entry->exists && ! $this->withinCurrentScope($entry)) {
            return false;
        }

        /*
         * ⚠️ An empty handle is refused rather than passed through. `type_handle` is denormalised for
         * routing and re-stamped on save, so it is non-empty for anything that went through the model —
         * and a row written around it would otherwise resolve `entry..view`, a permission string nobody
         * can hold but which no longer looks like a missing type.
         */
        $handle = $entry->type_handle;

        return $handle !== ''
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
    private function withinCurrentScope(Entry $entry): bool
    {
        $context = app(Context::class);

        if (! $context->hasSite()) {
            return false;
        }

        $site = $entry->site_id === null ? null : (int) $entry->site_id;

        return $site === null
            ? (int) $entry->org_id === $context->orgId()
            : $site === $context->siteId();
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
