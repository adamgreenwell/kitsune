<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartReaderData;

beforeEach(function (): void {
    // Separate in-memory primary, including a prefix: adapter verification never changes the engine suite's schema.
    $this->connectionName = 'cart_privacy_probe';
    config(['database.connections.'.$this->connectionName => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'privacy_', 'foreign_key_constraints' => true]]);
    $this->connection = DB::connection($this->connectionName);
    $schema = $this->connection->getSchemaBuilder();
    $schema->create('orgs', static fn (Blueprint $table) => $table->id());
    $schema->create('sites', static function (Blueprint $table): void {
        $table->id();
        $table->integer('org_id');
        $table->string('handle');
    });
    $schema->create('migrations', static function (Blueprint $table): void {
        $table->id();
        $table->string('migration');
    });
    $schema->create('commerce_carts', static function (Blueprint $table): void {
        $table->id();
        $table->integer('org_id');
        $table->integer('site_id');
        $table->string('reader_id')->nullable();
        $table->string('owner_key');
        $table->integer('version');
        $table->dateTime('expires_at');
        $table->timestamps();
    });
    $schema->create('commerce_cart_lines', static function (Blueprint $table): void {
        $table->id();
        $table->integer('org_id');
        $table->integer('site_id');
        $table->integer('cart_id');
        $table->integer('listing_id')->nullable();
        $table->integer('quantity');
        $table->timestamps();
    });
    $this->connection->table('orgs')->insert([['id' => 1], ['id' => 2]]);
    $this->connection->table('sites')->insert([['id' => 1, 'org_id' => 1, 'handle' => 'main'], ['id' => 2, 'org_id' => 1, 'handle' => 'sibling'], ['id' => 3, 'org_id' => 2, 'handle' => 'rival']]);
    $this->adapter = new CartReaderData;
});

afterEach(function (): void {
    DB::purge($this->connectionName);
    config(['database.connections.'.$this->connectionName => null]);
});

function privacyCart(object $test, int $site = 1, int $org = 1, ?string $reader = '81234981'): int
{
    return $test->connection->table('commerce_carts')->insertGetId(['org_id' => $org, 'site_id' => $site, 'reader_id' => $reader,
        'owner_key' => $reader === null ? CartOwner::anonymousKey(str_repeat('a', 64)) : CartOwner::readerKey($reader),
        'version' => 3, 'expires_at' => '2020-01-01 12:00:00', 'created_at' => '2019-12-01 12:00:00', 'updated_at' => '2019-12-02 12:00:00']);
}

function privacyLine(object $test, int $cart, int $site = 1, int $org = 1, ?int $listing = 4): int
{
    return $test->connection->table('commerce_cart_lines')->insertGetId(['org_id' => $org, 'site_id' => $site, 'cart_id' => $cart,
        'listing_id' => $listing, 'quantity' => 2, 'created_at' => '2019-12-01 12:00:00', 'updated_at' => '2019-12-02 12:00:00']);
}

it('exports expired retained carts on every site of one org with an explicit secret-free projection', function (): void {
    $main = privacyCart($this);
    privacyLine($this, $main);
    privacyLine($this, $main, listing: null);
    privacyCart($this, site: 2);
    privacyCart($this, site: 3, org: 2);
    privacyCart($this, reader: 'other-reader');
    privacyCart($this, reader: null);
    $export = $this->adapter->export($this->connection, 1, '81234981');

    expect($export['carts'])->toHaveCount(2)->and(array_column($export['carts'], 'site'))->toBe(['main', 'sibling'])
        ->and($export['carts'][0])->toMatchArray(['version' => 3, 'expires_at' => '2020-01-01T12:00:00Z', 'created_at' => '2019-12-01T12:00:00Z'])
        ->and($export['carts'][0]['lines'])->toHaveCount(2)->and(array_column($export['carts'][0]['lines'], 'listing'))->toBe(['4', null])
        ->and(json_encode($export))->not->toContain('81234981', 'owner_key', 'reader_id', CartOwner::readerKey('81234981'), 'other-reader');
});

