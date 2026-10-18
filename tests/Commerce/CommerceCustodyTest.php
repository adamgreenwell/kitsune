<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\CommerceCustody;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;

it('keeps a disabled commerce receipt and another orgs retained or trashed data', function (string $table, bool $trashed, bool $context): void {
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    $other = Org::create(['name' => 'Other organisation', 'slug' => 'other-organisation']);
    DB::table($table)->insert([
        'org_id' => $other->id,
        'payload' => 'confidential record that must not appear in a refusal',
        'deleted_at' => $trashed ? now() : null,
    ]);

    if ($context) {
        app(Context::class)->setOrg(Org::create(['name' => 'Current organisation', 'slug' => 'current-organisation']));
    } else {
        app(Context::class)->forget();
    }

    expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))
        ->toThrow(RuntimeException::class, 'kitsune/commerce still holds data');

    expect(Schema::hasTable($table))->toBeTrue()
        ->and(DB::table($table)->count())->toBe(1)
        ->and(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeTrue();
})->with([
    'another org with context' => ['commerce_fixture_kept', false, true],
    'another org with no context' => ['commerce_fixture_kept', false, false],
    'trash' => ['commerce_fixture_kept', true, false],
    'unknown future table' => ['commerce_fixture_future', false, false],
]);

it('does not claim a different packages table merely because commerce appears in its name', function (): void {
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    DB::table('fixture_commerce_unowned')->insert(['org_id' => 1, 'payload' => 'someone else owns this']);

    expect(ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))->toContain('Uninstalled')
        ->and(DB::table('fixture_commerce_unowned')->count())->toBe(1)
        ->and(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeFalse();
});

it('can uninstall after the operator resolves the last retained row', function (): void {
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    DB::table('commerce_fixture_kept')->insert(['org_id' => 1, 'payload' => 'kept']);

    expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))
        ->toThrow(RuntimeException::class, 'still holds data');

    DB::table('commerce_fixture_kept')->delete();

    expect(ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))->toContain('Uninstalled')
        ->and(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeFalse();
});

it('refuses teardown on a failed custody query without deleting the receipt', function (): void {
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    $connection = (new Module)->getConnection();
    $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
    $original = $callbacks->getValue($connection);

    try {
        $connection->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'commerce_fixture_kept')) {
                throw new RuntimeException('Custody could not be read.');
            }
        });

        expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))
            ->toThrow(RuntimeException::class, 'Custody could not be read.');
    } finally {
        $callbacks->setValue($connection, $original);
    }

    expect(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeTrue()
        ->and(Schema::hasTable('commerce_fixture_kept'))->toBeTrue();
});

it('accounts for the connection prefix while looking for owned tables', function (): void {
    // A separate database lets this structural probe run without DDL in the test wrapper on any engine leg.
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'host_', ['driver' => 'sqlite']);
    $connection->getSchemaBuilder()->create('commerce_future', function (Blueprint $table): void {
        $table->id();
    });
    $connection->table('commerce_future')->insert(['id' => 1]);

    expect(fn () => CommerceCustody::refuseKeptRows($connection))
        ->toThrow(RuntimeException::class, 'still holds data');
});

it('does not overlook a prefix whose case the database folds', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'host_', ['driver' => 'sqlite']);
    $connection->getSchemaBuilder()->create('commerce_future', function (Blueprint $table): void {
        $table->id();
    });
    $connection->table('commerce_future')->insert(['id' => 1]);
    $connection->setTablePrefix('Host_');

    expect($connection->table('commerce_future')->useWritePdo()->exists())->toBeTrue();
    expect(fn () => CommerceCustody::refuseKeptRows($connection))
        ->toThrow(RuntimeException::class, 'still holds data');
});

it('refuses a failed schema discovery without deleting retained data or the receipt', function (): void {
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    DB::table('commerce_fixture_kept')->insert(['org_id' => 1, 'payload' => 'retained']);
    $connection = (new Module)->getConnection();
    $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
    $original = $callbacks->getValue($connection);
    $queries = [];

    try {
        // Capture the actual driver's metadata statement rather than assume SQLite's catalog spelling.
        $connection->beforeExecuting(function (string $query) use (&$queries): void {
            $queries[] = $query;
        });
        $schema = $connection->getSchemaBuilder();
        $schema->getTables($schema->getCurrentSchemaListing());
        $metadata = $queries[array_key_last($queries)];
        $callbacks->setValue($connection, $original);
        $connection->beforeExecuting(function (string $query) use ($metadata): void {
            if ($query === $metadata) {
                throw new RuntimeException('Schema discovery failed.');
            }
        });

        expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))
            ->toThrow(RuntimeException::class, 'Schema discovery failed.');
    } finally {
        $callbacks->setValue($connection, $original);
    }

    expect(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeTrue()
        ->and(DB::table('commerce_fixture_kept')->count())->toBe(1);
});

it('reads the write connection rather than an empty replica', function (): void {
    $primary = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $replica = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);

    foreach ([$primary, $replica] as $connection) {
        $connection->getSchemaBuilder()->create('commerce_future', function (Blueprint $table): void {
            $table->id();
        });
    }

    $primary->table('commerce_future')->insert(['id' => 1]);
    $primary->setReadPdo($replica->getPdo());

    expect($primary->table('commerce_future')->exists())->toBeFalse();
    expect(fn () => CommerceCustody::refuseKeptRows($primary))
        ->toThrow(RuntimeException::class, 'still holds data');
});

it('retains refusal for unmeasured non-ASCII setting two before discovery or DDL', function (): void {
    $connection = Mockery::mock(MySqlConnection::class);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn(Mockery::mock(MySqlBuilder::class));
    $connection->shouldReceive('getTablePrefix')->once()->andReturn('İ_');
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectFromWriteConnection')->once()->with('select @@lower_case_table_names as folding')
        ->andReturn([(object) ['folding' => '2']]);
    expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'cannot safely inspect a non-ASCII table prefix');
});
