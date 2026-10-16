<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use Illuminate\Database\Query\Builder;
use Kitsune\Commerce\Persistence\ReadOnlyOrderBuilder;

/** Shared commerce read-only write fence; these rows are Org-owned, with no Site coherence rule. @internal */
trait ReadsRetentionRows
{
    public function save(array $options = [])
    {
        throw new RetentionRefused;
    }

    public function delete()
    {
        throw new RetentionRefused;
    }

    /** @param Builder $query
     * @return ReadOnlyOrderBuilder<$this>
     */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }

    public function toArray(): array
    {
        return [];
    }
}
