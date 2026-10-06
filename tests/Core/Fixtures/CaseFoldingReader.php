<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Query\Builder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * A string-keyed reader whose table finds `ABC` as `abc`, as a host's case-insensitive collation on MySQL does — so the
 * writer is seen to store the HOST's spelling, read back from the row, never the caller's.
 */
#[OrgScoped]
class CaseFoldingReader extends TestUlidReader
{
    /**
     * @param  Builder  $query
     * @return CaseFoldingReaderBuilder<$this>
     */
    public function newEloquentBuilder($query): CaseFoldingReaderBuilder
    {
        return new CaseFoldingReaderBuilder($query, $this);
    }
}
