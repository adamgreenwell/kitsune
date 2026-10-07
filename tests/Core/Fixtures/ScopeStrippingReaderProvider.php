<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Scopes\OrgScope;

/**
 * A host provider that strips the org scope from its reader loads, as `OrgAwareUserProvider` strips membership — so a
 * reader's session carried to another org's site still loads them. The reader guard's second fence, on the row's own
 * `org_id`, is what this proves.
 */
class ScopeStrippingReaderProvider extends EloquentUserProvider
{
    public const DRIVER = 'scope-stripping';

    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(OrgScope::class);
    }
}
