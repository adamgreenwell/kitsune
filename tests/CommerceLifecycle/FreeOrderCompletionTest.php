<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Commerce\Orders\FreeAccessCorrections;
use Kitsune\Commerce\Orders\FreeOrders;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

function freeOrderSeed(bool $guest = false, string $price = '0', ?int $retentionDays = null, bool $physical = false, string $shippingAmount = '0.00', bool $mixed = false): array
{
    CredentialFixture::boot();
    Fx::boot();
    config(AppServiceProvider::readerConfig());
    config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32)), 'hashing.bcrypt.rounds' => 4, 'cache.default' => 'array', 'mail.default' => 'array', 'mail.from.address' => 'free@example.test']);
    ReaderFixture::forget();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 19:00:00', 'UTC'));
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    ModuleKernel::boot(app());
    $org = Org::create(['slug' => 'free-orders', 'name' => 'Free orders', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'free-main', 'name' => 'Free main', 'locale' => 'en', 'base_url' => 'https://free.example.test']);
    app(Context::class)->setSite($site);
    $owner = Fx::owner();
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'FREE-COURSE', 'price_minor' => $price, 'stock' => '5',
        'entitlement_name' => 'course.free', 'offer_terms' => ['version' => 1, 'kind' => 'fixed', 'days' => 30]], 'USD',
        ['title' => 'Free course', 'slug' => 'free-course', 'status' => 'published', 'values' => []], [], true);
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping',
        'ship_from_name' => 'Fixture warehouse', 'ship_from_address' => ['line1' => '1 Fixture Warehouse Road', 'country' => 'US']]);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 50000]);
    $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => $physical ? 'physical' : 'digital',
        ...($physical ? ['weight_grams' => 500, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100, 'tare_grams' => 50, 'units_per_parcel' => 1] : [])]);
    $carrier = (object) ['calls' => 0];
    if ($physical) {
        $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'shipping', 'rate_parts' => 50000]);
        app(CredentialWriter::class)->set(OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, 'shippo_test_free_fixture', CredentialFixture::PASSWORD);
        $respond = static function () use ($shippingAmount, $carrier): Response {
            expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
            $carrier->calls++;

            return new Response(201, ['Content-Type' => 'application/json'], json_encode(['object_id' => 'fixture_shipment', 'status' => 'SUCCESS', 'test' => true,
                'rates' => [['object_id' => 'fixture_rate', 'shipment' => 'fixture_shipment', 'amount' => $shippingAmount, 'currency' => 'USD', 'test' => true,
                    'provider' => 'Fixture carrier', 'servicelevel' => ['name' => 'Fixture service']]]], JSON_THROW_ON_ERROR));
        };
        app()->instance(ShippoRates::class, new ShippoRates(new Client([
            'handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, $respond)))])));
    }
    if ($retentionDays !== null) {
        $policies = app(RetentionPolicies::class);
        $policy = $policies->createAsOwner(['name'], 'Explicit fixture purpose.', 'Exercise free order retention.', 'confirmation_days', $retentionDays);
        $policies->activateAsOwner($policy->version);
    }
    $RF = ReaderFixture::class;
    $RF::mode($org, 'sign-in');
    $reader = $guest ? null : $RF::reader($org, 'free-reader@example.test');
    app(Context::class)->setSite($site);
    $request = OrderFixture::request(app('session.store'));
    if ($reader !== null) {
        Auth::guard($RF::GUARD)->login($reader);
    }
    Auth::guard('web')->setUser($owner);
    Permissions::forget();
    app(CartWriter::class)->add($request, $product->listings()->sole()->getKey(), 3);
    if ($mixed) {
        $digital = app(ProductWriter::class)->createProduct(['sku' => 'FREE-DIGITAL', 'price_minor' => '0', 'stock' => null], 'USD',
            ['title' => 'Free digital extra', 'slug' => 'free-digital-extra', 'status' => 'published', 'values' => []], [], true);
        $setup->setSalesPolicy($digital->id, ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
        app(CartWriter::class)->add($request, $digital->listings()->sole()->id, 1);
    }
    $quote = app(OrderQuotes::class)->quote($request, ['email' => 'free-buyer@example.test', 'billing' => OrderFixture::billing()]);
    $order = app(OrderWriter::class)->prepare($request, $quote['token'], $physical ? 'fixture_rate' : 'none');
    $review = app(CheckoutReview::class)->review($request, $order->reference);

    return compact('org', 'site', 'owner', 'product', 'reader', 'request', 'order', 'review', 'carrier');
}

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('commits a distinct free receipt and one stock allocation without a provider payment or refund', function (): void {
    $s = freeOrderSeed();
    $result = app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect($result['state'])->toBe('completed_free')
        ->and(DB::table('commerce_orders')->value('state'))->toBe('completed_free')
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(1)
        ->and(DB::table(PaidOrders::RECEIPTS)->count())->toBe(0)
        ->and(DB::table('commerce_checkout_attempts')->count())->toBe(0)
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(0)
        ->and(DB::table('commerce_stock_movements')->value('kind'))->toBe('free_order')
        ->and(DB::table('commerce_stock_movements')->value('reason'))->toBe('Free order stock allocation.')
        ->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('2');
    $record = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect($record['payment'])->toBeNull()->and($record['free_completion']['completed_at'])->toBe('2026-10-16T19:00:00Z')
        ->and($record['allocations'][0]['awarded'])->toBe(3)->and($record['refund'])->toBeNull();
});

it('returns the same owned completion after review expiry without restarting access or repeating stock', function (): void {
    $s = freeOrderSeed();
    $first = app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    app(AccessFulfillment::class)->reconcileReader($s['request'], $s['order']->reference);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
    $second = app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect($second)->toBe($first)->and(DB::table('commerce_stock_movements')->count())->toBe(1)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-15 19:00:00');
});

it('awards two of three after stock falls without creating a fictitious refund', function (): void {
    $s = freeOrderSeed();
    DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['stock' => 2]);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    $allocation = DB::table(PaidOrders::ALLOCATIONS)->sole();
    expect((int) $allocation->awarded)->toBe(2)->and((int) $allocation->unavailable)->toBe(1)
        ->and(DB::table(PaidOrders::OBLIGATIONS)->count())->toBe(0)
        ->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('0');
});

it('refuses a positive final total and preserves the prepared order', function (): void {
    $s = freeOrderSeed(price: '1');
    expect(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared')
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0);
});

it('refuses a changed purchase or an expired review before completion', function (string $change): void {
    $s = freeOrderSeed();
    if ($change === 'expired') {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(16));
    } else {
        DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['price_minor' => 100]);
    }
    expect(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared')
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0);
})->with(['expired', 'price']);

