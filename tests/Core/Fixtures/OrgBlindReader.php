<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * The skeleton's reader on a host whose query builder drops every `org_id` predicate — its own scope's and its caller's
 * alike — so a query asked for one org's readers reads every org's. Only a fence on the row as read back can tell.
 */
#[OrgScoped]
class OrgBlindReader extends Reader
{
    /**
     * @param  Builder  $query
     * @return OrgBlindReaderBuilder<$this>
     */
    public function newEloquentBuilder($query): OrgBlindReaderBuilder
    {
        return new OrgBlindReaderBuilder($query, $this);
    }
}
