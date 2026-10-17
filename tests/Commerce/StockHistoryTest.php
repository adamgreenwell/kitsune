<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Filament\StockHistory;
use Kitsune\Commerce\Filament\StockResource;
use Kitsune\Commerce\Inventory\StockRefused;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as HistoryFx;
use Livewire\Mechanisms\DataStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    // These direct page-method probes do not boot Livewire's provider. Share its ephemeral error bag as production does.
    app()->singleton(DataStore::class);
    HistoryFx::boot();
    $this->org = HistoryFx::org('stock-history');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'stock-history-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = HistoryFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->type = ProductType::current();
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'], 'USD', ['title' => 'Board', 'slug' => 'board', 'status' => 'draft', 'values' => []]);
    $this->reference = (string) $this->product->getKey();
});

afterEach(fn () => HistoryFx::tearDown());

function retainedMovement(): array
{
    $test = test();

    return app(StockWriter::class)->adjust($test->reference, '5', '3', 'Two units damaged <in storage>');
}

it('reads historical balances and staff references after product and listing removal without recreating anything', function (): void {
    retainedMovement();
    DB::table('commerce_listings')->where('sellable_id', $this->reference)->delete();
    DB::table('commerce_sellables')->where('id', $this->reference)->delete();
    $before = DB::table('commerce_stock_movements')->get()->toJson();
    $rows = app(StockWriter::class)->retainedHistory();
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray([
        'sku' => 'BOARD', 'productReference' => $this->reference, 'beforeStock' => '5', 'afterStock' => '3',
        'quantity' => null, 'kind' => 'manual', 'reason' => 'Two units damaged <in storage>', 'actorId' => (string) $this->owner->getKey(),
    ])->and($rows[0])->not->toHaveKeys(['email', 'name', 'billing', 'actorType'])
        ->and(DB::table('commerce_stock_movements')->get()->toJson())->toBe($before)
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(DB::table('commerce_listings')->count())->toBe(0);
});

it('keeps historical SKU and product filters exact even when the reference now names a foreign product', function (): void {
    retainedMovement();
    DB::table('commerce_stock_movements')->update(['sellable_ref' => '9223372036854775807']);
    $writer = app(StockWriter::class);
    expect($writer->retainedHistory(['sku' => ' board ']))->toHaveCount(1)
        ->and($writer->retainedHistory(['sku' => 'BOAR']))->toBe([])
        ->and($writer->retainedHistory(['product' => '9223372036854775807']))->toHaveCount(1)
        ->and($writer->retainedHistory(['product' => $this->reference]))->toBe([])
        ->and($writer->retainedHistory(['sku' => 'OTHER', 'product' => '9223372036854775807']))->toBe([]);
});

it('filters UTC calendar dates inclusively and paginates in stable time and ID order', function (): void {
    retainedMovement();
    $row = (array) DB::table('commerce_stock_movements')->first();
    DB::table('commerce_stock_movements')->update(['created_at' => '2026-10-16 00:00:00']);
    unset($row['id']);
    foreach (range(1, 28) as $i) {
        DB::table('commerce_stock_movements')->insert([...$row, 'reference' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT),
            'created_at' => '2026-10-17 23:59:59']);
    }
    $writer = app(StockWriter::class);
    expect($writer->retainedHistory(['from' => '2026-10-16', 'to' => '2026-10-16']))->toHaveCount(1)
        ->and($writer->retainedHistory(['from' => '2026-10-18']))->toBe([]);
    $first = $writer->retainedHistory(['from' => '2026-10-17', 'to' => '2026-10-17'], 0, 26);
    $next = $writer->retainedHistory(['from' => '2026-10-17', 'to' => '2026-10-17'], 25, 26);
    expect($first)->toHaveCount(26)->and($next)->toHaveCount(3)
        ->and($first[25]['reference'])->toBe($next[0]['reference']);
    $page = app(StockHistory::class);
    $page->mount();
    expect($page->movements()->items())->toHaveCount(25)->and($page->movements()->hasMorePages())->toBeTrue();
});

it('reads all org sites for owners without requiring a current product schema', function (): void {
    retainedMovement();
    $sibling = Site::create(['handle' => 'other', 'slug' => 'history-other', 'name' => 'Other']);
    app(Context::class)->setSite($sibling);
    expect(app(StockWriter::class)->retainedHistory())->toHaveCount(1);
});

it('does not reveal foreign org movements even when their planted reference matches this product', function (): void {
    retainedMovement();
    $row = (array) DB::table('commerce_stock_movements')->first();
    unset($row['id']);
    $originalSite = $this->site;
    $foreign = HistoryFx::org('history-foreign');
    $foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'history-foreign-site', 'name' => 'Foreign']);
    DB::table('commerce_stock_movements')->insert([...$row, 'org_id' => $foreign->id, 'site_id' => $foreignSite->id,
        'sku' => 'FOREIGN-SECRET', 'reference' => str_repeat('f', 32)]);
    app(Context::class)->setSite($originalSite);
    expect(app(StockWriter::class)->retainedHistory())->toHaveCount(1)
        ->and(app(StockWriter::class)->retainedHistory(['sku' => 'FOREIGN-SECRET']))->toBe([]);
});

