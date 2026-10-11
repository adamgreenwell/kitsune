<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Scopes\OrgScope;

/**
 * The skeleton's reader, whose every query sheds the org scope it registers — as a host's provider or base query can
 * (`ScopeStrippingReaderProvider`). The guard still sees the scope registered, so it is a usable reader model, and the
 * only org predicate left in a query is the one its caller wrote.
 */
#[OrgScoped]
class ScopeShedReader extends Reader
{
    /** @return Builder<static> */
    public function newQuery(): Builder
    {
        return parent::newQuery()->withoutGlobalScope(OrgScope::class);
    }
}
