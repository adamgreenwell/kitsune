<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * A real rival commits the Site and catalogue after the host pins its InnoDB snapshot. The writer must prove
 * current parents without changing the consistent-read scopes used by ordinary presentation queries.
 */

it('writes and replays a cart on a current locked site committed after an outer repeatable-read snapshot', function (): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection regression measures MySQL/MariaDB repeatable-read semantics.');
    }
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32)), 'cache.default' => 'array']);
    Notification::fake();
    $default = (string) config('database.default');
    config(['database.connections.cart-current-rival' => config('database.connections.'.$default)]);
    $rival = DB::connection('cart-current-rival');
    $orgId = null;
    $ownerId = null;
    // Register before the first committed fixture, so failure during setup cannot leak rows to the next engine test.
    $this->afterRollback(function () use ($rival, &$orgId, &$ownerId): void {
        if ($orgId !== null) {
            foreach (['commerce_cart_lines', 'commerce_carts', 'commerce_sales_policies', 'commerce_tax_rates', 'commerce_settings', 'commerce_listings', 'commerce_sellables'] as $table) {
                $rival->table($table)->where('org_id', $orgId)->delete();
            }
            $rival->table('orgs')->where('id', $orgId)->delete();
        }
        if ($ownerId !== null) {
            $rival->table('users')->where('id', $ownerId)->delete();
        }
        $rival->table('modules')->where('handle', 'kitsune/commerce')->delete();
        DB::purge('cart-current-rival');
    });
    $now = now();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'cart-current-rival', 'name' => 'Current Carts',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now]);
    // Commit the owner's authority before the snapshot; missing permissions must not mask catalogue discovery.
    DB::setDefaultConnection('cart-current-rival');
    try {
        app(Context::class)->setOrg(Org::query()->findOrFail($orgId));
        $owner = Fx::owner('current-cart-owner@example.test');
        Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
        ModuleKernel::flush();
        ModuleKernel::boot(app());
        $ownerId = $owner->getKey();
        $owner->setConnection($default);
    } finally {
        DB::setDefaultConnection($default);
    }

    // Replace only RefreshDatabase's empty wrapper to choose this transaction's actual isolation explicitly.
    $connection->rollBack();
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1)
        ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0);

    $siteId = (int) $rival->table('sites')->insertGetId(['org_id' => $orgId, 'handle' => 'current-main', 'slug' => 'cart-current-main',
        'name' => 'Current main', 'locale' => 'en', 'base_url' => 'https://current-carts.example.test',
        'canonical_host' => 'current-carts.example.test', 'path_prefix' => '', 'created_at' => $now, 'updated_at' => $now]);
    // The rival creates the catalogue through their real writers.
    DB::setDefaultConnection('cart-current-rival');
    try {
        $committedOrg = Org::query()->findOrFail($orgId);
        app(Context::class)->setOrg($committedOrg);
        $committedSite = Site::query()->whereKey($siteId)->firstOrFail();
        $committedSite->setRelation('org', $committedOrg);
        app(Context::class)->setSite($committedSite);
        BlueprintApplier::apply(new StorefrontBlueprint);
        $product = app(ProductWriter::class)->createProduct(['sku' => 'CURRENT-CART', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
            'title' => 'Current course', 'slug' => 'current-course', 'status' => 'published', 'values' => [],
        ], [], true);
        $request = Request::create('https://current-carts.example.test/cart');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        app()->instance('request', $request);
        $listingId = $rival->table('commerce_listings')->where('org_id', $orgId)->where('site_id', $siteId)
            ->where('sellable_id', $product->getKey())->lockForUpdate()->value('id');
        $product->setConnection($default);
    } finally {
        DB::setDefaultConnection($default);
    }
    $orgRow = $connection->table('orgs')->where('id', $orgId)->lockForUpdate()->first();
    $siteRow = $connection->table('sites')->where('org_id', $orgId)->where('id', $siteId)->lockForUpdate()->first();
    expect($orgRow)->not->toBeNull()->and($siteRow)->not->toBeNull()
        ->and($connection->table('sites')->where('org_id', $orgId)->count())->toBe(0);
    $org = (new Org)->newFromBuilder((array) $orgRow);
    $site = (new Site)->newFromBuilder((array) $siteRow);
    $site->setRelation('org', $org);
    app(Context::class)->setSite($site);

    try {
        Auth::guard('web')->setUser($owner);
        Fx::forget();
        $writer = app(CartWriter::class);
        $writer->add($request, $listingId, 2);
        $cart = $connection->table('commerce_carts')->where('org_id', $orgId)->where('site_id', $siteId)->lockForUpdate()->first();
        $line = $connection->table('commerce_cart_lines')->where('org_id', $orgId)->where('site_id', $siteId)->lockForUpdate()->first();
        expect($cart)->not->toBeNull()->and($line)->not->toBeNull()
            ->and((int) $cart->version)->toBe(1)->and((int) $line->quantity)->toBe(2)
            ->and(Cart::query()->useWritePdo()->whereKey($cart->id)->first())->toBeNull()
            ->and(CartLine::query()->useWritePdo()->whereKey($line->id)->first())->toBeNull();
        $writer->add($request, $listingId, 2);
        expect($connection->table('commerce_carts')->where('id', $cart->id)->lockForUpdate()->first())->toEqual($cart)
            ->and($connection->table('commerce_cart_lines')->where('id', $line->id)->lockForUpdate()->first())->toEqual($line);
        $writer->update($request, $line->id, 3, 1);
        expect((int) $connection->table('commerce_carts')->where('id', $cart->id)->lockForUpdate()->value('version'))->toBe(2)
            ->and((int) $connection->table('commerce_cart_lines')->where('id', $line->id)->lockForUpdate()->value('quantity'))->toBe(3);
        expect(fn () => $writer->update($request, $line->id, 4, 1))->toThrow(CartRefused::class);

        // The newly committed parent does not authorize a sibling Site or another Org to address its children.
        foreach ([false, true] as $crossOrg) {
            if ($crossOrg) {
                app(Context::class)->setOrg(Org::create(['slug' => 'cart-current-other-org', 'name' => 'Other Org', 'currency' => 'USD']));
            }
            $other = Site::create(['handle' => 'other', 'slug' => $crossOrg ? 'cart-current-other-org-site' : 'cart-current-sibling',
                'name' => 'Other', 'locale' => 'en', 'base_url' => $crossOrg ? '/other-org' : '/other']);
            app(Context::class)->setSite($other);
            expect(fn () => CartLine::create(['cart_id' => $cart->id, 'listing_id' => null, 'quantity' => 1]))->toThrow(RuntimeException::class)
                ->and(fn () => $writer->update($request, $line->id, 4, 2))->toThrow(CartRefused::class);
        }
        app(Context::class)->setSite($site);
        $writer->remove($request, $line->id, 2);
        expect($connection->table('commerce_cart_lines')->where('cart_id', $cart->id)->lockForUpdate()->get())->toBeEmpty()
            ->and((int) $connection->table('commerce_carts')->where('id', $cart->id)->lockForUpdate()->value('version'))->toBe(3)
            ->and($connection->table('sites')->where('id', $siteId)->count())->toBe(0)
            ->and($connection->transactionLevel())->toBe(1);
    } finally {
        Fx::tearDown();
    }
});
