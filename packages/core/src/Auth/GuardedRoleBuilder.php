<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Concerns\ResolvesWrittenColumns;
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
 * ⚠️ THE PROOF IS HOW AN INSTANCE SAVE IS TOLD FROM A BULK ONE, because `Model::performUpdate()` writes
 * through this builder too — so refusing every bulk-shaped write would refuse `$role->save()` as well. It is
 * two private facts on the model: the `saving`/`deleting` listeners record that this instance's guards ran,
 * and `performUpdate()`/`performDeleteOnModel()` record the write they ran for. A bulk update dispatches
 * nothing and is inside nobody's save, so it can present neither.
 *
 * ⚠️ IT USED TO BE A PUBLIC BOOLEAN, WHICH REVIEW CORRECTLY CALLED A FORGERY: `Builder::getModel()` is
 * public, so `$q = Role::query(); $q->getModel()->authorityGuarded = true; $q->update([…])` armed the proof
 * from outside and promoted every matching role. `RequiresModelSave`'s docblock records the same attack on
 * `exists` and `getIncrementing()` — measured, twice — and the answer there is the answer here: ask for
 * something only Eloquent's own save path can be inside.
 *
 * ⚠️ AND `update()` IS ONE DOOR OF FOUR. Review found three more, each forwarding past this class to the
 * query builder: `increment()`, `decrement()` and their `…Each()` plurals carry an `$extra` map of ordinary
 * assignments, and `forceDelete()` is sent straight down by Eloquent rather than through `delete()`. Every
 * guarantee below was true of one method name at a time until they were covered too — which is this
 * docblock's own lesson arriving a second time, one API call along.
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
    /*
     * Its own private copy of the refusals, because `ScopedBuilder` keeps its copy private rather than hand every
     * plugin subclass a protected name. `bareColumn()` comes in protected, exactly as the parent declares it.
     */
    use ResolvesWrittenColumns {
        refuseAmbiguousColumns as private;
        refuseMisnamedGuardedColumn as private;
    }

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
        /*
         * ⚠️ A PROVEN SAVE WRITES THE OWNER FLAG UNDER ITS OWN NAME OR NOT AT ALL. The lifecycle hooks that audit a
         * change of owner ask about `is_owner` by name, so `$role->update(['IS_OWNER' => true])` left that
         * attribute clean, passed every hook, armed the proof — and SQLite, MySQL and MariaDB wrote the second
         * attribute into the same column. Measured: the role became an owner with no per-holder audit.
         */
        if ($this->getModel()->authorityProven($this)) {
            foreach (array_keys($values) as $written) {
                $column = $this->bareColumn((string) $written);

                if (in_array($column, self::PER_ROW, true)) {
                    $this->refuseMisnamedGuardedColumn((string) $written, $column);
                }
            }
        }

        if (! $this->getModel()->authorityProven($this)) {
            $this->refusePerRowAuthority($values, 'a bulk write');

            /*
             * ⚠️ AND THE ROW QUESTION, WHICH ONLY THE COLUMNS WERE ASKING — review found the gap in the
             * boundary the round before drew. `saveQuietly()` and `updateQuietly()` suppress the `saving`
             * listener that asks whether this row belongs to the current org, and what is left here refused
             * only `is_owner` and `org_id`: so a quiet `name` or `handle` change on ANOTHER org's role went
             * through, written by primary key.
             *
             * Asked of the model only when it is an instance write that lost its proof — a genuine bulk
             * update reaches this builder with a prototype that does not exist, and the global scope is what
             * narrows that one to the current org.
             *
             * ⚠️ `exists` ALONE, BECAUSE `getKey()` IS THE ATTRIBUTE AND THE WRITE USES THE ORIGINAL — review
             * found the gap that condition left. Nulling `id` in memory after an org switch made this false
             * while `saveQuietly()` still updated the row the instance was loaded from, so a quiet `name` or
             * `handle` change on org A's role went through under org B: the guard was skipped on exactly the
             * instance that had been tampered with. `refuseIfNotCurrentOrg()` already refuses an edited or
             * absent key by comparing `getKeyForSaveQuery()` with the attribute, so the condition's only job
             * is to tell an instance write from a bulk one — which `exists` answers on its own.
             */
            $model = $this->getModel();

            if ($model->exists) {
                $model->refuseIfNotCurrentOrg('a save with no lifecycle guards');
            }
        }

        return parent::update($values);
    }

    /**
     * Refuse a write to a column whose guarantees are per row.
     *
     * ⚠️ EXTRACTED BECAUSE `update()` WAS NOT THE ONLY DOOR, which review found: the arithmetic family
     * forwards straight to the query builder, so `Role::query()->increment('id', 0, ['is_owner' => true])`
     * promoted every matching role — no per-holder audit, no lifecycle invalidation, no instance org check,
     * and `Permissions`' memo left answering from before the write. Laravel's `$extra` map is a set of
     * ordinary assignments; the method it arrives through does not change what it does.
     *
     * @param  array<string, mixed>  $values
     */
    private function refusePerRowAuthority(array $values, string $shape): void
    {
        foreach (array_keys($values) as $written) {
            /*
             * ⚠️ AS THE DATABASE READS THE NAME, which `last(explode('.', …))` did not: qualified names arrive
             * from a join, and SQLite, MySQL and MariaDB match column names without regard to case. Measured before
             * this read through `ResolvesWrittenColumns`: `Role::query()->update(['IS_OWNER' => true])` promoted
             * every role it matched, with no per-holder audit.
             */
            $column = $this->bareColumn((string) $written);

            if (in_array($column, self::PER_ROW, true)) {
                throw new RuntimeException(sprintf(
                    'Refusing %s to `%s` on roles: the owner flag is audited one row per HOLDER '
                    .'and the scope key is guarded per row, and these paths dispatch nothing — so the '
                    .'authority would change with no trail of who gained or lost it (ADR-020, ADR-033). '
                    .'Load the role and save it.',
                    $shape,
                    $column,
                ));
            }
        }
    }

    /**
     * ⚠️ THE ARITHMETIC FAMILY IS REFUSED WITHOUT ASKING FOR THE FLAG, unlike `update()` and `delete()`,
     * and the asymmetry is the point. The flag means "this instance's guards ran for the write in flight",
     * and an instance save is never in flight here: `Model::performUpdate()` calls `update()`, and
     * `Model::increment()` builds a fresh query outside any save. So there is no legitimate instance path
     * through these four methods to stand aside for, and treating the flag as though there were would leave
     * one more way to promote a role during somebody else's save.
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardArithmetic(array $values): void
    {
        parent::guardArithmetic($values);

        $this->refusePerRowAuthority($values, 'an arithmetic write');
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
        $this->refuseUnguardedDeletion('delete');

        return parent::delete();
    }

    /**
     * ⚠️ `forceDelete()` TOO, which review found open: Eloquent sends it STRAIGHT to the query builder
     * rather than through `delete()`, so `Role::query()->forceDelete()` removed the roles and cascaded
     * `role_user` with none of the per-holder revocation audits and no `Permissions::forget()` — cached
     * grants stayed usable for the rest of the process, answering for authority that no longer existed.
     * `ScopedBuilder` already carries this override for the cascade refusal and `AuditedBuilder` for the
     * audit; roles are the third concern to need it, and the reason is the same each time.
     *
     * The same flag discipline as `delete()`, rather than a flat refusal, so the two doors cannot drift
     * apart. `Role` does not soft-delete today, so no instance path reaches this one — which makes the
     * refusal unconditional in practice and correct on the day that changes.
     */
    public function forceDelete()
    {
        $this->refuseUnguardedDeletion('force-delete');

        return parent::forceDelete();
    }

    private function refuseUnguardedDeletion(string $shape): void
    {
        if ($this->getModel()->deletionProven()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing a bulk %s of roles: deleting one revokes it from every holder, and that is '
            .'audited per holder by `Role::delete()`. These paths dispatch nothing, so the bypass '
            .'would disappear with no record of whose it was (ADR-033).',
            $shape,
        ));
    }
}
