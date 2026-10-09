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
 * One link waiting in a reader's mail — ADR-037, reader accounts' second part, as built. Written and used up only by
 * `ReaderTokens`.
 *
 * ⚠️ ORG-SCOPED, WITH ITS SITE AS DATA. Under org B's context, org A's links do not exist. The site is not a scope: a
 * link is looked up by the site in context as well (`ReaderTokens::live()`), so a link mailed from Golfdom is dead at
 * Golfdom FR, and erasure — which acts from no site — still reaches every link of the org.
 *
 * ⚠️ NO GUARDED WRITE BUILDER, unlike `Entitlement`. A row a stray write gave the wrong site is dead, not dangerous: it
 * opens nothing on any other site, and `OrgScope` still keeps it inside its org. The only thing a row grants is the use
 * of a secret its hash came from, which no write can supply.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property string $purpose
 * @property string $subject
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 */
#[OrgScoped]
final class ReaderToken extends Model
{
    use EnforcesScope;

    protected $table = 'reader_tokens';

    public $timestamps = false;

    /** Written through `ReaderTokens::mint()`'s `forceCreate()` only. */
    protected $guarded = ['*'];

    /** Never in an array or a JSON dump: the subject is an address or a reader's key, and the hash is the link's. */
    protected $hidden = ['subject', 'token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => UtcInstant::class];
    }
}
