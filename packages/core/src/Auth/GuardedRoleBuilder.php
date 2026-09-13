<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * The authority guarantees on `Role`, on the paths a model event cannot see — ADR-033.
 *
 * ⚠️ REVIEW FOUND THE HOLE BY NAME: *"`Role::query()->update(['is_owner' => …])` dispatches no `saved`
 * event, so users gain or lose the owner bypass without any per-assignee audit rows and previously
 * memoized owner answers remain stale."* Every guarantee this model makes about the owner flag lived in a
 * lifecycle hook, which is to say it was true of the row-at-a-time path and of nothing else.
 *
 * It is the same lesson `GuardedStorageBuilder` records for `FieldStorage` and `AuditedBuilder` for
 * `Entry`, and it is the third time: a guard belongs where the write is, and a model event is not where
 * the write is.
 *
 * ⚠️ THE FLAG IS HOW AN INSTANCE SAVE IS TOLD FROM A BULK ONE, because `Model::performUpdate()` writes
 * through this builder too — so refusing every bulk-shaped write would refuse `$role->save()` as well. It
 * is set by the `saving` guard, which only a model event reaches; a bulk update dispatches nothing, so it
 * can never be set and the write is refused.
 *
 * ⚠️ IN `Auth/` RATHER THAN `Models/`, following `Audit/AuditedBuilder` and `Schema/GuardedStorageBuilder`:
 * a guarded builder lives beside the concern it guards, not beside the model. The declaration sweep also
 * reads `Models/` and asks every class there for a tenancy attribute, which a builder has no business
 * carrying — it caught this on the first run.
 *
 * @extends ScopedBuilder<Role>
 */
class GuardedRoleBuilder extends ScopedBuilder
{
    /**
     * Columns whose guarantees are PER ROW, so a bulk write cannot honour them.
     *
     * `is_owner` is the widest grant in the system and its audit is one row per HOLDER — a bulk update
     * sees one value and any number of roles, each with its own holders, so there is no correct set of
     * audit rows to write. `org_id` is the scope key, which `EnforcesScope` guards per row for the same
     * reason.
     */
    private const PER_ROW = ['is_owner', 'org_id'];

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        if ($this->getModel()->authorityGuarded) {
            return parent::update($values);
        }

        foreach (array_keys($values) as $column) {
            // Qualified names arrive from a join, so compare the column rather than the prefix.
            if (in_array(last(explode('.', (string) $column)), self::PER_ROW, true)) {
                throw new RuntimeException(sprintf(
                    'Refusing a bulk write to `%s` on roles: the owner flag is audited one row per HOLDER '
                    .'and the scope key is guarded per row, and these paths dispatch nothing — so the '
                    .'authority would change with no trail of who gained or lost it (ADR-020, ADR-033). '
                    .'Load the role and save it.',
                    (string) last(explode('.', (string) $column)),
                ));
            }
        }

        return parent::update($values);
    }

    /**
     * ⚠️ BULK DELETE IS REFUSED OUTRIGHT, because deleting a role revokes it from every holder — the
     * database cascades `role_user` — and the audit for that is again per holder. `Role::delete()` records
     * them inside its own transaction; a `delete()` on the builder dispatches nothing and cannot.
     *
     * An instance delete arrives here as well, so the same flag separates them.
     *
     * @return int
     */
    public function delete()
    {
        if (! $this->getModel()->authorityGuarded) {
            throw new RuntimeException(
                'Refusing a bulk delete of roles: deleting one revokes it from every holder, and that is '
                .'audited per holder by `Role::delete()`. These paths dispatch nothing, so the bypass '
                .'would disappear with no record of whose it was (ADR-033).'
            );
        }

        return parent::delete();
    }
}
