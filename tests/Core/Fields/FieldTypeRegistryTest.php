<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Schema\Drivers\MySqlDriver;
use Kitsune\Core\Schema\Drivers\PostgresDriver;
use Kitsune\Core\Schema\Drivers\SqliteDriver;

/*
 * These tests hold every registered type to the whole contract, so a new type
 * cannot be added that edits beautifully and cannot be queried.
 *
 * ⚠️ The quotation that used to head this block — "a type that answers three of
 * four is not shippable" — has been removed from field-types.md, because all
 * twelve shipped types answered exactly three: there was no UI method on the
 * interface at all. Citing an aspiration as an invariant made a real gap look
 * closed. `control()` is the UI face now (ADR-029).
 */

beforeEach(function (): void {
    $this->registry = new FieldTypeRegistry;
    $this->driver = DriverFactory::for(DB::connection());
});

function configFor(string $type, array $settings = [], int $cardinality = 1): FieldConfig
{
    $storage = new FieldStorage([
        'handle' => 'probe',
        'type' => $type,
        'cardinality' => $cardinality,
        'pii_class' => 'none',
        'settings' => $settings,
    ]);

    return new FieldConfig($storage);
}

it('registers the twelve v1.0 types', function (): void {
    // Deliberately small: every type added before the API freeze is a
    // permanent maintenance obligation.
    expect(array_keys($this->registry->all()))->toBe([
        'text', 'textarea', 'rich_text', 'number', 'boolean', 'date',
        'datetime', 'select', 'multi_select', 'relation', 'slug', 'json',
    ]);
});

it('fails closed on an unknown handle', function (): void {
    // Falling back to text would silently reinterpret stored data — a number
    // becoming text is how content gets destroyed quietly.
    expect(fn () => $this->registry->get('nope'))
        ->toThrow(RuntimeException::class, 'No field type registered');
});

it('gives every type a complete contract', function (string $handle): void {
    $type = $this->registry->get($handle);
    $config = configFor($handle);

    expect($type::label())->toBeString()->not->toBeEmpty();
    expect($type::icon())->toBeString()->not->toBeEmpty();
    expect($type->strategy())->toBeInstanceOf(StorageStrategy::class);
    expect($type->validationRules($config))->toBeArray();
    expect($type->apiSchema($config))->toBeArray()->toHaveKey('type');
    expect($type->settingsSchema())->toBeArray();
    expect($type->suggestedPiiClass())->toBeIn(['none', 'personal', 'sensitive']);
})->with(['text', 'textarea', 'rich_text', 'number', 'boolean', 'date', 'datetime', 'select', 'multi_select', 'relation', 'slug', 'json']);

it('gives every indexable type a projection every driver can render', function (): void {
    // The pairing that matters: claiming indexability without being able to
    // project to a scalar is the "edits beautifully, cannot be queried"
    // failure the contract exists to prevent.
    //
    // Rendered against ALL THREE drivers, not just the connected one. Four
    // defects hid behind checking only one: `integer` and `boolean` were
    // unindexable on MySQL, `date` and `datetime` on PostgreSQL.
    $drivers = [new PostgresDriver, new MySqlDriver, new SqliteDriver];

    foreach ($this->registry->all() as $handle => $type) {
        $projection = $type->projection(configFor($handle));

        if ($type->isIndexable() && $type->strategy() !== StorageStrategy::Promoted) {
            expect($projection)->toBeInstanceOf(Projection::class, "{$handle} claims indexable but projects to nothing");

            foreach ($drivers as $driver) {
                expect($driver->columnType($projection))
                    ->toBeString("{$handle} has no column type on {$driver->name()}");
            }

            continue;
        }

        expect($projection)->toBeNull("{$handle} is not indexable but declares a projection");
    }
});

it('round-trips values through storage', function (string $handle, mixed $input, mixed $expected): void {
    $type = $this->registry->get($handle);

    expect($type->toStorage($input, configFor($handle)))->toBe($expected);
})->with([
    'text casts to string' => ['text', 42, '42'],
    'number casts to float' => ['number', '24.99', 24.99],
    'boolean casts' => ['boolean', 1, true],
    'empty number is null, not zero' => ['number', '', null],
    'slug is slugified' => ['slug', 'Hello There World', 'hello-there-world'],
    'multi_select is always an array' => ['multi_select', 'a', ['a']],
    'relation casts ids to int' => ['relation', ['3', '4'], [3, 4]],
]);

it('knows which types cannot be multi-valued', function (): void {
    // Cardinality > 1 cannot project to a scalar column, so a type that is
    // intrinsically single-valued says so rather than being configured wrong.
    foreach (['boolean', 'select', 'multi_select', 'rich_text', 'json', 'slug'] as $handle) {
        expect($this->registry->get($handle)->supportsCardinality())->toBeFalse("{$handle}");
    }
});
