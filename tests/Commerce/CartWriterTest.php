<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartSessions;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32))]);
    Fx::declareReaders();
    $this->org = Org::create(['slug' => 'carts', 'name' => 'Carts', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'cart-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    CartSessions::listen(app(Dispatcher::class));
    $this->request = Request::create('/cart');
    $this->request->setLaravelSession(app('session.store'));
    $this->request->session()->start();
    $this->writer = app(CartWriter::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

function cartProduct(string $sku = 'COURSE', string $price = '2000', ?string $stock = null): int
{
    $product = app(ProductWriter::class)->createProduct(['sku' => $sku, 'price_minor' => $price, 'stock' => $stock], 'USD', [
        'title' => 'Product '.$sku, 'slug' => strtolower($sku), 'status' => 'published',
        'values' => ['product_description' => 'A course'],
    ], [], true);

    return (int) $product->listings()->firstOrFail()->getKey();
}

it('persists anonymous items by a hashed secret and treats repeated adds as max rather than increments', function (): void {
    $listing = cartProduct();
    $this->writer->add($this->request, (string) $listing, '2');
    $state = $this->writer->snapshot($this->request);
    expect($state['items'])->toHaveCount(1)->and($state['items'][0]['quantity'])->toBe(2)->and($state['total'])->toBe('40.00');
    $secret = $this->request->session()->get(CartOwner::sessionKey($this->org->id, $this->site->id));
    expect(Cart::firstOrFail()->owner_key)->toBe(CartOwner::anonymousKey($secret))->not->toBe($secret)
        ->and(Cart::firstOrFail()->reader_id)->toBeNull();
    $this->writer->add($this->request, $listing, 1);
    $this->writer->add($this->request, $listing, 2);
    expect($this->writer->snapshot($this->request))->toBe($state);
});

it('uses absolute quantities and detects stale tab edits without partial writes', function (): void {
    $this->writer->add($this->request, cartProduct(), 1);
    $one = $this->writer->snapshot($this->request);
    $id = $one['items'][0]['lineId'];
    $this->writer->update($this->request, $id, 3, $one['version']);
    $three = $this->writer->snapshot($this->request);
    expect(fn () => $this->writer->update($this->request, $id, 2, $one['version']))->toThrow(CartRefused::class)
        ->and($this->writer->snapshot($this->request))->toBe($three);
    $this->writer->update($this->request, $id, 3, $one['version']);
    expect($this->writer->snapshot($this->request))->toBe($three);
    $this->writer->remove($this->request, $id, $three['version']);
    $this->writer->remove($this->request, $id, $three['version']);
    expect($this->writer->snapshot($this->request)['items'])->toBe([]);
});

it('refuses malformed quantity and identity values before creating any cart', function (mixed $quantity): void {
    expect(fn () => $this->writer->add($this->request, 1, $quantity))->toThrow(CartRefused::class)
        ->and(Cart::count())->toBe(0)->and(CartLine::count())->toBe(0);
})->with([0, -1, 100, '01', ' 1', '+1', '1.0', 1.0, true, null, [[]], '99999999999999999999999']);

it('does exact decimal arithmetic and refuses supported-money overflow atomically', function (): void {
    expect(CartAmounts::multiply('9007199254740993', 2))->toBe('18014398509481986')
        ->and(CartAmounts::add('9007199254740993', '7'))->toBe('9007199254741000')
        ->and(fn () => CartAmounts::multiply('9223372036854775807', 2))->toThrow(InvalidArgumentException::class);
    $id = cartProduct(price: '9223372036854775807');
    expect(fn () => $this->writer->add($this->request, $id, 2))->toThrow(CartRefused::class)
        ->and(Cart::count())->toBe(0)->and(CartLine::count())->toBe(0);
});

it('rechecks live price and stock and keeps deleted listings removable without copied product data', function (): void {
    $listing = cartProduct(stock: '3');
    $this->writer->add($this->request, $listing, 2);
    $sellable = Listing::findOrFail($listing)->sellable;
    $sellable->price_minor = '2500';
    $sellable->saveOrFail();
    expect($this->writer->snapshot($this->request)['total'])->toBe('50.00');
    // Simulate a committed payment changing live stock below the ordinary content/model write door.
    DB::table('commerce_sellables')->where('org_id', $this->org->id)->where('id', $sellable->getKey())->update(['stock' => '1']);
    $state = $this->writer->snapshot($this->request);
    expect($state['items'][0]['available'])->toBeFalse()->and($state['total'])->toBeNull();
    $sellable->getConnection()->table('commerce_listings')->where('id', $listing)->delete();
    $state = $this->writer->snapshot($this->request);
    expect($state['items'][0]['title'])->toBe('')->and($state['items'][0]['available'])->toBeFalse();
    $this->writer->remove($this->request, $state['items'][0]['lineId'], $state['version']);
    expect(CartLine::count())->toBe(0);
});

it('expires at the exclusive thirty-day boundary and a read or replay does not prolong it', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $id = cartProduct();
    $this->writer->add($this->request, $id, 1);
    $expires = Cart::firstOrFail()->expires_at;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 11:59:59', 'UTC'));
    $this->writer->snapshot($this->request);
    $this->writer->add($this->request, $id, 1);
    expect(Cart::firstOrFail()->expires_at->equalTo($expires))->toBeTrue();
    CarbonImmutable::setTestNow($expires);
    expect($this->writer->snapshot($this->request)['items'])->toBe([]);
    $this->writer->add($this->request, $id, 2);
    expect($this->writer->snapshot($this->request)['items'][0]['quantity'])->toBe(2)
        ->and(Cart::firstOrFail()->expires_at->equalTo($expires->addDays(30)))->toBeTrue();
});

it('adopts through the real Login event after session rotation and keeps reader rows after sign-out', function (): void {
    $listing = cartProduct();
    $reader = Fx::reader();
    $this->writer->add($this->request, $listing, 2);
    $before = $this->request->session()->getId();
    Auth::guard(Fx::GUARD)->login($reader);
    expect($this->request->session()->getId())->not->toBe($before)
        ->and($this->writer->snapshot($this->request)['items'][0]['quantity'])->toBe(2)
        ->and(Cart::firstOrFail()->reader_id)->toBe((string) $reader->id)
        ->and(Cart::count())->toBe(1);
    Auth::guard(Fx::GUARD)->logoutCurrentDevice();
    expect($this->writer->snapshot($this->request)['items'])->toBe([])->and(Cart::count())->toBe(1);
    Auth::guard(Fx::GUARD)->login($reader);
    expect($this->writer->snapshot($this->request)['items'][0]['quantity'])->toBe(2);
});

it('merges distinct products with the greater duplicate quantity and consumes the guest once', function (): void {
    $a = cartProduct('A');
    $b = cartProduct('B');
    $reader = Fx::reader();
    Auth::guard(Fx::GUARD)->login($reader);
    $this->writer->add($this->request, $a, 3);
    Auth::guard(Fx::GUARD)->logoutCurrentDevice();
    $this->writer->add($this->request, $a, 2);
    $this->writer->add($this->request, $b, 1);
    Auth::guard(Fx::GUARD)->login($reader);
    $state = $this->writer->snapshot($this->request);
    expect(array_column($state['items'], 'quantity'))->toBe([3, 1])->and(Cart::count())->toBe(1);
    $this->writer->adopt($this->request->session(), (string) $reader->id);
    expect($this->writer->snapshot($this->request))->toBe($state);
});

it('does not adopt a sibling-site guest cart merely because the reader signed in on another site', function (): void {
    $listing = cartProduct();
    $reader = Fx::reader();
    $this->writer->add($this->request, $listing, 2);
    $other = Site::create(['handle' => 'other', 'slug' => 'cart-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    Auth::guard(Fx::GUARD)->login($reader);
    expect($this->writer->snapshot($this->request)['items'])->toBe([]);
    app(Context::class)->setSite($this->site);
    expect($this->writer->snapshot($this->request)['items'])->toBe([])->and(Cart::firstOrFail()->reader_id)->toBeNull();
});

it('refuses an oversized merge atomically and retries after the saved cart has space', function (): void {
    $ids = [];
    foreach (range(1, 21) as $i) {
        $ids[] = cartProduct('SKU-'.$i);
    }
    $reader = Fx::reader();
    Auth::guard(Fx::GUARD)->login($reader);
    foreach (array_slice($ids, 0, 20) as $id) {
        $this->writer->add($this->request, $id, 1);
    }
    Auth::guard(Fx::GUARD)->logoutCurrentDevice();
    $this->writer->add($this->request, $ids[20], 2);
    Auth::guard(Fx::GUARD)->login($reader);
    $state = $this->writer->snapshot($this->request);
    expect($state['mergeBlocked'])->toBeTrue()->and($state['items'])->toHaveCount(20)->and(Cart::count())->toBe(2);
    $this->writer->remove($this->request, $state['items'][0]['lineId'], $state['version']);
    $state = $this->writer->snapshot($this->request);
    expect($state['mergeBlocked'])->toBeFalse()->and($state['items'])->toHaveCount(20)
        ->and($state['items'][19]['quantity'])->toBe(2)->and(Cart::count())->toBe(1);
});
