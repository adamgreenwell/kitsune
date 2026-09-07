<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Schema\SchemaManager;

/*
 * The repair path for schema drift. DDL implicitly commits on MySQL, so a
 * field storage write and its schema change cannot be one transaction — this
 * command is how the two are brought back into agreement.
 *
 * Same teardown caveat as SchemaManagerTest: this file alters the real
 * `entries` table, so it cleans up after itself rather than trusting a
 * rollback that the DDL has already broken.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Sync', 'slug' => 'sync-org']);
});

afterEach(function (): void {
    $driver = DriverFactory::for(DB::connection());

    // Index names checked first: MySQL's DROP INDEX has no IF EXISTS, and a
    // test that dropped one itself would break the teardown for every test
    // after it — the same defect this file covers in the code.
    $indexes = array_map(
        static fn (array $index): string => strtolower((string) $index['name']),
        Schema::getIndexes('entries'),
    );

    foreach (Schema::getColumnListing('entries') as $column) {
        if (! str_starts_with($column, 'idx_')) {
            continue;
        }

        if (in_array(strtolower($column.'_site_idx'), $indexes, true)) {
            DB::statement($driver->dropIndexSql('entries', $column.'_site_idx'));
        }

        DB::statement($driver->dropGeneratedColumnSql('entries', $column));
    }

    DB::table('field_storage')->delete();
    DB::table('orgs')->delete();
});

function indexedField(int $orgId, string $handle, string $type = 'number'): FieldStorage
{
    return FieldStorage::create([
        'org_id' => $orgId,
        'handle' => $handle,
        'type' => $type,
        'pii_class' => 'none',
        'cardinality' => 1,
        'is_indexed' => true,
    ]);
}

it('reports without changing anything, because a schema change can lock the table', function (): void {
    indexedField($this->org->id, 'price');

    $this->artisan('kitsune:schema-sync')
        ->expectsOutputToContain('idx_price__decimal12_2')
        ->expectsOutputToContain('Re-run with --force')
        ->assertSuccessful();

    // The whole point of the dry run.
    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
});

it('says how many rows want a column, since one column serves many', function (): void {
    $second = Org::create(['name' => 'Other', 'slug' => 'sync-other']);
    indexedField($this->org->id, 'price');
    indexedField($second->id, 'price');

    // ADR-028: "drop idx_price__decimal12_2" is only safe to read alongside how
    // many orgs still project to it.
    $this->artisan('kitsune:schema-sync')
        ->expectsOutputToContain('wanted by 2 field storage row(s)')
        ->assertSuccessful();
});

it('applies the change under --force', function (): void {
    indexedField($this->org->id, 'price');

    $this->artisan('kitsune:schema-sync', ['--force' => true])
        ->expectsOutputToContain('+ idx_price__decimal12_2')
        ->expectsOutputToContain('1 change(s) applied')
        ->assertSuccessful();

    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
});

it('drops a column no row asks for any more', function (): void {
    $storage = indexedField($this->org->id, 'price');
    app(SchemaManager::class)->index($storage);
    $storage->delete();

    $this->artisan('kitsune:schema-sync', ['--force' => true])
        ->expectsOutputToContain('- idx_price__decimal12_2')
        ->assertSuccessful();

    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
});

it('reports clean when nothing has drifted', function (): void {
    $this->artisan('kitsune:schema-sync')
        ->expectsOutputToContain('already in sync')
        ->assertSuccessful();
});

it('fails loudly rather than half-applying when a change is refused', function (): void {
    // Cardinality -1 cannot project to a scalar, so reconcile() throws
    // partway through. Reporting success here would be the worst outcome:
    // an operator believing the schema is in sync when it is not.
    FieldStorage::create([
        'org_id' => $this->org->id,
        'handle' => 'tags',
        'type' => 'text',
        'pii_class' => 'none',
        'cardinality' => -1,
        'is_indexed' => true,
    ]);

    $this->artisan('kitsune:schema-sync', ['--force' => true])
        ->expectsOutputToContain('cannot be projected to a scalar')
        ->assertFailed();
});

it('reports a column whose index is missing, rather than saying it is in sync', function (): void {
    // The column and the index are separate statements, so a column can be
    // present with no index. Reporting on columns alone said "already in
    // sync" while every query scanned the projection.
    $storage = indexedField($this->org->id, 'price');
    app(SchemaManager::class)->index($storage);

    DB::statement(DriverFactory::for(DB::connection())->dropIndexSql('entries', $storage->generatedIndexName()));

    $this->artisan('kitsune:schema-sync')
        ->expectsOutputToContain('index missing')
        ->expectsOutputToContain('Re-run with --force')
        ->assertSuccessful();
});

it('repairs that under --force', function (): void {
    $storage = indexedField($this->org->id, 'price');
    app(SchemaManager::class)->index($storage);

    DB::statement(DriverFactory::for(DB::connection())->dropIndexSql('entries', $storage->generatedIndexName()));

    $this->artisan('kitsune:schema-sync', ['--force' => true])->assertSuccessful();

    $names = array_map(
        static fn (array $i): string => strtolower((string) $i['name']),
        Schema::getIndexes('entries'),
    );

    expect($names)->toContain(strtolower($storage->generatedIndexName()));
});
