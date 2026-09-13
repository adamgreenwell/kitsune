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
         * ⚠️ An empty handle is refused rather than passed through. `type_handle` is denormalised for
         * routing and re-stamped on save, so it is non-empty for anything that went through the model —
         * and a row written around it would otherwise resolve `entry..view`, a permission string nobody
         * can hold but which no longer looks like a missing type.
         */
        $handle = $entry->type_handle;

        return $handle !== ''
            && Permissions::allows($user, Permissions::forEntryType($handle, $action));
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
