<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\GuardedEntitlementBuilder;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * One source of one reader's entitlement on one site — ADR-040. One row per (site, reader, entitlement, source).
 *
 * ⚠️ "LIVE" IS ONE ROW'S, "HOLDS" IS THE READER'S. A row is live while it is not revoked and has no end or an end after
 * now; a reader holds an entitlement while ANY of their rows for it is live, and only `EntitlementCheck::holds()` asks
 * that. Lapsed and revoked are derived per row, never stored: nothing ever writes "expired".
 *
 * ⚠️ TWO ENCODINGS OF "LIVE", one SQL (`scopeLiveAt`) and one PHP (`isLiveAt`), for the check and for the writer's
 * decision. `EntitlementExpiryTest` pins them against one dataset of rows × instants, so they cannot drift silently.
 * `$now` is always `CarbonImmutable::now('UTC')->startOfSecond()`, taken once by the caller: PHP's clock, never the
 * database's, which follows MySQL's session zone and PostgreSQL's transaction start, and which test time cannot reach.
 *
 * ⚠️ WRITTEN ONLY BY `EntitlementWriter`: `GuardedEntitlementBuilder` refuses every other write, inside
 * `withoutScopeBecause()` too.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property string $reader_id
 * @property string $entitlement
 * @property string $source
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $changed_at
 */
#[SiteScoped]
final class Entitlement extends Model
{
    use EnforcesScope;

    protected $table = 'entitlements';

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => UtcInstant::class,
            'revoked_at' => UtcInstant::class,
            'changed_at' => UtcInstant::class,
        ];
    }

    /**
     * @param  QueryBuilder  $query
     * @return GuardedEntitlementBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedEntitlementBuilder
    {
        return new GuardedEntitlementBuilder($query, $this);
    }

    /**
     * Live at `$now` (UTC, whole seconds): not revoked, and no end or an end after `$now`. The SQL half of the one rule;
     * the interval is half-open, so at its end exactly a source no longer counts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLiveAt(Builder $query, CarbonImmutable $now): void
    {
        $query->whereNull($this->qualifyColumn('revoked_at'))
            ->where(fn (Builder $live) => $live->whereNull($this->qualifyColumn('expires_at'))
                ->orWhere($this->qualifyColumn('expires_at'), '>', UtcInstant::stored($now)));
    }

    /** The PHP half of the same rule, for the writer's decision and a page's badge. Pinned to agree with `scopeLiveAt`. */
    public function isLiveAt(CarbonImmutable $now): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->greaterThan($now));
    }

    /** An owner's comp, rather than a producer's grant. */
    public function isComp(): bool
    {
        return $this->source === EntitlementSource::COMP;
    }
}