it('records complete unavailability once without awarding stock or access', function (): void {
    $s = freeOrderSeed();
    DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['stock' => 0]);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect((int) DB::table(PaidOrders::ALLOCATIONS)->value('awarded'))->toBe(0)
        ->and((int) DB::table(PaidOrders::ALLOCATIONS)->value('unavailable'))->toBe(3)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
});

it('keeps untracked stock untracked while recording its awarded quantity', function (): void {
    $s = freeOrderSeed();
    DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['stock' => null]);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect(DB::table('commerce_sellables')->value('stock'))->toBeNull()
        ->and((int) DB::table(PaidOrders::ALLOCATIONS)->value('awarded'))->toBe(3);
});

it('requires explicit proof-backed guest claiming and preserves the original fixed end', function (): void {
    $s = freeOrderSeed(guest: true);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect(DB::table('entitlements')->count())->toBe(0);
    $claims = app(OrderClaims::class);
    $claims->remember($s['request'], $s['order']->reference);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(4));
    $RF = ReaderFixture::class;
    $reader = $RF::reader($s['org'], 'different-from-order@example.test');
    app(Context::class)->setSite($s['site']);
    Auth::guard($RF::GUARD)->login($reader);
    $claims->claim($s['request'], $s['order']->reference);
    app(AccessFulfillment::class)->reconcileReader($s['request'], $s['order']->reference);
    expect(DB::table('entitlements')->value('reader_id'))->toBe((string) $reader->id)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-15 19:00:00')
        ->and(DB::table('commerce_orders')->value('contact_email'))->toBe('free-buyer@example.test');
});

