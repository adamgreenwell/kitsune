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
use InvalidArgumentException;
use Kitsune\Commerce\Persistence\CommerceSetupValues;
use Kitsune\Commerce\Persistence\GuardedCommerceSetupBuilder;
use Kitsune\Commerce\Persistence\GuardsCommerceSetupWrites;
use Kitsune\Commerce\Shipping\InternationalShipping;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * One org-shared SKU's tax and delivery choice. Physical packing is per SKU; different SKU lines never share a parcel.
 * Bounds are admission defaults, not a claim a carrier will accept the parcel: live quotes make that decision.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property int $sellable_id
 * @property string $tax_class
 * @property string $delivery_kind
 * @property int|null $weight_grams
 * @property int|null $length_mm
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property int|null $tare_grams
 * @property int|null $units_per_parcel
 * @property string|null $customs_description
 * @property string|null $customs_origin_country
 * @property string|null $customs_tariff_number
 * @property int $version
 */
#[OrgScoped]
final class SalesPolicy extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCommerceSetupWrites;

    public const MAX_WEIGHT_GRAMS = 1000000;

    public const MAX_DIMENSION_MM = 100000;

    public const PACKING_FIELDS = ['weight_grams', 'length_mm', 'width_mm', 'height_mm', 'tare_grams', 'units_per_parcel'];

    public const FIELDS = ['tax_class', 'delivery_kind', ...self::PACKING_FIELDS, ...InternationalShipping::SKU_FIELDS];

    protected $table = 'commerce_sales_policies';

    protected $guarded = [];

    protected $attributes = [
        'customs_description' => null, 'customs_origin_country' => null, 'customs_tariff_number' => null,
        'version' => 1, 'weight_grams' => null, 'length_mm' => null, 'width_mm' => null,
        'height_mm' => null, 'tare_grams' => null, 'units_per_parcel' => null,
    ];

    protected $casts = [
        'version' => 'integer', 'weight_grams' => 'integer', 'length_mm' => 'integer', 'width_mm' => 'integer',
        'height_mm' => 'integer', 'tare_grams' => 'integer', 'units_per_parcel' => 'integer',
    ];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', 'sellable_id', ...self::FIELDS, 'version'], 'Commerce sales policies require a checked instance save.');
    }

    protected static function booted(): void
    {
        self::addGlobalScope('sales_policy_coherence', static fn (Builder $query) => $query
            ->whereExists(static fn (QueryBuilder $sellable) => $sellable->selectRaw('1')->from('commerce_sellables')
                ->whereColumn('commerce_sellables.id', 'commerce_sales_policies.sellable_id')
                ->whereColumn('commerce_sellables.org_id', 'commerce_sales_policies.org_id')));
    }

    /** @param array<array-key, mixed> $values
     * @return array<string, string|null>
     */
    public static function values(array $values): array
    {
        CommerceSetupValues::keys($values, self::FIELDS);
        $kind = $values['delivery_kind'] ?? null;

        if (! in_array($kind, ['digital', 'physical'], true)) {
            throw new InvalidArgumentException('Choose an explicit digital or physical delivery kind.');
        }

        $result = ['tax_class' => CommerceSetupValues::taxClass($values['tax_class'] ?? null), 'delivery_kind' => $kind];

        foreach (self::PACKING_FIELDS as $field) {
            $value = $values[$field] ?? null;

            if ($kind === 'digital') {
                if ($value !== null) {
                    throw new InvalidArgumentException('Digital sales policies cannot carry a physical packing profile.');
                }

                $result[$field] = null;

                continue;
            }

            $maximum = match ($field) {
                'units_per_parcel' => '99',
                'weight_grams', 'tare_grams' => (string) self::MAX_WEIGHT_GRAMS,
                default => (string) self::MAX_DIMENSION_MM,
            };
            $result[$field] = CommerceSetupValues::integer($value, $maximum, zero: $field === 'tare_grams');
        }

        return [...$result, ...InternationalShipping::sku($values, $kind === 'physical')];
    }

    public function refuseInvalidSetupValues(): void
    {
        $this->refuseRawSetupShape();
        $org = $this->setupOrg();
        $this->refuseChangedSetupIdentity(['org_id', 'sellable_id']);
        $sellable = Sellable::query()->useWritePdo()->whereKey(self::setupKey($this->getAttributes()['sellable_id'] ?? null))
            ->where('org_id', $org->getKey())->lockForUpdate()->first();

        if ($sellable === null) {
            throw new RuntimeException('A sales policy requires its org\'s shared product.');
        }

        if (($this->getAttributes()['delivery_kind'] ?? null) === 'physical') {
            $settings = CommerceSettings::query()->useWritePdo()->where('org_id', $org->getKey())->lockForUpdate()->first();

            if ($settings === null || $settings->ship_from_name === null || $settings->ship_from_address === null) {
                throw new RuntimeException('Configure a complete shipping origin before enabling a physical sales policy.');
            }

            $settings->refuseInvalidSetupValues();
        }
    }

    protected function refuseRawSetupShape(): void
    {
        $raw = $this->getAttributes();
        self::setupKey($raw['sellable_id'] ?? null);
        self::values(array_intersect_key($raw, array_flip(self::FIELDS)));
        CommerceSetupValues::integer($raw['version'] ?? null, CommerceSetupValues::MAX_VERSION);
    }

    public function refuseInvalidSetupRemoval(): void
    {
        $this->setupOrg();
        $this->refuseChangedSetupIdentity(['org_id', 'sellable_id']);
    }

    /** @return BelongsTo<Sellable, $this> */
    public function sellable(): BelongsTo
    {
        return $this->belongsTo(Sellable::class)->where('commerce_sellables.org_id', $this->org_id);
    }

    /** @param QueryBuilder $query
     * @return GuardedCommerceSetupBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCommerceSetupBuilder
    {
        return new GuardedCommerceSetupBuilder($query, $this);
    }
}
