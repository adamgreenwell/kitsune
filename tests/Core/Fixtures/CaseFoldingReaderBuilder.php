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
class CaseFoldingReaderBuilder extends ScopedBuilder
{
    /**
     * @param  mixed  $id
     * @return $this
     */
    public function whereKey($id)
    {
        return parent::whereKey(is_string($id) ? strtolower($id) : $id);
    }
}
