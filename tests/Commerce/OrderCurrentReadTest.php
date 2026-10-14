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
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Filament\OrderAdministration;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * A real InnoDB rival commits the owning Site after the host pins its outer snapshot. FOR UPDATE on an Order's
 * outer query does not make its ordinary coherence EXISTS current, so a new order's own readback used to disappear.
 * This exercises real preparation/replay/owner cancellation, with no services added to the default bare-clone leg.
 */

it('prepares replays and owner-cancels on a current locked site committed after an outer repeatable-read snapshot', function (): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This two-connection regression measures MySQL/MariaDB repeatable-read semantics.');
    }
    Fx::boot();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32)), 'cache.default' => 'array']);
    Notification::fake();
    $default = (string) config('database.default');
    config(['database.connections.order-current-rival' => config('database.connections.'.$default)]);
    $rival = DB::connection('order-current-rival');
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
        DB::purge('order-current-rival');
    });
    $now = now();
    $orgId = (int) $rival->table('orgs')->insertGetId(['slug' => 'order-current-rival', 'name' => 'Current Orders',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now]);
    // Commit the owner's authority before the snapshot; missing permissions must not mask order discovery.
    DB::setDefaultConnection('order-current-rival');
    try {
        app(Context::class)->setOrg(Org::query()->findOrFail($orgId));
        $owner = Fx::owner('current-order-owner@example.test');
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

    $siteId = (int) $rival->table('sites')->insertGetId(['org_id' => $orgId, 'handle' => 'current-main', 'slug' => 'order-current-main',
        'name' => 'Current main', 'locale' => 'en', 'base_url' => 'https://current-orders.example.test',
        'canonical_host' => 'current-orders.example.test', 'path_prefix' => '', 'created_at' => $now, 'updated_at' => $now]);
    // The rival creates the catalogue and calculator settings through their real writers.
    DB::setDefaultConnection('order-current-rival');
    try {
        $committedOrg = Org::query()->findOrFail($orgId);
        app(Context::class)->setOrg($committedOrg);
        $committedSite = Site::query()->whereKey($siteId)->firstOrFail();
        $committedSite->setRelation('org', $committedOrg);
        app(Context::class)->setSite($committedSite);
        BlueprintApplier::apply(new StorefrontBlueprint);
        $product = app(ProductWriter::class)->createProduct(['sku' => 'CURRENT-ORDER', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
            'title' => 'Current course', 'slug' => 'current-course', 'status' => 'published', 'values' => [],
        ], [], true);
        $setup = app(CommerceSetupWriter::class);
        $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
        $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 0]);
        $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
        $request = Request::create('https://current-orders.example.test/cart');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        app()->instance('request', $request);
        $listingId = $rival->table('commerce_listings')->where('org_id', $orgId)->where('site_id', $siteId)
            ->where('sellable_id', $product->getKey())->lockForUpdate()->value('id');
        app(CartWriter::class)->add($request, $listingId, 2);
        $product->setConnection($default);
    } finally {
        DB::setDefaultConnection($default);
    }
    $orgRow = $connection->table('orgs')->where('id', $orgId)->lockForUpdate()->first();
    $siteRow = $connection->table('sites')->where('org_id', $orgId)->where('id', $siteId)->lockForUpdate()->first();
    expect($orgRow)->not->toBeNull()->and($siteRow)->not->toBeNull()
        ->and($connection->table('sites')->useWritePdo()->where('org_id', $orgId)->count())->toBe(0);
    $org = (new Org)->newFromBuilder((array) $orgRow);
    $site = (new Site)->newFromBuilder((array) $siteRow);
    $site->setRelation('org', $org);
    app(Context::class)->setSite($site);

    try {
        Auth::guard('web')->setUser($owner);
        Fx::forget();
        $details = ['email' => 'current-buyer@example.test', 'billing' => ['name' => 'Current Buyer',
            'address' => ['line1' => '1 Fixture Lane', 'country' => 'US']]];
        $quotes = app(OrderQuotes::class);
        $first = $quotes->quote($request, $details);
        $second = $quotes->quote($request, $details);
        $writer = app(OrderWriter::class);
        $order = $writer->prepare($request, $first['token'], 'none');
        $reference = $order->getAttributes()['reference'];
        $record = app(OrderReaderData::class)->record($connection, (object) $order->getAttributes());
        expect($record['reference'])->toBe($reference)->and($record['state'])->toBe('prepared')
            ->and($record['quoted_total_minor'])->toBe('4000')->and($record['lines'][0]['gross_minor'])->toBe('4000');

        // The ordinary scope remains consistent. The authoritative seam must nevertheless read its own new order.
        expect(Order::query()->useWritePdo()->whereKey($order->getKey())->first())->toBeNull()
            ->and($order->getAttributes()['state'])->toBe('prepared')
            ->and((string) $order->getAttributes()['quoted_total_minor'])->toBe('4000')
            ->and($writer->prepare($request, $first['token'], 'none')->getKey())->toBe($order->getKey())
            ->and(DB::table('commerce_orders')->where('org_id', $orgId)->count())->toBe(1)
            ->and(DB::table('commerce_order_lines')->where('order_id', $order->getKey())->count())->toBe(1)
            ->and(DB::table('audit_log')->where('action', 'commerce.order_prepared')->count())->toBe(1);
        try {
            $writer->prepare($request, $second['token'], 'none');
            $this->fail('A second quote bypassed the active cart revision claim.');
        } catch (OrderRefused $refusal) {
            expect($refusal->reason)->toBe('existing');
        }

        // A signed-in owner on another Site or Org still cannot address this order through the narrowed seam.
        $other = Site::create(['handle' => 'other', 'slug' => 'order-current-other', 'name' => 'Other', 'locale' => 'en', 'base_url' => '/other']);
        app(Context::class)->setSite($other);
        expect(fn () => $writer->cancelAsOwner($reference))->toThrow(OrderRefused::class);
        $otherOrg = Org::create(['slug' => 'order-current-other-org', 'name' => 'Other Org', 'currency' => 'USD']);
        app(Context::class)->setOrg($otherOrg);
        $otherSite = Site::create(['handle' => 'other-org', 'slug' => 'order-current-other-org-site', 'name' => 'Other Org Site', 'locale' => 'en', 'base_url' => '/other-org']);
        app(Context::class)->setSite($otherSite);
        Fx::owner('other-current-owner@example.test');
        expect(fn () => $writer->cancelAsOwner($reference))->toThrow(OrderRefused::class)
            ->and(DB::table('commerce_orders')->where('id', $order->getKey())->value('state'))->toBe('prepared');
        app(Context::class)->setSite($site);
        Auth::guard('web')->setUser($owner);
        Fx::forget();
        $writer->cancelAsOwner($reference);
        $writer->cancelAsOwner($reference);
        expect(DB::table('commerce_orders')->where('id', $order->getKey())->value('state'))->toBe('cancelled')
            ->and(DB::table('commerce_orders')->where('id', $order->getKey())->value('active_cart_key'))->toBeNull()
            ->and(DB::table('audit_log')->where('action', 'commerce.order_cancelled')->count())->toBe(1)
            ->and(DB::table('commerce_sellables')->where('id', $product->getKey())->lockForUpdate()->value('stock'))->toBeIn([5, '5'])
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and($connection->table('sites')->where('id', $siteId)->count())->toBe(0)
            ->and($connection->transactionLevel())->toBe(1);
        $raw = $connection->table('commerce_orders')->where('id', $order->getKey())->lockForUpdate()->first();
        expect(app(OrderReaderData::class)->record($connection, $raw)['state'])->toBe('cancelled');
        $connection->table('commerce_order_access_tokens')->insert(['org_id' => $orgId, 'site_id' => $siteId,
            'order_id' => $order->getKey(), 'token_hash' => bin2hex(random_bytes(32)), 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'expires_at' => now()->utc()->addMinutes(30)->format('Y-m-d H:i:s'), 'consumed_at' => null]);
        app(OrderAdministration::class)->erase($reference);
        expect($connection->table('commerce_orders')->where('id', $order->getKey())->lockForUpdate()->first())->toBeNull()
            ->and($connection->table('commerce_order_lines')->where('order_id', $order->getKey())->lockForUpdate()->get())->toBeEmpty()
            ->and($connection->table('commerce_order_access_tokens')->where('order_id', $order->getKey())->lockForUpdate()->get())->toBeEmpty()
            ->and($connection->table('audit_log')->where('action', 'commerce.order_erased')->count())->toBe(1)
            ->and($connection->table('sites')->where('id', $siteId)->count())->toBe(0)
            ->and($connection->transactionLevel())->toBe(1);
    } finally {
        Fx::tearDown();
    }
});