it('erases only checked reader carts and their lines even without a database cascade', function (): void {
    $main = privacyCart($this);
    privacyLine($this, $main);
    $sibling = privacyCart($this, site: 2);
    privacyLine($this, $sibling, site: 2);
    $rival = privacyCart($this, site: 3, org: 2);
    privacyLine($this, $rival, site: 3, org: 2);
    $anonymous = privacyCart($this, reader: null);

    expect($this->connection->transaction(fn () => $this->adapter->erase($this->connection, 1, '81234981')))->toBe(2)
        ->and($this->connection->table('commerce_carts')->orderBy('id')->pluck('id')->all())->toBe([$rival, $anonymous])
        ->and($this->connection->table('commerce_cart_lines')->pluck('cart_id')->all())->toBe([$rival]);
});

it('refuses inconsistent owner, site or line stamps before erasing any personal rows', function (string $corruption): void {
    $cart = privacyCart($this);
    $line = privacyLine($this, $cart);

    match ($corruption) {
        'owner hash' => $this->connection->table('commerce_carts')->where('id', $cart)->update(['owner_key' => str_repeat('b', 64)]),
        'reader bytes' => $this->connection->table('commerce_carts')->where('id', $cart)->update(['reader_id' => 'other-reader']),
        'site org' => $this->connection->table('commerce_carts')->where('id', $cart)->update(['site_id' => 3]),
        'line org' => $this->connection->table('commerce_cart_lines')->where('id', $line)->update(['org_id' => 2]),
        'line site' => $this->connection->table('commerce_cart_lines')->where('id', $line)->update(['site_id' => 2]),
        'line quantity' => $this->connection->table('commerce_cart_lines')->where('id', $line)->update(['quantity' => 100]),
    };

    expect(fn () => $this->adapter->export($this->connection, 1, '81234981'))->toThrow(RuntimeException::class, 'inconsistent')
        ->and(fn () => $this->connection->transaction(fn () => $this->adapter->erase($this->connection, 1, '81234981')))->toThrow(RuntimeException::class, 'inconsistent')
        ->and($this->connection->table('commerce_carts')->count())->toBe(1)->and($this->connection->table('commerce_cart_lines')->count())->toBe(1);
})->with(['owner hash', 'reader bytes', 'site org', 'line org', 'line site', 'line quantity']);

it('accepts a legitimate pre-cart schema but refuses recorded or partial disappearance', function (string $shape): void {
    $schema = $this->connection->getSchemaBuilder();
    $schema->drop('commerce_cart_lines');

    if ($shape !== 'one absent') {
        $schema->drop('commerce_carts');
    }

    if ($shape === 'recorded absent') {
        $this->connection->table('migrations')->insert(['migration' => '0001_01_01_000203_create_commerce_carts']);
    }

    if ($shape === 'pre-cart') {
        expect($this->adapter->export($this->connection, 1, '81234981'))->toBe(['carts' => []])
            ->and($this->adapter->erase($this->connection, 1, '81234981'))->toBe(0);
    } else {
        expect(fn () => $this->adapter->export($this->connection, 1, '81234981'))->toThrow(RuntimeException::class)
            ->and(fn () => $this->adapter->erase($this->connection, 1, '81234981'))->toThrow(RuntimeException::class);
    }
})->with(['pre-cart', 'recorded absent', 'one absent']);

it('refuses a malformed present schema instead of treating missing columns as an empty cart store', function (): void {
    $this->connection->getSchemaBuilder()->table('commerce_carts', static fn (Blueprint $table) => $table->dropColumn('reader_id'));

    expect(fn () => $this->adapter->export($this->connection, 1, '81234981'))->toThrow(RuntimeException::class, 'incomplete')
        ->and(fn () => $this->adapter->erase($this->connection, 1, '81234981'))->toThrow(RuntimeException::class, 'incomplete');
});

it('refuses oversized retained carts before exporting or erasing even earlier valid carts', function (): void {
    $main = privacyCart($this);
    privacyLine($this, $main);
    $oversized = privacyCart($this, site: 2);

    foreach (range(1, 21) as $i) {
        privacyLine($this, $oversized, site: 2, listing: null);
    }

    expect(fn () => $this->adapter->export($this->connection, 1, '81234981'))->toThrow(RuntimeException::class, 'inconsistent')
        ->and(fn () => $this->connection->transaction(fn () => $this->adapter->erase($this->connection, 1, '81234981')))->toThrow(RuntimeException::class, 'inconsistent')
        ->and($this->connection->table('commerce_carts')->count())->toBe(2)
        ->and($this->connection->table('commerce_cart_lines')->count())->toBe(22);
});
