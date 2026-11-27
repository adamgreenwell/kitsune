<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * @internal
 *
 * @property int $id
 * @property int $import_source_id
 * @property string $state
 */
#[SiteScoped]
final class ImportExecution extends Model
{
    use EnforcesScope;

    public $timestamps = false;

    protected $fillable = ['import_source_id', 'plan_digest'];
}
