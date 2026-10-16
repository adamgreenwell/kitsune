<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Persistence\ReadsOrderRows;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** Private checked privacy custody; no implicit customer/operator serialization. @internal */
#[SiteScoped]
final class PrivacyHold extends Model
{
    use EnforcesScope;
    use ReadsOrderRows {
        ReadsOrderRows::newEloquentBuilder insteadof EnforcesScope;
    }

    protected $table = 'commerce_privacy_holds';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function toArray(): array
    {
        return [];
    }
}
