<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Filament\CheckoutResource;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Commerce\Filament\StockList;
use Kitsune\Commerce\Filament\StockResource;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as StockAdminFx;
use Livewire\Mechanisms\DataStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    // These direct page-method probes do not boot Livewire's provider. Share its ephemeral error bag as production does.
    app()->singleton(DataStore::class);
    StockAdminFx::boot();
    $this->org = StockAdminFx::org('stock-admin');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'stock-admin-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = StockAdminFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->type = ProductType::current();
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'], 'USD', ['title' => 'Board', 'slug' => 'board', 'status' => 'draft', 'values' => []]);
    $this->reference = (string) $this->product->getKey();
});

afterEach(fn () => StockAdminFx::tearDown());

function stockAdminEditor(array $grants): void
{
    $member = StockAdminFx::member(owner: false, email: 'editor@stock.test');
    $role = Role::create(['handle' => 'stock-editor', 'name' => 'Stock editor']);
    foreach ($grants as $action) {
        $role->grant(Permissions::forEntryType(ProductType::current()->handle, $action));
    }
    $role->assignTo($member->getKey());
    Permissions::forget();
}

it('offers only stock projections and adjustments to a current product editor', function (): void {
    stockAdminEditor(['view', 'update']);
    expect(StockResource::canAccess())->toBeTrue();
    foreach ([ProductResource::class, CurrencyResource::class, CheckoutResource::class, OrderResource::class] as $resource) {
        expect($resource::canAccess())->toBeFalse();
    }
    $page = app(StockList::class);
    $page->mount($this->reference);
    expect($page->detail())->toBe(['reference' => $this->reference, 'sku' => 'BOARD', 'stock' => '5'])
        ->and($page->products()->items())->toBe([['reference' => $this->reference, 'sku' => 'BOARD', 'stock' => '5']]);
    $page->quantity = '3';
    $page->reason = 'Two units damaged during the stock count';
    $page->adjustStock();

    expect($page->expectedStock)->toBe('3')->and($page->quantity)->toBe('3')->and($page->reason)->toBe('')
        ->and($this->product->fresh()->stock)->toBe('3')->and($page->getErrorBag()->all())->toBe([]);
    $history = $page->history();
    expect($history)->toHaveCount(1)->and($history[0]['reason'])->toBe('Two units damaged during the stock count')
        ->and($history[0])->not->toHaveKeys(['order_reference', 'line_reference', 'orderReference', 'actor_type', 'actorType']);
});

it('refuses a stock editor who has only one of the two entry grants', function (array $grants): void {
    stockAdminEditor($grants);
    expect(StockResource::canAccess())->toBeFalse()
        ->and(fn () => app(StockList::class)->mount($this->reference))->toThrow(HttpException::class);
})->with([[['view']], [['update']], [[]]]);

it('keeps the old expectation after a refused stale adjustment until the user reloads', function (): void {
    $page = app(StockList::class);
    $page->mount($this->reference);
    // Another checked stock mutation happened after this page captured its expectation.
    $other = app(StockList::class);
    $other->mount($this->reference);
    $other->quantity = '4';
    $other->reason = 'One unit damaged';
    $other->adjustStock();

    $page->quantity = '8';
    $page->reason = 'Incoming stock';
    $page->adjustStock();
    expect($this->product->fresh()->stock)->toBe('4')->and($page->expectedStock)->toBe('5')
        ->and($page->getErrorBag()->has('stock'))->toBeTrue()->and($page->history())->toHaveCount(1);
    $page->reloadBalance();
    expect($page->expectedStock)->toBe('4')->and($page->quantity)->toBe('4')->and($page->reason)->toBe('')
        ->and($page->getErrorBag()->all())->toBe([]);
});

it('reports malformed quantities reasons or tracking as a safe page error without a write', function (mixed $tracking, mixed $quantity, mixed $reason): void {
    $page = app(StockList::class);
    $page->mount($this->reference);
    $page->tracking = $tracking;
    $page->quantity = $quantity;
    $page->reason = $reason;
    $page->adjustStock();
    expect($this->product->fresh()->stock)->toBe('5')->and($page->history())->toBe([])
        ->and($page->getErrorBag()->get('stock'))->toBe([__('commerce::stock.refused')]);
})->with([
    ['finite', '-1', 'Wrong count'], ['finite', '1.5', 'Wrong count'], ['finite', ['unsafe'], 'Wrong count'],
    ['finite', '4', ''], ['finite', '4', '   '], ['finite', '4', ['unsafe']],
    ['unknown', '4', 'Wrong count'], [['unsafe'], '4', 'Wrong count'],
]);

it('rechecks every stock page action after authority or module readiness is lost', function (string $denial): void {
    $page = app(StockList::class);
    $page->mount($this->reference);
    match ($denial) {
        'no actor' => Auth::guard('web')->logout(),
        'member' => StockAdminFx::member(owner: false, email: 'ordinary@stock.test'),
        'disabled' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'stale' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'old']),
    };
    Permissions::forget();
    foreach (['products', 'detail', 'history', 'reloadBalance', 'adjustStock'] as $action) {
        expect(fn () => $page->{$action}())->toThrow(HttpException::class);
    }
    expect((string) DB::table('commerce_sellables')->value('stock'))->toBe('5')
        ->and(DB::table('commerce_stock_movements')->count())->toBe(0);
})->with(['no actor', 'member', 'disabled', 'stale']);

it('refuses malformed stock route references before reading a product', function (string $reference): void {
    expect(fn () => app(StockList::class)->mount($reference))->toThrow(HttpException::class);
})->with(['0', '01', '1.0', '1e2', '-1', '9223372036854775808', '']);
