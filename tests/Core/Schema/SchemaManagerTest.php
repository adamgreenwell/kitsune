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
use Kitsune\Core\Fields\LogicalType;
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

function indexNames(): array
{
    return array_map(
        static fn (array $index): string => strtolower((string) $index['name']),
        Schema::getIndexes('entries'),
    );
}

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

    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();

    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Cheap', 'values' => ['price' => 10]]);
    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Dear', 'values' => ['price' => 900]]);

    // Filtering on the projected scalar rather than on JSON is the entire
    // point of the mechanism.
    expect(Entry::where('idx_price__decimal12_2', '<', 100)->count())->toBe(1);
});

it('drops the index before the column, which SQLite requires', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
    $this->manager->index($storage);

    $this->manager->dropIndex($storage);

    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
});

it('reconciles from the is_indexed flag', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $this->manager->sync($storage);
    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();

    $storage->update(['is_indexed' => false]);
    $this->manager->sync($storage);
    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
});

it('is idempotent, so a repeated sync is harmless', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $this->manager->index($storage);
    $this->manager->index($storage);

    expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
});

it('recreates a missing index even though its column is already there', function (): void {
    // The column and the index are two statements, and DDL implicitly commits
    // on MySQL — so the pair can half-succeed. Returning early on the column
    // alone left the index permanently missing while the dry run reported the
    // schema as in sync and every query scanned.
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
    $this->manager->index($storage);

    DB::statement(DriverFactory::for(DB::connection())->dropIndexSql('entries', $storage->generatedIndexName()));
    expect(indexNames())->not->toContain(strtolower($storage->generatedIndexName()));

    $this->manager->index($storage);

    expect(indexNames())->toContain(strtolower($storage->generatedIndexName()))
        ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
});

it('reports a missing index as drift, not as in sync', function (): void {
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
    $this->manager->index($storage);

    DB::statement(DriverFactory::for(DB::connection())->dropIndexSql('entries', $storage->generatedIndexName()));

    expect($this->manager->reconcile()['added'])->toBe(['idx_price__decimal12_2'])
        ->and(indexNames())->toContain(strtolower($storage->generatedIndexName()));
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
        expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue()
            ->and(Schema::hasColumn('entries', 'idx_price__string255'))->toBeTrue();
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
        expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();

        $b->update(['is_indexed' => false]);
        $this->manager->sync($b);

        expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
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
            ->toThrow(RuntimeException::class, 'exceeds 32 characters');
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
            ->toBe('idx_unit_price__decimal12_2');
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

        expect($result['added'])->toBe(['idx_price__decimal12_2'])
            ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
    });

    it('drops a column no row asks for any more', function (): void {
        $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $this->manager->index($storage);

        // The row goes away without a matching drop — a restore from a dump
        // taken mid-change looks exactly like this.
        $storage->delete();

        $result = $this->manager->reconcile();

        expect($result['dropped'])->toBe(['idx_price__decimal12_2'])
            ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
    });

    it('changes nothing when there is nothing to change', function (): void {
        $this->manager->index(storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));

        expect($this->manager->reconcile())->toBe(['added' => [], 'dropped' => []]);
    });
});

/*
 * ADR-028 named the column for its projection. The projection turned out to
 * depend on CONFIGURATION as well as on the field type, so the name had to
 * follow it there — two orgs configuring `number` differently would otherwise
 * have shared `idx_count__number` with incompatible column types.
 */
describe('a configured projection changes the column, not just the value', function (): void {
    it('gives an integer-formatted number an integer column', function (): void {
        // DECIMAL(12,2) is not merely imprecise here: `10000000000` is a
        // valid value both the validator and toStorage() accept, and
        // PostgreSQL then refuses the column with `numeric field overflow`.
        $storage = storageFor('count', 'number', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['format' => 'integer'],
        ]);

        expect($storage->projection()->logical)->toBe(LogicalType::Integer)
            ->and($storage->generatedColumnName())->toBe('idx_count__integer');

        $this->manager->index($storage);

        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Big', 'values' => ['count' => 10000000000]]);

        expect((int) Entry::where('title', 'Big')->value('idx_count__integer'))->toBe(10000000000);
    });

    it('keeps a decimal-formatted number on a decimal column', function (): void {
        $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

        expect($storage->generatedColumnName())->toBe('idx_price__decimal12_2');
    });

    it('separates two orgs that configured the same handle differently', function (): void {
        // The collision the signature exists to prevent.
        $a = storageFor('count', 'number', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['format' => 'integer'],
        ]);
        $b = storageFor('count', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->index($a);
        $this->manager->index($b);

        expect(Schema::hasColumn('entries', 'idx_count__integer'))->toBeTrue()
            ->and(Schema::hasColumn('entries', 'idx_count__decimal12_2'))->toBeTrue();
    });

    it('projects a wider text field at its configured width', function (): void {
        // Projecting a 400-character field through VARCHAR(255) truncates the
        // index, so two distinct values compare equal and an exact filter
        // returns the wrong rows.
        $storage = storageFor('summary', 'text', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['maxLength' => 400],
        ]);

        expect($storage->generatedColumnName())->toBe('idx_summary__string400');

        $this->manager->index($storage);

        $long = str_repeat('x', 400);
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Long', 'values' => ['summary' => $long]]);

        expect(Entry::where('title', 'Long')->value('idx_summary__string400'))->toBe($long);
    });

    it('refuses to index a string wider than an engine will key', function (): void {
        // Measured: MySQL caps an index key at 3,072 bytes, so VARCHAR(1000)
        // in utf8mb4 fails with ERROR 1071. Better to refuse with the reason
        // than to fail at ALTER TABLE, and far better than truncating.
        $storage = storageFor('essay', 'text', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['maxLength' => 5000],
        ]);

        expect(fn () => $this->manager->index($storage))
            ->toThrow(RuntimeException::class, 'cannot be indexed');
    });
});

