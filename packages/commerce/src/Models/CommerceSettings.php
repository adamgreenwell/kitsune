<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Commerce\Orders\PostalAddress;
use Kitsune\Commerce\Persistence\CommerceSetupValues;
use Kitsune\Commerce\Persistence\GuardedCommerceSetupBuilder;
use Kitsune\Commerce\Persistence\GuardsCommerceSetupWrites;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * Explicit calculator choices for one org. A configured tax mode alone does not configure a shipping origin.
 * Origin fields may stay null until a physical sales policy is enabled; they cannot then be cleared underneath it.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property string $tax_mode
 * @property string $shipping_tax_mode
 * @property string $shipping_tax_class
 * @property string|null $ship_from_name
 * @property array<string, string>|null $ship_from_address
 * @property int $version
 */
#[OrgScoped]
final class CommerceSettings extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCommerceSetupWrites;

    protected $table = 'commerce_settings';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'ship_from_name' => null, 'ship_from_address' => null];

    protected $casts = ['ship_from_address' => 'array', 'version' => 'integer'];

    public const FIELDS = ['tax_mode', 'shipping_tax_mode', 'shipping_tax_class', 'ship_from_name', 'ship_from_address'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', ...self::FIELDS, 'version'], 'Commerce setup requires a checked instance save.');
    }

    /** @param array<array-key, mixed> $values
     * @return array{tax_mode:string,shipping_tax_mode:string,shipping_tax_class:string,ship_from_name:?string,ship_from_address:?array<string,string>}
     */
    public static function values(#[\SensitiveParameter] array $values): array
    {
        CommerceSetupValues::keys($values, self::FIELDS);
        $mode = $values['tax_mode'] ?? null;
        $shippingMode = $values['shipping_tax_mode'] ?? null;

        if (! in_array($mode, ['inclusive', 'exclusive'], true) || ! in_array($shippingMode, ['inclusive', 'exclusive'], true)) {
            throw new InvalidArgumentException('Choose explicit inclusive or exclusive tax modes for products and quoted shipping fees.');
        }

        $name = $values['ship_from_name'] ?? null;
        $address = $values['ship_from_address'] ?? null;

        return [
            'tax_mode' => $mode,
            'shipping_tax_mode' => $shippingMode,
            'shipping_tax_class' => CommerceSetupValues::taxClass($values['shipping_tax_class'] ?? null),
            'ship_from_name' => $name === null ? null : BillingDetails::name($name),
            'ship_from_address' => $address === null ? null : PostalAddress::from($address),
        ];
    }

    public function refuseInvalidSetupValues(): void
    {
        $this->refuseRawSetupShape();
        $org = $this->setupOrg();
        $this->refuseChangedSetupIdentity(['org_id']);

        if (($this->getAttributes()['ship_from_name'] ?? null) === null || ($this->getAttributes()['ship_from_address'] ?? null) === null) {
            if ($this->getConnection()->table('commerce_sales_policies')->useWritePdo()->where('org_id', $org->getKey())->where('delivery_kind', 'physical')->exists()) {
                throw new RuntimeException('A shipping origin cannot be cleared while physical sales policies remain.');
            }
        }
    }

    protected function refuseRawSetupShape(): void
    {
        $raw = $this->getAttributes();
        $address = $raw['ship_from_address'] ?? null;

        if ($address !== null) {
            try {
                if (! is_string($address) || strlen($address) > 8192) {
                    throw new InvalidArgumentException('The shipping origin address must be bounded JSON.');
                }

                $address = json_decode($address, true, 8, JSON_THROW_ON_ERROR);

                if (! is_array($address)) {
                    throw new InvalidArgumentException('The shipping origin address must be an address object or database null.');
                }
            } catch (JsonException) {
                throw new InvalidArgumentException('The shipping origin address must be valid JSON.');
            }
        }

        self::values([
            'tax_mode' => $raw['tax_mode'] ?? null, 'shipping_tax_mode' => $raw['shipping_tax_mode'] ?? null,
            'shipping_tax_class' => $raw['shipping_tax_class'] ?? null,
            'ship_from_name' => $raw['ship_from_name'] ?? null, 'ship_from_address' => $address,
        ]);
        CommerceSetupValues::integer($raw['version'] ?? null, CommerceSetupValues::MAX_VERSION);
    }

    /** @param Builder $query
     * @return GuardedCommerceSetupBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCommerceSetupBuilder
    {
        return new GuardedCommerceSetupBuilder($query, $this);
    }
}
