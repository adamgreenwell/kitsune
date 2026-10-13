<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Persistence\GuardedCatalogueBuilder;
use Kitsune\Commerce\Persistence\GuardsCatalogueWrites;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * One org-owned SKU, price and stock balance, shared by all its sites. Content belongs to each site's Listing.
 * Amounts are canonical decimal strings, never float or platform-sized integer casts. Null stock is untracked.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property string $currency
 * @property string $sku
 * @property string $price_minor
 * @property string|null $stock
 */
#[OrgScoped]
final class Sellable extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCatalogueWrites;

    protected $table = 'commerce_sellables';

    protected $guarded = [];

    protected $casts = ['price_minor' => 'string', 'stock' => 'string'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', 'currency', 'sku', 'price_minor', 'stock'], 'Catalogue values require a checked instance save.');
    }

    protected static function booted(): void
    {
        self::creating(function (self $row): void {
            $org = $row->catalogueOrg();
            $row->currency = $org->getAttribute('currency');
            $row->canonicalizeAmounts();
            $row->refuseInvalidCatalogueValues();
            $row->noteGuardedColumnsDerived();
        });
        self::updating(function (self $row): void {
            $row->canonicalizeAmounts();
            $row->refuseInvalidCatalogueValues();
            $row->noteGuardedColumnsDerived();
        });
    }

    /** Called again at the builder, on the raw values the database will receive, after host observers. */
    public function refuseInvalidCatalogueValues(): void
    {
        $org = $this->catalogueOrg();
        $raw = $this->getAttributes();
        $this->refuseChangedCatalogueIdentity(['org_id', 'currency']);

        if (! Currencies::supports($raw['currency'] ?? null) || $raw['currency'] !== $org->getAttribute('currency')) {
            throw new RuntimeException('A sellable must use its org\'s supported currency.');
        }

        $this->refuseRawCatalogueShape();
    }

    protected function refuseRawCatalogueShape(): void
    {
        $raw = $this->getAttributes();
        self::skuValue($raw['sku'] ?? null);
        MinorUnits::integer($raw['price_minor'] ?? null);

        if (($raw['stock'] ?? null) !== null) {
            MinorUnits::integer($raw['stock']);
        }
    }

    public static function skuValue(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $value) !== 1) {
            throw new RuntimeException('A SKU must be 1–64 uppercase ASCII letters, digits, periods, underscores or hyphens, beginning with a letter or digit.');
        }

        return $value;
    }

    private function canonicalizeAmounts(): void
    {
        $raw = $this->getAttributes();
        $this->price_minor = MinorUnits::integer($raw['price_minor'] ?? null);
        $this->stock = ($raw['stock'] ?? null) === null ? null : MinorUnits::integer($raw['stock']);
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'sellable_id');
    }

    /** @param Builder $query
     * @return GuardedCatalogueBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCatalogueBuilder
    {
        return new GuardedCatalogueBuilder($query, $this);
    }
}