it('drops an orphan column whose index is already gone', function (): void {
    // MySQL's DROP INDEX has no IF EXISTS and errors with 1091 when the index
    // is absent — which is exactly the half-applied state reconcile() exists
    // to repair, so dropping unconditionally meant it never reached the
    // column and the drift was permanent.
    $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
    $this->manager->index($storage);

    DB::statement(DriverFactory::for(DB::connection())->dropIndexSql('entries', $storage->generatedIndexName()));
    $storage->delete();

    expect($this->manager->reconcile()['dropped'])->toBe(['idx_price__decimal12_2'])
        ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
});

/*
 * ⚠️ The column is named for its projection SIGNATURE, and a signature is not
 * a function of the field type. Reference counting on `type` got both
 * directions wrong. Reported in review.
 */
describe('reference counting follows the column, not the field type', function (): void {
    it('will not drop a column a DIFFERENT field type shares', function (): void {
        // text(maxLength: 64) and select both project to string64 and
        // deliberately share one column. Counting by type saw two unrelated
        // rows and dropped the column the other org was still querying.
        $text = storageFor('code', 'text', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['maxLength' => 64],
        ]);
        $select = storageFor('code', 'select', [
            'org_id' => $this->orgB->id, 'is_indexed' => true, 'settings' => ['options' => ['a' => 'A']],
        ]);

        expect($text->generatedColumnName())->toBe($select->generatedColumnName());

        $this->manager->index($text);
        $this->manager->index($select);

        $text->update(['is_indexed' => false]);
        $this->manager->sync($text);

        expect(Schema::hasColumn('entries', 'idx_code__string64'))->toBeTrue();
    });

    it('DOES drop a column when the other row of the same type projects elsewhere', function (): void {
        // Two `number` rows, one integer and one decimal: same type, different
        // columns. Counting by type left an orphan behind.
        $integer = storageFor('count', 'number', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['format' => 'integer'],
        ]);
        $decimal = storageFor('count', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->index($integer);
        $this->manager->index($decimal);

        $integer->update(['is_indexed' => false]);
        $this->manager->sync($integer);

        expect(Schema::hasColumn('entries', 'idx_count__integer'))->toBeFalse()
            ->and(Schema::hasColumn('entries', 'idx_count__decimal12_2'))->toBeTrue();
    });
});

it('drops an orphan before adding, so a swap fits under the cap', function (): void {
    // With the table at the cap, adding first hits guardCap() and throws —
    // so a capacity-NEUTRAL replacement could never be repaired and --force
    // reported a failure the operator could not act on.
    for ($i = 0; $i < SchemaManager::MAX_GENERATED_COLUMNS; $i++) {
        $this->manager->index(storageFor("filler_{$i}", 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));
    }

    // One row goes away, another arrives. Net zero columns.
    FieldStorage::query()->where('handle', 'filler_0')->delete();
    storageFor('replacement', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    $result = $this->manager->reconcile();

    expect($result['dropped'])->toBe(['idx_filler_0__decimal12_2'])
        ->and($result['added'])->toBe(['idx_replacement__decimal12_2'])
        ->and(Schema::hasColumn('entries', 'idx_replacement__decimal12_2'))->toBeTrue();
});

/*
 * ADR-006: storage locks the moment data exists. The lock checked `type` and
 * `cardinality` only, so a setting that changes the PROJECTION slipped past
 * it — and those change the conversion too.
 */
describe('settings that change the projection are shape, and lock with it', function (): void {
    it('refuses to switch a locked number from decimal to integer', function (): void {
        // 1.5 would become 1, and the column would move from DECIMAL to
        // BIGINT under rows that already hold fractions.
        $storage = storageFor('price', 'number', [
            'org_id' => $this->orgA->id, 'is_locked' => true, 'settings' => ['format' => 'decimal'],
        ]);

        $storage->settings = ['format' => 'integer'];

        expect(fn () => $storage->save())
            ->toThrow(RuntimeException::class, 'the projection would move from');
    });

    it('refuses to narrow a locked text field', function (): void {
        $storage = storageFor('summary', 'text', [
            'org_id' => $this->orgA->id, 'is_locked' => true, 'settings' => ['maxLength' => 400],
        ]);

        $storage->settings = ['maxLength' => 100];

        expect(fn () => $storage->save())->toThrow(RuntimeException::class, 'is locked');
    });

    it('still allows a setting that leaves the projection alone', function (): void {
        // Presentation is safe to edit on a field holding data; only shape is
        // not. Naming the settings would have banned both.
        $storage = storageFor('price', 'number', [
            'org_id' => $this->orgA->id, 'is_locked' => true, 'settings' => ['format' => 'decimal'],
        ]);

        $storage->settings = ['format' => 'decimal', 'min' => 0];

        expect(fn () => $storage->save())->not->toThrow(RuntimeException::class);
    });

    it('leaves an unlocked field free to change', function (): void {
        $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'settings' => ['format' => 'decimal']]);

        $storage->settings = ['format' => 'integer'];

        expect(fn () => $storage->save())->not->toThrow(RuntimeException::class);
    });
});

