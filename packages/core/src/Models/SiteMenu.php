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
use Kitsune\Core\Tenancy\Contracts\FixesColumnsAtCreation;

/**
 * @property int $id
 * @property int $site_id
 * @property int $org_id
 * @property string $handle
 * @property string $name
 * @property array<array-key, mixed> $items
 * @property int $version
 */
#[SiteScoped]
final class SiteMenu extends Model implements FixesColumnsAtCreation
{
    use EnforcesScope;

    protected $fillable = ['handle', 'name', 'items', 'version'];

    protected $casts = ['items' => 'array', 'version' => 'integer'];

    public static function columnsFixedAtCreation(): array
    {
        return [
            'site_id' => 'menus belong to a single Site.',
            'org_id' => 'menus belong to a single Org.',
            'handle' => 'the selected theme uses this stable menu name.',
        ];
    }
}
