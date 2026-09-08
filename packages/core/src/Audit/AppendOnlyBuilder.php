<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Models\AuditLog;
use RuntimeException;

/**
 * Refuses bulk rewrites, which model events never see.
 *
 * ⚠️ `updating` and `deleting` guards on the model cover instance mutations
 * only. `AuditLog::query()->update([...])` and `->delete()` compile straight
 * to SQL and fire nothing — so "append-only, enforced" was true of the path a
 * test exercises and false of the one-liner that rewrites the whole table.
 *
 * A claim that holds for the tested path and not the easy path is the failure
 * mode this project exists to avoid, so the guard moved to where the query is
 * built.
 *
 * @extends Builder<AuditLog>
 */
class AppendOnlyBuilder extends Builder
{
    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        throw new RuntimeException(
            'Audit rows are append-only, and a bulk update reaches more of them than a single '
            .'rewrite ever could. Record a new action instead (ADR-020).'
        );
    }

    public function delete()
    {
        throw new RuntimeException(
            'Audit rows are append-only and cannot be deleted. Retention is an operator policy '
            .'applied to the table, not something application code decides (ADR-020).'
        );
    }
}
