<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-006: one table, real indexes on the fields that need them. This is the
 * mechanism that avoids Drupal's join explosion — the 27-join, 697-second
 * production query that came from a table per field.
 *
 * This file alters the real `entries` table, and DDL implicitly commits on
 * MySQL — so RefreshDatabase's rollback is already broken by the time each
 * test ends. The teardown below therefore removes both the columns and the
 * rows itself rather than relying on it. Getting this wrong once already
 * turned 52 passes into 42 failures on the engine matrix.
 */

beforeEach(function (): void {
    $this->manager = new SchemaManager(new FieldTypeRegistry);

    $this->orgA = Org::create(['name' => 'A', 'slug' => 'schema-a']);
    $this->orgB = Org::create(['name' => 'B', 'slug' => 'schema-b']);

    app(Context::class)->setOrg($this->orgA);
    $this->site = Site::create(['org_id' => $this->orgA->id, 'handle' => 'main', 'slug' => 'schema-a-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create(['org_id' => $this->orgA->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
});

afterEach(function (): void {
    $driver = DriverFactory::for(DB::connection());

    foreach (Schema::getColumnListing('entries') as $column) {
        if (str_starts_with($column, 'idx_')) {
            DB::statement($driver->dropIndexSql('entries', $column.'_site_idx'));
            DB::statement($driver->dropGeneratedColumnSql('entries', $column));
        }
    }

    // Raw deletes, in dependency order: global scopes and soft deletes would
    // both leave rows behind, and there is no transaction left to roll back.
    foreach ([
        'entry_relations', 'entry_revisions', 'entry_type_availability',
        'entries', 'fields', 'field_storage', 'entry_types', 'sites',
        'site_groups', 'orgs',
    ] as $table) {
        DB::table($table)->delete();
    }

    app(Context::class)->forget();
});

function storageFor(string $handle, string $type, array $attrs = []): FieldStorage
{
    return FieldStorage::create(array_merge([
        'handle' => $handle,
        'type' => $type,
        'pii_class' => 'none',
        'cardinality' => 1,
    ], $attrs));
}

it('creates a generated column and queries through it', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $this->manager->index($storage);

    expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue();

    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Cheap', 'values' => ['price' => 10]]);
    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Dear', 'values' => ['price' => 900]]);

    // Filtering on the projected scalar rather than on JSON is the entire
    // point of the mechanism.
    expect(Entry::where('idx_price__number', '<', 100)->count())->toBe(1);
});

it('drops the index before the column, which SQLite requires', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
    $this->manager->index($storage);

    $this->manager->dropIndex($storage);

    expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeFalse();
});

it('reconciles from the is_indexed flag', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $this->manager->sync($storage);
    expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue();

    $storage->update(['is_indexed' => false]);
    $this->manager->sync($storage);
    expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeFalse();
});

it('is idempotent, so a repeated sync is harmless', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $this->manager->index($storage);
    $this->manager->index($storage);

    expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue();
});

/*
 * ADR-028. `entries` is one table shared by every org, and field_storage is
 * UNIQUE (org_id, handle) — so two orgs may each define `price`. Naming the
 * column after the handle alone made one org's schema change visible in
 * another org's queries, silently.
 */
