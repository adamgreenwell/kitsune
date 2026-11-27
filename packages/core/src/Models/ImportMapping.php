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
 * @property int $entry_id
 * @property int $entry_revision_id
 * @property string $entry_state_hash
 * @property string $action_hash
 * @property int $last_execution_id
 */
#[SiteScoped]
final class ImportMapping extends Model
{
    use EnforcesScope;

    public $timestamps = false;

    protected $table = 'migration_map';

    protected $guarded = ['*'];
}
