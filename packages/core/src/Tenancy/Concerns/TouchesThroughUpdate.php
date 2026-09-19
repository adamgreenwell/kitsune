<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use Illuminate\Support\Arr;

/**
 * Eloquent's `touch()`, written through the builder's own `update()` instead of past it.
 *
 * ⚠️ `touch($column)` IS AN UPDATE THAT NEVER REACHES `update()`. Eloquent implements it as
 * `$this->toBase()->update([$column => now])`, so every guard a builder hangs on `update()` stood aside for it, under
 * any spelling of any column. Measured on SQLite and MySQL: `FieldStorage::query()->touch('handle')` renamed a LOCKED
 * field and `touch('PII_CLASS')` stored a classification ADR-020 does not have;
 * `Site::query()->touch('canonical_host')` rewrote a claimed host `update()` refuses to write. On SQLite
 * `RolePermission::query()->touch('permission')` rewrote the grants it matched — a table with no org to narrow it —
 * with no audit, and `EntryRelation::query()->touch('ordering')` wrote `ordering` outside the versioning `update()`
 * wraps it in. The value written is always a timestamp, never the caller's, so it is an integrity hole rather than a
 * way to choose what lands — and it is closed the way `AuditedBuilder` already closed it for entries, which this now
 * serves too: routed through `update()`, so whatever that refuses, this refuses, and whatever it records, this records.
 *
 * `AppendOnlyBuilder` keeps its own `touch()`, which refuses outright: an append-only table has no `update()` to route
 * through.
 *
 * @internal Kitsune's own builders use this. It is not an extension point, and it may change or move before v1.2
 *           without notice (CONTRIBUTING.md: no new public API surface before then).
 */
trait TouchesThroughUpdate
{
    /**
     * The same arguments and the same answer as Eloquent's, with the write going through `update()`.
     *
     * @param  array<int, string>|string|null  $column
     * @return int|false
     */
    public function touch($column = null)
    {
        $time = $this->getModel()->freshTimestamp();

        // As Eloquent reads it: a non-empty column or list of columns, each set to the same instant.
        if ($column) {
            $columns = [];

            foreach (Arr::wrap($column) as $name) {
                $columns[$name] = $time;
            }

            return $this->update($columns);
        }

        $column = $this->getModel()->getUpdatedAtColumn();

        if (! $this->getModel()->usesTimestamps() || $column === null) {
            return false;
        }

        return $this->update([$column => $time]);
    }
}