describe('a column belongs to its projection, not to an org (ADR-028)', function (): void {
    it('gives orgs that disagree on type separate columns', function (): void {
        $a = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $b = storageFor('price', 'text', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->index($a);
        $this->manager->index($b);

        // Without this, org B would be filtering on a column that casts its
        // strings to DECIMAL — wrong answers, no error.
        expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue()
            ->and(Schema::hasColumn('entries', 'idx_price__text'))->toBeTrue();
    });

    it('shares one column between orgs that project identically', function (): void {
        $a = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $b = storageFor('price', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->index($a);
        $this->manager->index($b);

        // The expression is byte-identical, so this is deduplication rather
        // than a conflict: one column, not two.
        $generated = array_filter(
            Schema::getColumnListing('entries'),
            static fn (string $c): bool => str_starts_with($c, 'idx_'),
        );

        expect($generated)->toHaveCount(1);
    });

    it('will not let one org drop a column another org still queries', function (): void {
        $a = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $b = storageFor('price', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->index($a);
        $this->manager->index($b);

        $a->update(['is_indexed' => false]);
        $this->manager->sync($a);

        // Cross-org action at a distance is the defect class ADR-021 says has
        // no framework safety net. This is the safety net.
        expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue();

        $b->update(['is_indexed' => false]);
        $this->manager->sync($b);

        expect(Schema::hasColumn('entries', 'idx_price__number'))->toBeFalse();
    });
});

describe('refusing to index what cannot be indexed', function (): void {
    it('refuses a multi-value field', function (): void {
        // A JSON array cannot project to a scalar column.
        $storage = storageFor('tags', 'text', ['cardinality' => -1, 'is_indexed' => true]);

        expect(fn () => $this->manager->index($storage))
            ->toThrow(RuntimeException::class, 'cannot be projected to a scalar');
    });

    it('refuses a relational field, which entry_relations already indexes', function (): void {
        $storage = storageFor('author', 'relation', ['is_indexed' => true]);

        expect(fn () => $this->manager->index($storage))
            ->toThrow(RuntimeException::class, 'indexed by entry_relations');
    });

    it('refuses a promoted field, which is already a real column', function (): void {
        $storage = storageFor('slug', 'slug', ['is_indexed' => true]);

        expect(fn () => $this->manager->index($storage))
            ->toThrow(RuntimeException::class, 'already a real column');
    });

    it('refuses a type that is not indexable at all', function (): void {
        $storage = storageFor('body', 'rich_text', ['is_indexed' => true]);

        expect(fn () => $this->manager->index($storage))
            ->toThrow(RuntimeException::class, 'not indexable');
    });
});

describe('handles have to survive being SQL identifiers (ADR-028)', function (): void {
    it('refuses a handle longer than an identifier can carry', function (): void {
        expect(fn () => storageFor(str_repeat('a', FieldStorage::MAX_HANDLE_LENGTH + 1), 'number'))
            ->toThrow(RuntimeException::class, 'exceeds 40 characters');
    });

    it('refuses a doubled underscore, which is the separator', function (): void {
        // Otherwise `idx_a__b__c` is ambiguous between handle `a` type `b__c`
        // and handle `a__b` type `c`.
        expect(fn () => storageFor('unit__price', 'number'))
            ->toThrow(RuntimeException::class, 'lowercase snake_case');
    });

    it('refuses an uppercase handle', function (): void {
        expect(fn () => storageFor('unitPrice', 'number'))
            ->toThrow(RuntimeException::class, 'lowercase snake_case');
    });

    it('accepts an ordinary snake_case handle', function (): void {
        expect(storageFor('unit_price', 'number')->generatedColumnName())
            ->toBe('idx_unit_price__number');
    });
});

it('enforces the cap on the shared table, counting columns rather than rows', function (): void {
    // Without a cap this is a noisy-neighbour incident on shared
    // infrastructure, not just a slow page.
    for ($i = 0; $i < SchemaManager::MAX_GENERATED_COLUMNS; $i++) {
        $this->manager->index(storageFor("filler_{$i}", 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));
    }

    $storage = storageFor('one_too_many', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    expect(fn () => $this->manager->index($storage))
        ->toThrow(RuntimeException::class, 'Generated column limit reached');
});

it('does not count a column it is about to share against the cap', function (): void {
    for ($i = 0; $i < SchemaManager::MAX_GENERATED_COLUMNS; $i++) {
        $this->manager->index(storageFor("filler_{$i}", 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));
    }

    // Org B adopting an existing projection adds no column, so the cap must
    // not refuse it.
    $shared = storageFor('filler_0', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

    expect(fn () => $this->manager->index($shared))->not->toThrow(RuntimeException::class);
});

describe('reconcile repairs drift', function (): void {
    it('adds a column whose row says it should exist', function (): void {
        storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

        $result = $this->manager->reconcile();

        expect($result['added'])->toBe(['idx_price__number'])
            ->and(Schema::hasColumn('entries', 'idx_price__number'))->toBeTrue();
    });

    it('drops a column no row asks for any more', function (): void {
        $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $this->manager->index($storage);

        // The row goes away without a matching drop — a restore from a dump
        // taken mid-change looks exactly like this.
        $storage->delete();

        $result = $this->manager->reconcile();

        expect($result['dropped'])->toBe(['idx_price__number'])
            ->and(Schema::hasColumn('entries', 'idx_price__number'))->toBeFalse();
    });

    it('changes nothing when there is nothing to change', function (): void {
        $this->manager->index(storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));

        expect($this->manager->reconcile())->toBe(['added' => [], 'dropped' => []]);
    });
});
