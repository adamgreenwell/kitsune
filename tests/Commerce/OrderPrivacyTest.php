<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture as Orders;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('o', 32))]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'private-order@example.test');
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
    $this->privacy = app(OrderReaderData::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

it('exports frozen order references and amounts with retained carts through disabled stale commerce and erases the account last', function (): void {
    $reader = (string) $this->reader->getKey();
    $one = Orders::plant($this->site, $reader);
    $token = bin2hex(random_bytes(32));
    Orders::token($one, $token);
    $two = Orders::plant($this->world['sites']['golfdom-fr'], $reader, overrides: [
        'state' => 'cancelled', 'active_cart_key' => null, 'cancelled_at' => Orders::now(),
    ]);
    $guest = Orders::plant($this->site);
    $rival = Orders::plant($this->world['sites']['rival'], $reader);
    $cart = Cart::create(['reader_id' => $reader, 'owner_key' => CartOwner::readerKey($reader), 'expires_at' => now()->addDay()]);
    CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
    EntitlementFixture::plant($this->site, $reader, 'course.saved');

    $export = app(ReaderExport::class)->for($reader)['module_data']['kitsune/commerce'];
    expect(array_keys($export))->toBe(['carts', 'orders'])
        ->and($export['carts'])->toHaveCount(1)->and(array_column($export['orders'], 'site'))->toBe(['golfdom', 'golfdom-fr'])
        ->and($export['orders'][0]['lines'][0])->toMatchArray(['sellable_ref' => '7', 'listing_ref' => '8', 'entry_ref' => '9',
            'sku' => 'SAVED-COURSE', 'unit_price_minor' => '2000', 'net_minor' => '2000', 'tax_minor' => '100', 'gross_minor' => '2100'])
        ->and($export['orders'][0]['billing']['name'])->toBe('Historical Buyer')
        ->and($export['orders'][0]['confirmed_total_minor'])->toBeNull()->and($export['orders'][0]['mode'])->toBe('test')
        ->and(json_encode($export, JSON_THROW_ON_ERROR))->not->toContain('owner_key', 'reader_id', 'token_hash', $token, $one->request_key, $one->snapshot_hash)
        ->and(app()->getProviders(CommerceServiceProvider::class))->toBe([]);

    expect(app(ReaderErasure::class)->erase($reader))->toBe(['grants' => 1, 'links' => 0, 'account' => true])
        ->and(DB::table('commerce_orders')->orderBy('id')->pluck('id')->all())->toBe([$guest->id, $rival->id])
        ->and(DB::table('commerce_order_lines')->whereIn('order_id', [$one->id, $two->id])->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0)
        ->and(DB::table('commerce_carts')->count())->toBe(0)->and(DB::table('commerce_cart_lines')->count())->toBe(0)
        ->and(Reader::query()->count())->toBe(0);
});

it('returns a singular guest projection only to the exact current site owner and erases all of its retained children', function (): void {
    $request = Orders::request();
    $owner = CartOwner::current($request, true);
    $order = Orders::plant($this->site, ownerKey: $owner->key);
    Orders::token($order, bin2hex(random_bytes(32)));
    expect($this->privacy->exportGuest($request, $order->reference)['reference'])->toBe($order->reference);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect(fn () => $this->privacy->exportGuest($request, $order->reference))->toThrow(RuntimeException::class);
    app(Context::class)->setSite($this->site);
    expect($this->privacy->eraseGuest($request, $order->reference))->toBe(1)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table('commerce_order_lines')->count())->toBe(0)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(0);
});

