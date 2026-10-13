<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Persistence\GuardedCatalogueBuilder;
use Kitsune\Commerce\Persistence\GuardsCatalogueWrites;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * A site's own content for a shared sellable. One listing per site/SKU and per site/entry. References are immutable;
 * remove the listing before relinking. Unavailable listings stay visible to staff; availability is not publication.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property int $sellable_id
 * @property int $entry_id
 * @property bool $is_available
 */
#[SiteScoped]
final class Listing extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCatalogueWrites;

    protected $table = 'commerce_listings';

    protected $guarded = [];

    protected $attributes = ['is_available' => false];

    protected $casts = ['is_available' => 'boolean'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', 'site_id', 'sellable_id', 'entry_id', 'is_available'], 'Catalogue references require a checked instance save.');
    }

    protected static function booted(): void
    {
        // SiteScope admits shared entries and its own-site branch compares no org. Neither is correct for listings.
        self::addGlobalScope('catalogue_coherence', static function (Builder $query): void {
            $orgId = app(Context::class)->orgId();
            $siteId = app(Context::class)->siteId();

            if ($orgId === null || $siteId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('commerce_listings.org_id', $orgId)->where('commerce_listings.site_id', $siteId)
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('commerce_sellables')
                    ->whereColumn('commerce_sellables.id', 'commerce_listings.sellable_id')
                    ->whereColumn('commerce_sellables.org_id', 'commerce_listings.org_id'))
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', 'commerce_listings.site_id')
                    ->whereColumn('sites.org_id', 'commerce_listings.org_id'))
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('entries')
                    ->whereColumn('entries.id', 'commerce_listings.entry_id')
                    ->whereColumn('entries.org_id', 'commerce_listings.org_id')
                    ->whereColumn('entries.site_id', 'commerce_listings.site_id')->whereNull('entries.deleted_at'));
        });

        self::creating(function (self $row): void {
            $row->refuseInvalidCatalogueValues();
            $row->noteGuardedColumnsDerived();
        });
        self::updating(function (self $row): void {
            $row->refuseInvalidCatalogueValues();
            $row->noteGuardedColumnsDerived();
        });
    }

    /** Validate all endpoints under locks, again after every host observer. */
    public function refuseInvalidCatalogueValues(): void
    {
        $org = $this->catalogueOrg();
        $raw = $this->getAttributes();
        $siteId = self::catalogueKey($raw['site_id'] ?? null);
        $sellableId = self::catalogueKey($raw['sellable_id'] ?? null);
        $entryId = self::catalogueKey($raw['entry_id'] ?? null);
        $this->refuseChangedCatalogueIdentity(['org_id', 'site_id', 'sellable_id', 'entry_id']);

        if ($siteId !== app(Context::class)->siteId()) {
            throw new RuntimeException('A listing must belong to the site in context.');
        }

        $this->refuseRawCatalogueShape();

        $site = Site::query()->useWritePdo()->whereKey($siteId)->where('org_id', $org->getKey())->lockForUpdate()->first();
        $sellable = Sellable::query()->useWritePdo()->whereKey($sellableId)->where('org_id', $org->getKey())->lockForUpdate()->first();
        $entry = Entry::query()->useWritePdo()->whereKey($entryId)->where('org_id', $org->getKey())
            ->where('site_id', $siteId)->lockForUpdate()->first();

        if ($site === null || $sellable === null || $entry === null) {
            throw new RuntimeException('A listing requires its own site\'s live entry and its org\'s sellable.');
        }
    }

    protected function refuseRawCatalogueShape(): void
    {
        if (! in_array($this->getAttributes()['is_available'] ?? null, [true, false, 0, 1, '0', '1'], true)) {
            throw new RuntimeException('Listing availability must be a boolean.');
        }
    }

    /** A missing, moved or trashed endpoint must not trap a listing that only its owner can remove. */
    public function refuseInvalidCatalogueRemoval(): void
    {
        $this->catalogueOrg();
        $this->refuseChangedCatalogueIdentity(['org_id', 'site_id', 'sellable_id', 'entry_id']);

        if (self::catalogueKey($this->getAttributes()['site_id'] ?? null) !== app(Context::class)->siteId()) {
            throw new RuntimeException('A listing must belong to the site in context.');
        }
    }

    /** @return BelongsTo<Sellable, $this> */
    public function sellable(): BelongsTo
    {
        return $this->belongsTo(Sellable::class)->where('commerce_sellables.org_id', $this->org_id);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class)->where('entries.org_id', $this->org_id)->where('entries.site_id', $this->site_id);
    }

    /** @param QueryBuilder $query
     * @return GuardedCatalogueBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCatalogueBuilder
    {
        return new GuardedCatalogueBuilder($query, $this);
    }
}
