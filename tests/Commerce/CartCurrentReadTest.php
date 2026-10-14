<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Catalogue\PublicCatalogue;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    Fx::boot();
    $this->org = Org::create(['slug' => 'current-cart', 'name' => 'Current cart', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'current-cart-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->product = app(ProductWriter::class)->createProduct(['sku' => 'CURRENT', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Current product', 'slug' => 'current', 'status' => 'published', 'values' => [],
    ], [], true);
    $this->listing = $this->product->listings()->firstOrFail();
    $this->request = Request::create('/cart');
    $this->request->setLaravelSession(app('session.store'));
    $this->request->session()->start();
});

afterEach(fn () => Fx::tearDown());

/**
 * SQLite has no row-lock SQL. Its annotated grammar makes the production query's lock decision observable without
 * pretending to qualify repeatable-read behavior. Server matrix legs execute their real FOR UPDATE syntax instead.
 *
 * @return list<string>
 */
function cartCurrentQueries(Closure $work): array
{
    $connection = DB::connection();
    $grammar = $connection->getQueryGrammar();

    if ($connection->getDriverName() === 'sqlite') {
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? '/* current locking read */' : '';
            }
        });
    }

    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $work();

        return array_map(static fn (array $query): string => strtolower(str_replace(['"', '`'], '', $query['query'])), $connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $connection->setQueryGrammar($grammar);
    }
}

function isCartCurrentQuery(string $sql): bool
{
    return str_contains($sql, 'for update') || str_contains($sql, '/* current locking read */');
}

it('reads the owned cart revision and every retained child through current locks inside an outer transaction', function (): void {
    $writer = app(CartWriter::class);
    $writer->add($this->request, $this->listing->getKey(), 1);
    $before = $writer->snapshot($this->request);
    $queries = cartCurrentQueries(fn () => DB::transaction(fn () => $writer->update(
        $this->request, $before['items'][0]['lineId'], 2, $before['version'],
    )));

    foreach (['commerce_carts', 'commerce_cart_lines'] as $table) {
        $reads = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select ')
            && str_contains($sql, ' from '.$table.' ')));
        expect($reads)->not->toBeEmpty();

        foreach ($reads as $sql) {
            expect(isCartCurrentQuery($sql))->toBeTrue($sql);
        }
    }

    expect($writer->snapshot($this->request)['items'][0]['quantity'])->toBe(2)
        ->and(Cart::firstOrFail()->version)->toBe($before['version'] + 1);
});

it('locks every native cart admission input while public catalogue reads keep their ordinary path', function (): void {
    $catalogue = app(PublicCatalogue::class);
    $products = [];
    $queries = cartCurrentQueries(function () use ($catalogue, &$products): void {
        $products = DB::transaction(fn () => $catalogue->forListings([(int) $this->listing->getKey()], current: true));
    });

    foreach (['modules', 'sites', 'blueprints', 'entry_types', 'entry_type_availability', 'orgs', 'commerce_listings', 'entries', 'commerce_sellables', 'fields', 'field_storage', 'entry_relations'] as $table) {
        $reads = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select ')
            && str_contains($sql, ' from '.$table.' ')));
        expect($reads)->not->toBeEmpty($table);

        foreach ($reads as $sql) {
            expect(isCartCurrentQuery($sql))->toBeTrue($sql);
            expect($sql)->not->toContain('group by', 'count(');
        }
    }

    expect($products[(int) $this->listing->getKey()])->toMatchArray(['priceMinor' => '2000', 'stock' => '5', 'held' => null, 'imageUrl' => null]);
    $ordinary = cartCurrentQueries(fn () => $catalogue->page());
    expect(array_filter($ordinary, isCartCurrentQuery(...)))->toBe([]);
});

it('keeps current required relation and cardinality admission bounded without grouped locking queries', function (string $shape): void {
    $type = ProductType::current();
    $storage = FieldStorage::query()->where('handle', 'product_images')->firstOrFail();
    Field::query()->where('entry_type_id', $type->getKey())->where('field_storage_id', $storage->getKey())->firstOrFail()->update(['is_required' => true]);
    $entry = $this->listing->entry()->firstOrFail();
    $imageType = EntryType::query()->where('handle', 'product_image')->firstOrFail();
    $image = Entry::create(['entry_type_id' => $imageType->getKey(), 'title' => 'Current relation image']);

    if ($shape !== 'missing') {
        $images = [$image->getKey()];

        if ($shape === 'overflow') {
            $images[] = Entry::create(['entry_type_id' => $imageType->getKey(), 'title' => 'Another image'])->getKey();
        }

        $entry->syncFieldRelations($storage, $images);
    }

    if ($shape === 'invalid') {
        $image->delete();
    } elseif ($shape === 'overflow') {
        DB::table('field_storage')->where('id', $storage->getKey())->update(['cardinality' => 1]);
    }

    $products = [];
    $queries = cartCurrentQueries(function () use (&$products): void {
        $products = DB::transaction(fn () => app(PublicCatalogue::class)->forListings([(int) $this->listing->getKey()], current: true));
    });
    expect($products)->toHaveCount($shape === 'valid' ? 1 : 0);
    $relations = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, ' from entry_relations ')));
    expect($relations)->not->toBeEmpty();

    foreach ($relations as $sql) {
        expect($sql)->toContain('limit 1')->not->toContain('group by', 'count(');
        expect(isCartCurrentQuery($sql))->toBeTrue($sql);

        if (str_contains($sql, 'not exists (select')) {
            expect(substr_count($sql, 'for update') + substr_count($sql, '/* current locking read */'))->toBe(2);
        }
    }
})->with(['valid', 'missing', 'invalid', 'overflow']);