it('refuses unknown future financial state or malformed custody before exporting or erasing any reader data', function (string $table, array $patch): void {
    if (isset($patch['created_at']) && DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Server datetime columns refuse this malformed stored value before application custody can inspect it.');
    }
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    Orders::token($order, bin2hex(random_bytes(32)));
    DB::table($table)->where($table === 'commerce_orders' ? 'id' : 'order_id', $order->id)->update($patch);
    EntitlementFixture::plant($this->site, $reader, 'course.saved');

    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)->and(DB::table('commerce_order_lines')->count())->toBe(1)
        ->and(DB::table('commerce_order_access_tokens')->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
        ->and(DB::table('entitlements')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
})->with([
    'unknown mode' => ['commerce_orders', ['mode' => 'bad']],
    'future paid state' => ['commerce_orders', ['state' => 'paid']],
    'future confirmed money' => ['commerce_orders', ['confirmed_total_minor' => '2100']],
    'reader stamp disagrees' => ['commerce_orders', ['reader_id' => 'other-reader']],
    'active identity disagrees' => ['commerce_orders', ['active_cart_key' => str_repeat('f', 64)]],
    'quoted aggregate disagrees' => ['commerce_orders', ['quoted_total_minor' => '2101']],
    'quoted tax disagrees' => ['commerce_orders', ['quoted_tax_minor' => '101']],
    'site snapshot userinfo' => ['commerce_orders', ['site_snapshot' => '{"name":"Saved site","base_url":"https://private-user:private-password@example.test"}']],
    'site snapshot query' => ['commerce_orders', ['site_snapshot' => '{"name":"Saved site","base_url":"https://example.test/?secret=private-payload"}']],
    'site snapshot fragment' => ['commerce_orders', ['site_snapshot' => '{"name":"Saved site","base_url":"https://example.test/#private-payload"}']],
    'billing has unexpected PII key' => ['commerce_orders', ['billing' => '{"name":"A","address":{},"private_note":"private-payload"}']],
    'malformed timestamp' => ['commerce_orders', ['created_at' => '2026-02-30 12:00:00']],
    'noncanonical SKU' => ['commerce_order_lines', ['sku' => 'bad sku']],
    'line money disagrees' => ['commerce_order_lines', ['net_minor' => '1999']],
    'line tax disagrees' => ['commerce_order_lines', ['tax_minor' => '101']],
    'future terms' => ['commerce_order_lines', ['offer_terms' => '{"unknown":true}']],
    'entitlement grammar' => ['commerce_order_lines', ['entitlement_name' => 'Course Saved']],
    'physical without delivery' => ['commerce_order_lines', ['delivery_kind' => 'physical']],
    'malformed token hash' => ['commerce_order_access_tokens', ['token_hash' => str_repeat('z', 64)]],
]);

it('inspects unfiltered raw child stamps rather than hiding cross-org or sibling-site corruption', function (string $table, string $column): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    Orders::token($order, bin2hex(random_bytes(32)));
    $value = $column === 'org_id' ? $this->world['rival']->getKey() : $this->world['sites']['golfdom-fr']->getKey();
    DB::table($table)->where('order_id', $order->id)->update([$column => $value]);
    expect(fn () => $this->privacy->export(DB::connection(), (int) $this->org->getKey(), $reader))->toThrow(RuntimeException::class)
        ->and(fn () => $this->privacy->erase(DB::connection(), (int) $this->org->getKey(), $reader))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)->and(DB::table($table)->count())->toBe(1);
})->with([
    ['commerce_order_lines', 'org_id'], ['commerce_order_lines', 'site_id'],
    ['commerce_order_access_tokens', 'org_id'], ['commerce_order_access_tokens', 'site_id'],
]);

it('refuses an oversized graph without partial reader export or erasure', function (string $table): void {
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    if ($table === 'commerce_order_lines') {
        $line = (array) DB::table($table)->where('order_id', $order->id)->first();
        unset($line['id']);
        foreach (range(1, 20) as $i) {
            DB::table($table)->insert($line);
        }
    } else {
        foreach (range(1, 21) as $i) {
            Orders::token($order, bin2hex(random_bytes(32)));
        }
    }
    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table($table)->count())->toBe(21)->and(Reader::query()->count())->toBe(1);
})->with(['commerce_order_lines', 'commerce_order_access_tokens']);

