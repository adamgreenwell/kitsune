<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Support\Collection;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * `role_permissions` has no public write surface — ADR-033, and review found that it had one.
 *
 * ⚠️ `#[Unscoped]` IS SAFE FOR READS BECAUSE EVERY READ GOES THROUGH `Role`, AND THAT ARGUMENT SAYS NOTHING
 * ABOUT WRITES. The model's own docblock told a reviewer to treat a bare `RolePermission::query()` in a read
 * path as a defect — and then the ordinary published model API could do this:
 *
 *   RolePermission::query()->delete();                                  every org's grants, gone
 *   RolePermission::create(['role_id' => $rivalsRole, 'permission' => …]);  a grant on another org's role
 *
 * No scope narrows either one, because there is no `org_id` on this table to narrow — that absence is
 * deliberate, so there is no copy of the role's org to drift from. What it costs is that the constraint has
 * to be expressed as "the only door is `Role`" rather than as a column.
 *
 * ⚠️ SO THE WINDOW IS THE DISCRIMINATOR, NOT A PER-INSTANCE FLAG, and the difference is forced by
 * `firstOrCreate()`. `Role::grant()` reaches this table through a relation, which builds its own instance —
 * so a flag armed on the model in hand never reaches the object that gets saved. `ScopeWrites` already
 * solves the same shape for the tenancy guards: a narrow, explicit window opened by the one caller that is
 * allowed to write, and closed in a `finally` so an exception cannot leave it open.
 *
 * ⚠️ AND THE WINDOW IS OPENED INSIDE `Role`, WHICH REVIEW HAD TO POINT OUT TWICE OVER. A first version put a
 * public opener on `RolePermission` — which made the capability public, so a caller could hold the window
 * open around a write of their own and this class was decoration. `Role` arms a private static inline in
 * `grant()` and `revoke()`; the reader below is all that is exposed, and a reader cannot open anything.
 *
 * What that window does NOT claim is that the write is correct — `Role::grant()` validates the permission,
 * refuses a role outside the current org, audits, and flushes the memo. This only makes it the sole path.
 *
 * @extends ScopedBuilder<RolePermission>
 */
class GuardedGrantBuilder extends ScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->refuseUnlessThroughRole('update()');

        return parent::update($values);
    }

    /** @return int */
    public function delete()
    {
        $this->refuseUnlessThroughRole('delete()');

        return parent::delete();
    }

    /**
     * ⚠️ `forceDelete()` as well, because Eloquent sends it to the query builder rather than through
     * `delete()` — the same omission this project has now found on three builders.
     */
    public function forceDelete()
    {
        $this->refuseUnlessThroughRole('forceDelete()');

        return parent::forceDelete();
    }

    /** @param  array<string, mixed>  $values */
    public function insert(array $values): bool
    {
        $this->refuseUnlessThroughRole('insert()');

        return parent::insert($values);
    }

    /**
     * ⚠️ `insertGetId()` IS `performInsert()`'S PATH for an incrementing model, so it is the one a legitimate
     * `Role::grant()` takes — and it is publicly callable, so it needs the window like everything else.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->refuseUnlessThroughRole('insertGetId()');

        return parent::insertGetId($values, $sequence);
    }

    /**
     * ⚠️ AND THE INSERT-OR-IGNORE FAMILY, WHICH THIS CLASS CLAIMED WAS ALREADY COVERED AND WAS NOT — review
     * checked the claim. `ScopedBuilder` refuses them through `refuseBulkCreate()`, which returns early for a
     * model that is not `RequiresModelSave`, and `guardEveryInsertedRow()` inspects SCOPE KEYS, of which an
     * `#[Unscoped]` table has none. So both guards stood aside and every one of these was a working door:
     * `RolePermission::query()->insertOrIgnore([...])` attached a grant to any role on the installation.
     *
     * A docblock that says "already handled one layer down" is a claim about somebody else's code, and this
     * is the second time in this project that one has been wrong. The methods are listed here instead.
     *
     * @param  array<string, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $this->refuseUnlessThroughRole('insertOrIgnore()');

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseUnlessThroughRole('insertUsing()');

        return parent::insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseUnlessThroughRole('insertOrIgnoreUsing()');

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $returning
     * @param  array<int, string>|string|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        $this->refuseUnlessThroughRole('insertOrIgnoreReturning()');

        return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * ⚠️ `upsert()`, `updateOrInsert()` and `truncate()` ARE the ones `ScopedBuilder` refuses for every model
     * — outright, with no window to open — so they are absent here on purpose rather than by assumption.
     * `RolePermissionWriteDoorsTest` walks the whole public write surface of the model and fails if a method
     * that changes rows is reachable, which is what keeps this list honest as Laravel adds to it.
     */

    /**
     * The arithmetic family reaches the query builder without passing `update()` — the hole review found on
     * `GuardedRoleBuilder`, closed here at the same seam rather than one defect later.
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardArithmetic(array $values): void
    {
        $this->refuseUnlessThroughRole('an arithmetic write');

        parent::guardArithmetic($values);
    }

    private function refuseUnlessThroughRole(string $method): void
    {
        if (Role::grantsAreBeingWritten()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing %s on role_permissions: this table carries no org of its own, so nothing here can '
            .'narrow a write to one customer — a single call can revoke every org\'s grants or attach one '
            .'to another org\'s role, with no validation, no audit row and no cache invalidation '
            .'(ADR-021, ADR-033). Grants change through %s::grant() and revoke().',
            $method,
            Role::class,
        ));
    }
}
