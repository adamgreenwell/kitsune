<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * The reader's choice for one Site. Org scope lets subject export and erasure see every Site; processing reads also
 * require the Site in context and an exact reader key.
 *
 * @internal
 *
 * @property int $id
 * @property int $site_id
 * @property string $purpose
 * @property string $notice_version
 * @property bool $granted
 * @property CarbonImmutable $recorded_at
 * @property ?CarbonImmutable $expires_at
 * @property CarbonImmutable $retain_until
 */
#[OrgScoped]
final class ReaderConsentEvent extends Model
{
    use EnforcesScope;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['reader_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'recorded_at' => UtcInstant::class,
            'expires_at' => UtcInstant::class,
            'retain_until' => UtcInstant::class,
        ];
    }
}
