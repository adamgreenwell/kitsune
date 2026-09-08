<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Contracts;

/**
 * A model whose deletion would take content with it by foreign key.
 *
 * ⚠️ Declared as an interface so the check reaches BOTH paths. The refusal
 * started as a `deleting` model event, which `Site::query()->delete()`,
 * `deleteQuietly()` and `withoutEvents()` all dispatch straight past — and the
 * `ON DELETE CASCADE` behind it then hard-deleted every referenced entry with
 * no audit row and no soft delete.
 *
 * `ScopedBuilder` calls this for every row a bulk delete would remove, so the
 * model states the rule once and both paths enforce it.
 */
interface RefusesCascadingDeletes
{
    /**
     * Refuse if deleting this row would cascade to content.
     *
     * @throws \RuntimeException
     */
    public function guardCascade(): void;
}