it('anchors the explicitly frozen retention version to free completion and exports its own fact', function (): void {
    $s = freeOrderSeed(retentionDays: 10);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect(DB::table('commerce_order_privacy')->value('confirmed_at'))->toBe('2026-10-16 19:05:00')
        ->and(DB::table('commerce_order_privacy')->value('retained_until'))->toBe('2026-10-26 19:05:00');
    $json = app(OrderHistoryExport::class)->forOwner('jsonl')->body;
    $csv = app(OrderHistoryExport::class)->forOwner('csv')->body;
    expect($json)->toContain('"type":"free_completion"', '"state":"completed_free"', '"parent_reference":"free-completion"')->not->toContain('"type":"payment"', 'free-buyer@example.test')
        ->and($csv)->toContain('free_completion', 'completed_free');
});

it('removes and restores exact original units without refunding restocking or restarting a term', function (): void {
    $s = freeOrderSeed();
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    $line = (int) DB::table('commerce_order_lines')->value('id');
    $corrections = app(FreeAccessCorrections::class);
    $key = str_repeat('a', 32);
    $first = $corrections->changeAsOwner($s['order']->reference, [$line => [1, 2, 3]], 'remove', 0, 'Withdraw original free units.', $key, true);
    $duplicate = $corrections->changeAsOwner($s['order']->reference, [$line => [1, 2, 3]], 'remove', 0, 'Withdraw original free units.', $key, true);
    app(AccessAdjustments::class)->reconcile($s['order']->reference);
    expect($duplicate)->toBe($first)->and(DB::table('entitlements')->value('revoked_at'))->not->toBeNull();
    expect(app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole())['free_access_corrections']['selected_units'])->toBe([$line => [1, 2, 3]]);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
    $corrections->changeAsOwner($s['order']->reference, [$line => [2]], 'restore', 1, 'Restore one original unit.', str_repeat('b', 32), true);
    app(AccessAdjustments::class)->reconcile($s['order']->reference);
    expect(DB::table('entitlements')->value('revoked_at'))->toBeNull()
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-15 19:00:00')
        ->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('2')
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(0)
        ->and(DB::table(FreeAccessCorrections::TABLE)->count())->toBe(2);
    expect(app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole())['free_access_corrections']['selected_units'])->toBe([$line => [1, 3]]);
});

it('minimizes a completed free guest order with negative claim evidence and original retention custody', function (): void {
    $s = freeOrderSeed(guest: true, retentionDays: 10);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect(app(OrderReaderData::class)->eraseGuest($s['request'], $s['order']->reference))->toBe(0);
    $order = DB::table('commerce_orders')->sole();
    $record = app(OrderReaderData::class)->record(DB::connection(), $order);
    expect($order->contact_email)->toBeNull()->and(json_decode($order->billing, true, flags: JSON_THROW_ON_ERROR))->toBe(['name' => 'Historical Buyer'])
        ->and($record['privacy']['subject_erased'])->toBeTrue()->and($record['state'])->toBe('completed_free')
        ->and(DB::table('commerce_privacy_tombstones')->count())->toBeGreaterThan(0)
        ->and(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class);
});

it('fails closed when free completion evidence or its correction marker is removed', function (string $table): void {
    $s = freeOrderSeed();
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    DB::table($table)->delete();
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole()))->toThrow(RuntimeException::class);
})->with([FreeOrders::TABLE, FreeAccessCorrections::WORK]);

