<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * @property int $id
 * @property int $org_id
 * @property int|null $site_id
 * @property string $label
 */
#[SiteScoped]
class SiteThing extends Model
{
    use EnforcesScope;

    protected $guarded = [];

    public $timestamps = false;
}
