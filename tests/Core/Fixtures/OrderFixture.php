<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Core\Models\Site;

/** Raw historical rows: no current catalogue, commerce runtime, provider call or permissive model cast. */
final class OrderFixture
{
    public static function request(?Store $session = null): Request
    {
        $request = Request::create('/cart/orders');
        $request->setLaravelSession($session ?? new Store('order-fixture', new ArraySessionHandler(120)));
        $request->session()->start();
        app()->instance('request', $request);

        return $request;
    }

    /** @return array{name:string,address:array<string,string>} */
    public static function billing(): array
    {
        return ['name' => 'Historical Buyer', 'address' => ['line1' => '1 Saved Street', 'line2' => '',
            'city' => 'New York', 'region' => 'NY', 'postal_code' => '10001', 'country' => 'US']];
    }

    /** @param array<string,mixed> $overrides
     * @param  array<string,mixed>  $lineOverrides
     */
    public static function plant(Site $site, ?string $reader = null, ?string $ownerKey = null, array $overrides = [], array $lineOverrides = []): object
    {
        $owner = $ownerKey ?? ($reader === null ? CartOwner::anonymousKey(bin2hex(random_bytes(32))) : CartOwner::readerKey($reader));
        $data = ['org_id' => $site->org_id, 'site_id' => $site->getKey(), 'reference' => bin2hex(random_bytes(16)),
            'reader_id' => $reader, 'owner_key' => $owner, 'contact_email' => 'historical-buyer@example.test',
            'cart_ref' => 1, 'cart_version' => 0, 'request_key' => bin2hex(random_bytes(32)),
            'request_digest' => bin2hex(random_bytes(32)), 'snapshot_hash' => bin2hex(random_bytes(32)),
            'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0'), 'state' => 'prepared', 'currency' => 'USD',
            'mode' => 'test', 'tax_mode' => 'exclusive', 'billing' => json_encode(self::billing(), JSON_THROW_ON_ERROR),
            'delivery' => null, 'shipping' => null, 'site_snapshot' => json_encode(['name' => $site->name, 'base_url' => $site->base_url], JSON_THROW_ON_ERROR),
            'quoted_item_net_minor' => '2000', 'quoted_shipping_net_minor' => '0', 'quoted_tax_minor' => '100',
            'quoted_total_minor' => '2100', 'confirmed_total_minor' => null, 'created_at' => self::now(), 'cancelled_at' => null,
            ...$overrides];
        $id = DB::table('commerce_orders')->insertGetId($data);
        if (DB::connection()->getSchemaBuilder()->hasTable(AccessFulfillment::WORK)) {
            // Historical planting mirrors migration213, including foreign-Site attack fixtures without changing Context.
            DB::table(AccessFulfillment::WORK)->insert(['org_id' => $data['org_id'], 'site_id' => $data['site_id'],
                'order_id' => $id, 'state' => $data['state'] === 'paid' ? 'initializing' : 'awaiting_payment',
                'category' => null, 'manifest' => null, 'manifest_hash' => null, 'claim_binding' => null, 'claim_hash' => null, 'claim_reader_id' => null,
                'effect_count' => 0, 'replay_count' => 0, 'created_at' => $data['created_at'], 'updated_at' => $data['created_at']]);
        }
        DB::table('commerce_order_lines')->insert(['org_id' => $site->org_id, 'site_id' => $site->getKey(), 'order_id' => $id,
            'sellable_ref' => 7, 'listing_ref' => 8, 'entry_ref' => 9, 'sku' => 'SAVED-COURSE', 'title' => 'Saved course title',
            'quantity' => 1, 'unit_price_minor' => '2000', 'tax_class' => 'standard', 'tax_rate_parts' => 50000,
            'delivery_kind' => 'digital', 'net_minor' => '2000', 'tax_minor' => '100', 'gross_minor' => '2100',
            'entitlement_name' => 'course.saved', 'offer_terms' => null, ...$lineOverrides]);

        return DB::table('commerce_orders')->where('id', $id)->first() ?? throw new \RuntimeException('The order fixture was not retained.');
    }

    /** @param array<string,mixed> $overrides */
    public static function token(object $order, #[\SensitiveParameter] string $secret, array $overrides = []): int
    {
        return (int) DB::table('commerce_order_access_tokens')->insertGetId(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'token_hash' => hash('sha256', $secret), 'created_at' => self::now(),
            'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30)->format('Y-m-d H:i:s'), 'consumed_at' => null, ...$overrides]);
    }

    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s');
    }
}
