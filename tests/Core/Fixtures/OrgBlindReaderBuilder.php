<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\ScopedBuilder;

/**
 * @template TModel of Model
 *
 * @extends ScopedBuilder<TModel>
 */
class OrgBlindReaderBuilder extends ScopedBuilder
{
    /**
     * Every `where` but one on an `org_id`, qualified or not, which it drops.
     *
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column) && ($column === 'org_id' || str_ends_with($column, '.org_id'))) {
            return $this;
        }

        return parent::where($column, $operator, $value, $boolean);
    }
}
