<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Persistence\ReadOnlyOrderBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use RuntimeException;

/** Opaque Org replay guards outlive the removed personal order graph. @internal */
#[OrgScoped]
final class PrivacyTombstone extends Model
{
    use EnforcesScope;

    protected $table = 'commerce_privacy_tombstones';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function save(array $options = [])
    {
        throw new RuntimeException('Privacy guards are written only by their checked workflow.');
    }

    public function delete()
    {
        throw new RuntimeException('Privacy guards are removed only by their checked workflow.');
    }

    /** @return ReadOnlyOrderBuilder<$this> */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }

    public function toArray(): array
    {
        return [];
    }
}
