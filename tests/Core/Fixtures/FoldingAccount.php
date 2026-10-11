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
 * A string-keyed reader account whose table finds `ABC` as `abc`, as a host's case-insensitive collation on MySQL does —
 * one key or a list of them — so a row is seen to be named only by the key it is read back as.
 */
#[OrgScoped]
class FoldingAccount extends StringKeyedReaderAccount
{
    /**
     * @param  Builder  $query
     * @return FoldingAccountBuilder<$this>
     */
    public function newEloquentBuilder($query): FoldingAccountBuilder
    {
        return new FoldingAccountBuilder($query, $this);
    }
}