it('refuses completion from another browser site org or missing context', function (string $boundary): void {
    $s = freeOrderSeed(guest: true);
    $request = $s['request'];
    if ($boundary === 'browser') {
        $request = OrderFixture::request();
    } elseif ($boundary === 'site') {
        $site = Site::create(['handle' => 'sibling', 'slug' => 'free-sibling', 'name' => 'Sibling', 'base_url' => 'https://sibling.example.test']);
        app(Context::class)->setSite($site);
    } elseif ($boundary === 'org') {
        $org = Org::create(['slug' => 'free-rival', 'name' => 'Rival', 'currency' => 'USD']);
        app(Context::class)->setOrg($org);
        $site = Site::create(['handle' => 'main', 'slug' => 'free-rival-main', 'name' => 'Rival main', 'base_url' => 'https://rival-free.example.test']);
        app(Context::class)->setSite($site);
    } else {
        app(Context::class)->forget();
    }
    expect(fn () => app(FreeOrders::class)->complete($request, $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0)->and(DB::table('commerce_orders')->value('state'))->toBe('prepared');
})->with(['browser', 'site', 'org', 'none']);

it('refuses a removed listing or changed offer rather than fulfilling stale consent', function (string $change): void {
    $s = freeOrderSeed();
    if ($change === 'listing') {
        DB::table('commerce_listings')->where('sellable_id', $s['product']->id)->delete();
    } else {
        DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['offer_terms' => '{"version":1,"kind":"permanent"}']);
    }
    expect(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0)->and(DB::table('commerce_stock_movements')->count())->toBe(0);
})->with(['listing', 'offer']);

it('rolls back free completion when host audit code changes stock or the persisted receipt', function (string $corruption): void {
    $s = freeOrderSeed();
    $event = 'eloquent.created: '.AuditLog::class;
    $events = AuditLog::getEventDispatcher();
    $prior = $events->getRawListeners()[$event] ?? [];
    AuditLog::created(static function ($audit) use ($s, $corruption): void {
        if ($audit->action === 'commerce.order_completed_free') {
            match ($corruption) {
                'stock' => DB::table('commerce_sellables')->where('id', $s['product']->id)->update(['stock' => 999]),
                'receipt' => DB::table(FreeOrders::TABLE)->update(['completed_at' => '2026-10-01 00:00:00']),
                'audit' => DB::table('audit_log')->where('id', $audit->id)->delete(),
            };
        }
    });
    try {
        expect(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
            ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0)->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('5')
            ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared');
    } finally {
        $events->forget($event);
        foreach ($prior as $listener) {
            $events->listen($event, $listener);
        }
    }
})->with(['stock', 'receipt', 'audit']);

it('rejects nonowners stale corrections and unavailable units without changing access', function (string $invalid): void {
    $s = freeOrderSeed();
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    $line = (int) DB::table('commerce_order_lines')->value('id');
    if ($invalid === 'actor') {
        Fx::member();
    }
    $corrections = app(FreeAccessCorrections::class);
    expect(fn () => $corrections->changeAsOwner($s['order']->reference, [$line => [$invalid === 'unit' ? 4 : 1]], 'remove', $invalid === 'revision' ? 1 : 0,
        'An invalid correction.', str_repeat('c', 32), $invalid !== 'confirmation'))->toThrow(OrderRefused::class)
        ->and(DB::table(FreeAccessCorrections::TABLE)->count())->toBe(0)
        ->and(DB::table('entitlements')->value('revoked_at'))->toBeNull();
})->with(['actor', 'unit', 'revision', 'confirmation']);

