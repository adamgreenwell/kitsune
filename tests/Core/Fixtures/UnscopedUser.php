<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host's user model as Laravel ships it: no membership scope declared, nothing applied.
 *
 * ⚠️ THE SHAPE A NEW HOST STARTS FROM, which is why it is worth a fixture. `Permissions` asks membership through
 * the user model's own scoped query, and this model's query has no scope — so it is the fixture for "a model
 * that cannot answer the question must not be taken to have answered yes".
 */
class UnscopedUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