it('recomputes a frozen physical shipping quote and exports only its exact public snapshot', function (): void {
    $shipping = ['id' => 'rate_1', 'carrier' => 'Saved carrier', 'service' => 'Ground', 'amountMinor' => '500', 'currency' => 'USD',
        'shipmentId' => 'shipment_1', 'test' => true, 'taxClass' => 'shipping', 'taxMode' => 'inclusive', 'taxRateParts' => 0,
        'net' => '500', 'tax' => '0', 'gross' => '500'];
    $order = Orders::plant($this->site, overrides: ['delivery' => json_encode(Orders::billing(), JSON_THROW_ON_ERROR),
        'shipping' => json_encode($shipping, JSON_THROW_ON_ERROR), 'quoted_shipping_net_minor' => '500', 'quoted_total_minor' => '2600'],
        lineOverrides: ['delivery_kind' => 'physical']);
    expect($this->privacy->record(DB::connection(), $order)['shipping'])->toBe($shipping);
    DB::table('commerce_orders')->where('id', $order->id)->update(['shipping' => json_encode([...$shipping, 'private_provider_json' => 'not-exported'], JSON_THROW_ON_ERROR)]);
    $row = DB::table('commerce_orders')->where('id', $order->id)->first();
    expect(fn () => $this->privacy->record(DB::connection(), $row))->toThrow(RuntimeException::class);
});

it('checks the current exact Org and Site before an operator erases a checked record', function (): void {
    $order = Orders::plant($this->site);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect(fn () => $this->privacy->eraseRecord(DB::connection(), $order))->toThrow(RuntimeException::class);
    app(Context::class)->setOrg($this->world['rival'])->setSite($this->world['sites']['rival']);
    expect(fn () => $this->privacy->eraseRecord(DB::connection(), $order))->toThrow(RuntimeException::class);
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    expect($this->privacy->eraseRecord(DB::connection(), $order))->toBe(1)->and(DB::table('commerce_orders')->count())->toBe(0);
});

it('rolls back the entire reader erasure when a physical parent deletion is cancelled', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The cancellation trigger is a disposable SQLite probe.');
    }
    $reader = (string) $this->reader->getKey();
    $order = Orders::plant($this->site, $reader);
    Orders::token($order, bin2hex(random_bytes(32)));
    EntitlementFixture::plant($this->site, $reader, 'course.saved');
    DB::unprepared('CREATE TRIGGER refuse_order_erasure BEFORE DELETE ON commerce_orders BEGIN SELECT RAISE(IGNORE); END');
    try {
        expect(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
            ->and(DB::table('commerce_order_lines')->count())->toBe(1)->and(DB::table('commerce_order_access_tokens')->count())->toBe(1)
            ->and(DB::table('commerce_orders')->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
            ->and(DB::table('entitlements')->count())->toBe(1)->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER refuse_order_erasure');
    }
});

it('distinguishes an unapplied order schema from missing recorded custody or a partial schema', function (bool $recorded, bool $partial): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('DDL absence probes use transactional disposable SQLite only.');
    }
    DB::getSchemaBuilder()->drop('commerce_order_access_tokens');
    if (! $partial) {
        DB::getSchemaBuilder()->drop('commerce_order_lines');
        DB::getSchemaBuilder()->drop('commerce_orders');
    }
    DB::table('migrations')->where('migration', '0001_01_01_000205_create_commerce_orders')->delete();
    if ($recorded) {
        DB::table('migrations')->insert(['migration' => '0001_01_01_000205_create_commerce_orders', 'batch' => 1]);
    }
    if (! $recorded && ! $partial) {
        expect($this->privacy->export(DB::connection(), (int) $this->org->getKey(), (string) $this->reader->getKey()))->toBe(['orders' => []]);
    } else {
        expect(fn () => $this->privacy->export(DB::connection(), (int) $this->org->getKey(), (string) $this->reader->getKey()))->toThrow(RuntimeException::class);
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);
