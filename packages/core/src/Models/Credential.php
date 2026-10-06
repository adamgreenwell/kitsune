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
 * One of an org's credentials, encrypted — ADR-040. One row per (org, slot, mode).
 *
 * ⚠️ NO `encrypted` CAST, AND THAT IS THE POINT. Measured: under it `toArray()` and `(string) $model` emit plaintext,
 * and a bulk `update()` stores plaintext. `ciphertext` is an opaque string only `CredentialCipher` produces or opens,
 * sealed with the org, slot and mode inside it, so a ciphertext moved to another row refuses to open.
 *
 * ⚠️ WRITTEN ONLY BY `CredentialWriter`: `GuardedCredentialBuilder` refuses every other write, inside
 * `withoutScopeBecause()` too. Not `RequiresModelSave`: the window refuses every write that is not the writer's, so a
 * per-column list would be a weaker copy of it.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @property int $id
 * @property int $org_id
 * @property string $slot
 * @property string $mode
 * @property string|null $ciphertext
 * @property string|null $key_id
 * @property CarbonImmutable $changed_at
 */
#[OrgScoped]
final class Credential extends Model
{
    use EnforcesScope;

    protected $table = 'credentials';

    public $timestamps = false;

    protected $guarded = [];

    /** Defence in depth: an array, JSON or a string of the model carries not even ciphertext. Not a substitute for having no cast. */
    protected $hidden = ['ciphertext', 'key_id'];

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
