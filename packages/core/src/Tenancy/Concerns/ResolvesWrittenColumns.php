<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use RuntimeException;

/**
 * Which column a written name reaches, answered once for every guarded builder.
 *
 * ⚠️ EACH BUILDER HAD ITS OWN ANSWER, AND ONLY ONE WAS RIGHT. `ScopedBuilder` learned on #127 that SQLite, MySQL
 * and MariaDB compare column names without regard to case, and that a JSON path has to come off before a table
 * qualifier is looked for. `GuardedRelationBuilder` and `GuardedStorageBuilder` each kept a private copy of the
 * older rule. So `update(['ORG_ID' => $rival])` was refused on a site and allowed on a relation row;
 * `update(['IS_LOCKED' => false])` cleared a field's lock; and `update(['settings->format' => …])` changed a
 * locked field's projection without even changing case. Measured on SQLite, MySQL and MariaDB before this existed.
 * `AuditedBuilder` had once carried this very defect beside a fixed copy in `ScopedBuilder`, which is the argument
 * for one copy rather than for more care.
 *
 * ⚠️ `use` IT; DO NOT COPY IT. A builder that compares a written column name any other way is the defect this
 * trait exists to end.
 *
 * @internal Kitsune's own builders use this. It is not an extension point, and it may change or move before v1.2
 *           without notice (CONTRIBUTING.md: no new public API surface before then). A builder that does not
 *           declare `getModel()` cannot use `refuseMisnamedGuardedColumn()`.
 */
trait ResolvesWrittenColumns
{
    /**
     * The column a written name reaches, as the guards compare it: `Entries`.`ORG_ID->x` is `org_id`.
     *
     * Strips table qualification, quoting and the JSON path, and folds case.
     *
     * ⚠️ THE CASE FOLD IS WHAT THE DATABASE DOES, and a guard that compares exactly is comparing something else.
     * SQLite, MySQL and MariaDB match column names without regard to ASCII case, so `update(['SETTINGS' => …])`
     * writes `settings`. PostgreSQL folds an unquoted name the same way and refuses a quoted one it does not have,
     * so folding here refuses nothing any engine would store under another spelling.
     *
     * ⚠️ ASCII ONLY, AND SO IS EVERY ENGINE. Measured on all three that accept another spelling at all: `org_íd`,
     * `ORG_İD`, a fullwidth `ｏrg_id` and `org_id ` with a trailing space are each an unknown column. `strtolower()`
     * folds exactly ASCII and nothing else, which is the same set.
     *
     * For the comparison only: the name handed to the database is the caller's, untouched.
     */
    protected function bareColumn(string $column): string
    {
        // ⚠️ And the JSON PATH is rooted at its column. Laravel accepts `update(['values->body' => ...])`, and a
        // comparison that kept the path never matched the guarded key `values` — so a bulk JSON-path write
        // skipped the per-row refusal entirely (issue #42).
        //
        // ⚠️ THE PATH COMES OFF FIRST, because a path may hold a dot and a table qualifier is found by its last
        // one. Stripping the qualifier first read `settings->a.b` as the column `b`, while Laravel's MySQL and
        // MariaDB grammars write it into `settings` at the key `a.b` — measured allowed on both. SQLite's and
        // PostgreSQL's reject that SQL, which is luck, not a guard.
        $bare = explode('->', $column)[0];

        $bare = str_contains($bare, '.')
            ? substr($bare, (int) strrpos($bare, '.') + 1)
            : $bare;

        return strtolower(trim($bare, '`"[]'));
    }

    /**
     * Refuse a write that reaches one column under more than one name.
     *
     * ⚠️ WHICH NAME THE DATABASE KEEPS IS NOT A QUESTION WITH ONE ANSWER, so a guard cannot know which value to
     * judge. SQLite keeps the FIRST of a duplicated column in an INSERT and the LAST in an UPDATE; MySQL and
     * MariaDB refuse a duplicated INSERT column outright (error 1110) and keep the last in an UPDATE. Measured on
     * all three. A guard that folds the names into one map keeps one of them, and it kept the one SQLite did not:
     * from org A, `SharedThing::query()->insertGetId(['ORG_ID' => $orgB, 'org_id' => $orgA, …])` passed the
     * scope-key check on `org_id` and planted the row in org B — with #127's case fold already in place.
     *
     * ⚠️ JSON PATHS ALONE NEVER COUNT. Each writes part of a column, and several into one column is an ordinary
     * thing to ask for. A whole-column write beside anything else reaching the same column is the ambiguity.
     *
     * Unconditional, including inside `withoutScopeBecause()`: this is not a scope question, and no Eloquent
     * write needs to name one column twice.
     *
     * @param  array<array-key, mixed>  $values
     */
    protected function refuseAmbiguousColumns(array $values): void
    {
        $names = [];
        $whole = [];

        foreach (array_keys($values) as $written) {
            $column = $this->bareColumn((string) $written);
            $names[$column][] = (string) $written;

            if (! str_contains((string) $written, '->')) {
                $whole[$column] = true;
            }
        }

        foreach ($names as $column => $spellings) {
            if (count($spellings) < 2 || ! isset($whole[$column])) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'This write names [%s] more than once — as [%s] — and the database writes every one of them into '
                .'that column. Which value it keeps depends on the engine and on the statement, so no guard can '
                .'know which one to check. Write the column once, under its own name.',
                $column,
                implode('], [', $spellings),
            ));
        }
    }

    /**
     * Refuse a guarded column that a write stands behind its guards for, but names other than they read it.
     *
     * ⚠️ THE GUARDS READ ONE ATTRIBUTE, AND THE DATABASE WRITES ANOTHER. A model's hooks check an attribute by its
     * own name — `HoldsSettings` asks for `getAttribute('settings')`, `EntryRelation` asks
     * `isDirty('field_storage_id')` — and `$model->update(['Settings' => …])` leaves that attribute alone and sets a
     * second one, which SQLite, MySQL and MariaDB then write into the same column. The hooks passed, the proof was
     * armed, and a value nothing checked was stored — measured, and case-folding the bulk comparison does not reach
     * it, because a save is the path that comparison stands aside for. So a write that stands behind its guards
     * writes each guarded column under exactly the name they read, or not at all.
     */
    protected function refuseMisnamedGuardedColumn(string $written, string $column): void
    {
        if ($written === $column) {
            return;
        }

        throw new RuntimeException(sprintf(
            '[%s] cannot be written on %s: it reaches [%s], and the checks this write ran read [%s] by that name '
            .'alone, so the value would be stored unchecked. Set [%s] itself.',
            $written,
            $this->getModel()::class,
            $column,
            $column,
            $column,
        ));
    }
}