describe('sync is safe for a field that projects to nothing', function (): void {
    it('does not throw for a type with no generated column', function (string $type): void {
        // ⚠️ dropIndex() starts by asking for the column NAME, which throws
        // for these — so the documented "call sync() after saving" flow blew
        // up on four ordinary field types and every caller would have had to
        // special-case them.
        $storage = storageFor("probe_{$type}", $type, ['org_id' => $this->orgA->id, 'is_indexed' => false]);

        expect(fn () => $this->manager->sync($storage))->not->toThrow(RuntimeException::class);
    })->with(['rich_text', 'json', 'relation', 'slug']);

    it('still indexes a type that does project', function (): void {
        $storage = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

        $this->manager->sync($storage);

        expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
    });
});

it('still REFUSES an index requested on a projection-less field', function (): void {
    // ⚠️ The no-op added for the removal path must not swallow this. Returning
    // early for every projection-less type left an `is_indexed = true` row
    // looking synchronised, and reconcile() then choked on it for the whole
    // table — one bad row poisoning the global repair command.
    $storage = storageFor('body', 'rich_text', ['org_id' => $this->orgA->id, 'is_indexed' => true]);

    expect(fn () => $this->manager->sync($storage))
        ->toThrow(RuntimeException::class, 'not indexable');
});

describe('a moved projection takes its old column with it', function (): void {
    it('drops the column a changed setting orphaned', function (): void {
        // ⚠️ Adding the new column alone left the old one and its index
        // behind — paying write overhead on every entry save and consuming
        // the cap. At the cap, a capacity-NEUTRAL replacement failed outright.
        $storage = storageFor('price', 'number', [
            'org_id' => $this->orgA->id, 'is_indexed' => true, 'settings' => ['format' => 'decimal'],
        ]);
        $this->manager->sync($storage);

        expect(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();

        $storage->update(['settings' => ['format' => 'integer']]);
        $this->manager->sync($storage);

        expect(Schema::hasColumn('entries', 'idx_price__integer'))->toBeTrue()
            ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeFalse();
    });

    it('keeps the old column when ANOTHER row still projects that way', function (): void {
        // Reference-counted like any other drop: one org moving must not take
        // another org's column with it.
        $mine = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $theirs = storageFor('price', 'number', ['org_id' => $this->orgB->id, 'is_indexed' => true]);

        $this->manager->sync($mine);
        $this->manager->sync($theirs);

        $mine->update(['settings' => ['format' => 'integer']]);
        $this->manager->sync($mine);

        expect(Schema::hasColumn('entries', 'idx_price__integer'))->toBeTrue()
            ->and(Schema::hasColumn('entries', 'idx_price__decimal12_2'))->toBeTrue();
    });

    it('lets a capacity-neutral replacement fit at the cap', function (): void {
        for ($i = 0; $i < SchemaManager::MAX_GENERATED_COLUMNS - 1; $i++) {
            $this->manager->index(storageFor("filler_{$i}", 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]));
        }

        $moving = storageFor('price', 'number', ['org_id' => $this->orgA->id, 'is_indexed' => true]);
        $this->manager->sync($moving);

        // At the cap now. Changing the projection is net zero columns, and
        // adding before dropping made it throw.
        $moving->update(['settings' => ['format' => 'integer']]);

        expect(fn () => $this->manager->sync($moving))->not->toThrow(RuntimeException::class);
        expect(Schema::hasColumn('entries', 'idx_price__integer'))->toBeTrue();
    });
});
