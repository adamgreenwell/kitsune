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
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartSessions;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    Fx::declareReaders();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'session.lifetime' => 120]);
    Cookie::flushQueuedCookies();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->org = Org::create(['slug' => 'cart-owner', 'name' => 'Carts', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'cart-owner-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    CartSessions::listen(app(Dispatcher::class));
    $this->writer = app(CartWriter::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    Fx::tearDown();
});

function ownerRequest(?Store $session = null, ?string $seed = null): Request
{
    $request = Request::create('/cart');
    $request->setLaravelSession($session ?? app('session.store'));
    $request->session()->start();

    if ($seed !== null) {
        $request->cookies->set(ReaderSessions::EPHEMERAL, $seed);
    }

    app()->instance('request', $request);

    return $request;
}

function ownerProduct(string $sku = 'OWNER'): int
{
    $product = app(ProductWriter::class)->createProduct(['sku' => $sku, 'price_minor' => '2000', 'stock' => null], 'USD', [
        'title' => 'Product '.$sku, 'slug' => strtolower($sku), 'status' => 'published',
        'values' => ['product_description' => 'A course'],
    ], [], true);

    return (int) $product->listings()->firstOrFail()->getKey();
}

it('derives the same first owner in independent requests carrying one established session ID', function (): void {
    $id = bin2hex(random_bytes(20));
    $one = ownerRequest(new Store('owner-one', new ArraySessionHandler(120), $id));
    $two = ownerRequest(new Store('owner-two', new ArraySessionHandler(120), $id));
    $first = CartOwner::current($one, true);
    $second = CartOwner::current($two, true);

    expect($first->key)->toBe($second->key)->and($one->session()->getId())->toBe($two->session()->getId());
    $this->writer->add($one, ownerProduct('FIRST'), 1);
    $this->writer->add($two, ownerProduct('SECOND'), 1);
    expect(Cart::count())->toBe(1)->and(CartLine::count())->toBe(2);
});

it('recovers a cart through its remember cookie after the short framework session has expired', function (): void {
    $first = ownerRequest();
    $this->writer->add($first, ownerProduct(), 2);
    $expiry = Cart::firstOrFail()->expires_at;
    $cookie = Cookie::queued(ReaderSessions::EPHEMERAL);
    $seed = $cookie->getValue();
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime() - CarbonImmutable::now('UTC')->timestamp)->toBe(30 * 24 * 60 * 60);
    Cookie::flushQueuedCookies();
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(3));
    $fresh = ownerRequest(new Store('owner-recovered', new ArraySessionHandler(120)), $seed);
    expect($fresh->session()->getId())->not->toBe($first->session()->getId())
        ->and($this->writer->snapshot($fresh)['items'][0]['quantity'])->toBe(2)
        ->and(Cart::firstOrFail()->expires_at->equalTo($expiry))->toBeTrue();

    CarbonImmutable::setTestNow($expiry);
    expect($this->writer->snapshot($fresh)['items'])->toBe([])
        ->and(Cart::firstOrFail()->expires_at->equalTo($expiry))->toBeTrue();
});

it('recovers only the current site claim and leaves catalogue guests without an opt-in cookie', function (): void {
    $request = ownerRequest();
    expect(CartOwner::current($request))->toBeNull()->and(Cookie::queued(ReaderSessions::EPHEMERAL))->toBeNull();
    $first = CartOwner::current($request, true);
    $seed = Cookie::queued(ReaderSessions::EPHEMERAL)->getValue();
    Cookie::flushQueuedCookies();
    $other = Site::create(['handle' => 'other', 'slug' => 'cart-owner-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    $fresh = ownerRequest(new Store('owner-sibling', new ArraySessionHandler(120)), $seed);
    $second = CartOwner::current($fresh);

    expect($second->key)->not->toBe($first->key)->and($second->siteId)->toBe($other->getKey())
        ->and($fresh->session()->has(CartOwner::sessionKey($this->org->id, $other->id)))->toBeTrue();
});

it('restores a remembered guest before the real Login event adopts it after session rotation', function (): void {
    $request = ownerRequest();
    $this->writer->add($request, ownerProduct(), 2);
    $seed = Cookie::queued(ReaderSessions::EPHEMERAL)->getValue();
    Cookie::flushQueuedCookies();
    $reader = Fx::reader();
    $request->session()->flush();
    $request->session()->migrate();
    ownerRequest(seed: $seed);
    Auth::forgetGuards();
    Auth::guard(Fx::GUARD)->login($reader);

    expect(Cart::count())->toBe(1)->and(Cart::firstOrFail()->reader_id)->toBe((string) $reader->id)
        ->and($this->writer->snapshot(app('request'))['items'][0]['quantity'])->toBe(2);
});

it('uses a fresh anonymous claim after non-migrating reader logout with no commerce provider booted', function (string $method): void {
    $request = ownerRequest();
    $before = CartOwner::current($request, true);
    $seed = Cookie::queued(ReaderSessions::EPHEMERAL)->getValue();
    $request->cookies->set(ReaderSessions::EPHEMERAL, $seed);
    $id = $request->session()->getId();
    expect(app()->getProviders(CommerceServiceProvider::class))->toBe([]);
    Auth::guard(Fx::GUARD)->{$method}();

    expect($request->session()->getId())->toBe($id)
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL)->getExpiresTime())->toBeLessThan(time())
        ->and($request->session()->has(ReaderSessions::EPHEMERAL))->toBeFalse();
    // Deliberately keep a stale copy on this request to exercise the queued-deletion barrier as well.
    $request->cookies->set(ReaderSessions::EPHEMERAL, $seed);
    $after = CartOwner::current($request, true);
    expect($after->key)->not->toBe($before->key);
})->with(['logout', 'logoutCurrentDevice']);

it('ignores malformed remembered seeds and refuses missing secret authority before opting in', function (): void {
    $request = ownerRequest();
    $request->cookies->set(ReaderSessions::EPHEMERAL, ['forged' => str_repeat('a', 64)]);
    expect(CartOwner::current($request))->toBeNull()->and(Cookie::queued(ReaderSessions::EPHEMERAL))->toBeNull();
    config(['app.key' => null]);
    expect(fn () => CartOwner::current($request, true))->toThrow(CartRefused::class)
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL))->toBeNull();
});

it('refuses malformed logout-generation state instead of recovering an earlier anonymous claim', function (): void {
    $request = ownerRequest();
    $request->session()->put(ReaderSessions::LOGOUT_GENERATION, 'corrupt-generation');

    expect(fn () => CartOwner::current($request, true))->toThrow(CartRefused::class)
        ->and(Cookie::queued(ReaderSessions::EPHEMERAL))->toBeNull();
});
