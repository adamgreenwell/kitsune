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
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Credentials\GuardedCredentialBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Which of an org's credentials are in force — ADR-040's test and live mode. One row per org; absent means test.
 *
 * ⚠️ NOT A COLUMN ON `orgs`. `Org` is unscoped and published, a save of it is unaudited, and its guarded columns stand
 * down inside the escape hatch; nor an ADR-022 setting, which a site could override. Written only by
 * `CredentialWriter::switchTo()`, under the window, the org's lock and an audit row, like the keys it selects.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @property int $id
 * @property int $org_id
 * @property string $mode
 * @property CarbonImmutable $changed_at
 */
#[OrgScoped]
final class OrgCredentialMode extends Model
{
    use EnforcesScope;

    protected $table = 'org_credential_modes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changed_at' => 'immutable_datetime'];

    /**
     * @param  Builder  $query
     * @return GuardedCredentialBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCredentialBuilder
    {
        return new GuardedCredentialBuilder($query, $this);
    }
}