it('refuses a planted movement whose site belongs to another org', function (): void {
    retainedMovement();
    $originalSite = $this->site;
    HistoryFx::org('history-foreign');
    $foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'history-foreign-site', 'name' => 'Foreign']);
    app(Context::class)->setSite($originalSite);
    DB::table('commerce_stock_movements')->update(['site_id' => $foreignSite->id]);
    expect(fn () => app(StockWriter::class)->retainedHistory())->toThrow(StockRefused::class);
});

it('refuses product editors on every history page read and filter action', function (): void {
    retainedMovement();
    $page = app(StockHistory::class);
    $page->mount();
    $member = HistoryFx::member(owner: false, email: 'editor@history.test');
    $role = Role::create(['handle' => 'history-editor', 'name' => 'Editor']);
    foreach (['view', 'update'] as $action) {
        $role->grant(Permissions::forEntryType('product', $action));
    }
    $role->assignTo($member->getKey());
    Permissions::forget();
    expect(StockResource::canAccess())->toBeTrue()->and(app(StockWriter::class)->canBrowseHistory())->toBeFalse();
    foreach (['mount', 'movements', 'applyFilters', 'clearFilters'] as $action) {
        expect(fn () => $page->{$action}())->toThrow(HttpException::class);
    }
    expect(fn () => app(StockWriter::class)->retainedHistory())->toThrow(StockRefused::class);
});

it('refuses stale owner authority or module readiness', function (string $loss): void {
    retainedMovement();
    match ($loss) {
        'logout' => Auth::guard('web')->logout(),
        'role' => DB::table('role_user')->delete(),
        'membership' => DB::table('org_user')->delete(),
        'module' => DB::table('modules')->update(['is_enabled' => false]),
        'version' => DB::table('modules')->update(['version' => 'old']),
    };
    expect(app(StockWriter::class)->canBrowseHistory())->toBeFalse()
        ->and(fn () => app(StockWriter::class)->retainedHistory())->toThrow(StockRefused::class);
})->with(['logout', 'role', 'membership', 'module', 'version']);

it('refuses malformed or unbounded history filters before reading the ledger', function (array $filters): void {
    expect(fn () => app(StockWriter::class)->retainedHistory($filters))->toThrow(StockRefused::class);
})->with([
    [['sku' => '%']], [['sku' => ['unsafe']]], [['product' => '01']], [['product' => '9223372036854775808']],
    [['from' => '2026-02-30']], [['to' => '2026-2-1']], [['from' => '2026-10-17', 'to' => '2026-10-16']],
    [['from' => ['unsafe']]], [['extra' => 'ignored?']],
]);

it('refuses unbounded page arguments', function (int $offset, int $limit): void {
    expect(fn () => app(StockWriter::class)->retainedHistory([], $offset, $limit))->toThrow(StockRefused::class);
})->with([[-1, 26], [250000, 26], [0, 0], [0, 27]]);

it('keeps applied filters after invalid input and clears them explicitly', function (): void {
    retainedMovement();
    $page = app(StockHistory::class);
    $page->mount();
    $page->sku = ' board ';
    $page->applyFilters();
    expect($page->filters['sku'])->toBe('BOARD')->and($page->movements()->items())->toHaveCount(1);
    $page->from = 'bad';
    $page->applyFilters();
    expect($page->filters['sku'])->toBe('BOARD')->and($page->getErrorBag()->has('history'))->toBeTrue();
    $page->clearFilters();
    expect($page->filters)->toBe([])->and($page->sku)->toBe('')->and($page->getErrorBag()->all())->toBe([]);
});

it('projects paid and free order allocation provenance without customer or provider data', function (string $kind): void {
    retainedMovement();
    DB::table('commerce_stock_movements')->update(['kind' => $kind, 'before_stock' => null, 'after_stock' => null,
        'quantity' => 2, 'actor_id' => null, 'actor_type' => null, 'order_reference' => str_repeat('b', 32), 'line_reference' => 42]);
    $row = app(StockWriter::class)->retainedHistory()[0];
    expect($row)->toMatchArray(['kind' => $kind, 'beforeStock' => null, 'afterStock' => null, 'quantity' => 2,
        'actorId' => null, 'orderReference' => str_repeat('b', 32), 'lineReference' => '42'])
        ->and($row)->not->toHaveKeys(['reader_id', 'customer_email', 'provider_charge_id', 'actorType']);
})->with(['sale', 'free_order']);

it('uses Org-leading historical time and exact-filter indexes on the real migrated schema', function (): void {
    $indexes = Schema::getIndexes('commerce_stock_movements');
    $columns = array_column($indexes, 'columns');
    expect($columns)->toContain(['org_id', 'created_at', 'id'], ['org_id', 'sku', 'created_at', 'id'],
        ['org_id', 'sellable_ref', 'created_at', 'id']);
});
