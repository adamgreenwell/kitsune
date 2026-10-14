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
use Kitsune\Commerce\Persistence\CommerceSetupValues;
use Kitsune\Commerce\Persistence\GuardedCommerceSetupBuilder;
use Kitsune\Commerce\Persistence\GuardsCommerceSetupWrites;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

/** Explicit org-owned local rule, including a deliberate zero rate; no match is never inferred as zero. @internal
 * @property int $id
 * @property int $org_id
 * @property string $country
 * @property string $region
 * @property string $tax_class
 * @property int $rate_parts
 * @property int $version
 */
#[OrgScoped]
final class TaxRate extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCommerceSetupWrites;

    protected $table = 'commerce_tax_rates';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $casts = ['rate_parts' => 'integer', 'version' => 'integer'];

    public const FIELDS = ['country', 'region', 'tax_class', 'rate_parts'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', ...self::FIELDS, 'version'], 'Commerce tax rules require a checked instance save.');
    }

    /** @param array<array-key, mixed> $values
     * @return array{country:string,region:string,tax_class:string,rate_parts:string}
     */
    public static function values(array $values): array
    {
        CommerceSetupValues::keys($values, self::FIELDS);

        return [
            'country' => CommerceSetupValues::country($values['country'] ?? null),
            'region' => CommerceSetupValues::region($values['region'] ?? null),
            'tax_class' => CommerceSetupValues::taxClass($values['tax_class'] ?? null),
            'rate_parts' => CommerceSetupValues::integer($values['rate_parts'] ?? null, '1000000', zero: true),
        ];
    }

    public function refuseInvalidSetupValues(): void
    {
        $this->refuseRawSetupShape();
        $this->setupOrg();
        $this->refuseChangedSetupIdentity(['org_id']);
    }

    protected function refuseRawSetupShape(): void
    {
        $raw = $this->getAttributes();
        self::values(array_intersect_key($raw, array_flip(self::FIELDS)));
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