it('completes mixed free physical and digital units after verified zero shipping with essential delivery holds', function (): void {
    $s = freeOrderSeed(guest: true, retentionDays: 10, physical: true, mixed: true);
    expect($s['carrier']->calls)->toBe(2);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    expect($s['carrier']->calls)->toBe(2)->and((int) DB::table(PaidOrders::ALLOCATIONS)->sum('awarded'))->toBe(4)
        ->and(DB::table('commerce_privacy_deliveries')->value('state'))->toBe('pending');
    app(OrderReaderData::class)->eraseGuest($s['request'], $s['order']->reference);
    $order = DB::table('commerce_orders')->sole();
    $record = app(OrderReaderData::class)->record(DB::connection(), $order);
    expect($order->contact_email)->toBeNull()->and($order->delivery)->toBeNull()->and($record['privacy']['delivery_pending'])->toBeTrue()
        ->and(DB::table('commerce_privacy_deliveries')->value('payload'))->toContain('free-buyer@example.test', '1 Saved Street');
});

it('keeps zero-priced goods with positive tax and shipping out of free completion', function (): void {
    $s = freeOrderSeed(physical: true, shippingAmount: '5.00');
    expect((string) DB::table('commerce_orders')->value('quoted_total_minor'))->toBe('525');
    expect(fn () => app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']))->toThrow(OrderRefused::class)
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0)->and($s['carrier']->calls)->toBe(2);
});

it('erases a free purchaser account last while retaining only permitted purchase fields and suppressing access', function (): void {
    $s = freeOrderSeed(retentionDays: 10);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    Auth::guard(ReaderFixture::GUARD)->logout();
    ReaderFixture::forget();
    Auth::guard('web')->setUser($s['owner']);
    app(Context::class)->setSite($s['site']);
    app(ReaderErasure::class)->erase((string) $s['reader']->id);
    $order = DB::table('commerce_orders')->sole();
    $record = app(OrderReaderData::class)->record(DB::connection(), $order);
    expect(DB::table('readers')->count())->toBe(0)->and(DB::table('entitlements')->count())->toBe(0)
        ->and($order->reader_id)->toBeNull()->and($order->contact_email)->toBeNull()
        ->and($record['privacy']['subject_erased'])->toBeTrue()->and($record['access_fulfillment']['state'])->toBe('suppressed');
});

it('retires a free order only after retention and original access obligations end and preserves negative replay evidence', function (): void {
    $s = freeOrderSeed(retentionDays: 0);
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    $line = (int) DB::table('commerce_order_lines')->value('id');
    app(FreeAccessCorrections::class)->changeAsOwner($s['order']->reference, [$line => [1, 2, 3]], 'remove', 0, 'Retire the original free contributions.', str_repeat('d', 32), true);
    Auth::guard(ReaderFixture::GUARD)->logout();
    ReaderFixture::forget();
    Auth::guard('web')->setUser($s['owner']);
    app(Context::class)->setSite($s['site']);
    $result = app(OrderPrivacy::class)->maintainOrder($s['order']->reference);
    expect($result['purged'])->toBe(1)->and(DB::table('commerce_orders')->count())->toBe(0)
        ->and(DB::table(FreeOrders::TABLE)->count())->toBe(0)->and(DB::table(FreeAccessCorrections::TABLE)->count())->toBe(0)
        ->and(DB::table('commerce_privacy_tombstones')->whereNotNull('purged_at')->count())->toBeGreaterThan(0);
});

it('keeps free history and exact unit corrections exportable while runtime commerce is disabled', function (): void {
    $s = freeOrderSeed();
    app(FreeOrders::class)->complete($s['request'], $s['order']->reference, $s['review']['token']);
    $line = (int) DB::table('commerce_order_lines')->value('id');
    app(FreeAccessCorrections::class)->changeAsOwner($s['order']->reference, [$line => [2]], 'remove', 0, 'Remove one original contribution.', str_repeat('e', 32), true);
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    ModuleKernel::flush();
    $json = app(OrderHistoryExport::class)->forOwner('jsonl')->body;
    expect($json)->toContain('"type":"free_completion"', '"type":"free_access_correction"', '"unit_number":2')->not->toContain('"type":"payment"', 'free-buyer@example.test');
});
