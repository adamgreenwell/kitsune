<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

/**
 * An unfinished installation-wide uninstall, never an installed receipt — ADR-038 / #264.
 *
 * @internal Recovery restores a consistent pre-uninstall backup; deleting this row is not recovery.
 *
 * @property string $handle
 * @property string $version
 */
#[Unscoped]
class ModuleUninstall extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;

    protected $primaryKey = 'handle';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'handle' => 'it identifies a module whose uninstall has not completed.',
            'version' => 'it records the code version at the start of destructive teardown.',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $marker) => $marker->noteGuardedColumnsDerived());
        static::updating(fn (self $marker) => $marker->noteGuardedColumnsDerived());
    }
}
