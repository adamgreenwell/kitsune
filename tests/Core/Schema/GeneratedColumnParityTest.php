<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Schema\DriverFactory;

/*
 * Spike #11, promoted to a standing test.
 *
 * ADR-006 stakes the storage design on generated columns over JSON, and
 * ADR-015 stakes indexing on them. All three engines diverge on syntax and
 * JSON path operators, and SQLite diverges structurally: it cannot add a
 * STORED generated column through ALTER TABLE at all.
 *
 * This runs against whichever engine DB_CONNECTION selects, so the CI matrix
 * proves parity rather than asserting it. The table deliberately uses a JSON
 * column named `values` - the real name from ADR-006, and a reserved word on
 * more than one engine.
 */

// DDL inside a test implicitly commits on MySQL, which broke
// RefreshDatabase's rollback for the scope fixtures. It is acceptable here:
// this file creates and drops its own isolated probe table and asserts
// nothing that depends on rollback, and testing ALTER TABLE is the whole
// point — it cannot be moved into a migration.
beforeEach(function (): void {
    $this->driver = DriverFactory::for(DB::connection());

    Schema::dropIfExists('parity_probe');
    Schema::create('parity_probe', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id');
        $table->json('values')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('parity_probe');
});

it('resolves a driver for the connection', function (): void {
    expect($this->driver->name())->toBeIn(['pgsql', 'mysql', 'sqlite']);
});

it('adds a generated column over a JSON path and queries through it', function (): void {
    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal)));

    DB::table('parity_probe')->insert([
        ['site_id' => 1, 'values' => json_encode(['price' => 24.99])],
        ['site_id' => 1, 'values' => json_encode(['price' => 99.50])],
        ['site_id' => 2, 'values' => json_encode(['price' => 5.00])],
    ]);

    // The whole point: filter on the projected scalar, not on JSON.
    $cheap = DB::table('parity_probe')->where('site_id', 1)->where('idx_price', '<', 50)->count();
    expect($cheap)->toBe(1);

    $value = DB::table('parity_probe')->where('site_id', 2)->value('idx_price');
    expect((float) $value)->toBe(5.0);
});

it('indexes the generated column, leading with the scope key', function (): void {
    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal)));

    // ADR-021: composite indexes lead with the model's scope key.
    DB::statement($this->driver->createIndexSql('parity_probe', 'parity_probe_site_price', 'site_id', 'idx_price'));

    DB::table('parity_probe')->insert(['site_id' => 7, 'values' => json_encode(['price' => 12.34])]);

    expect(DB::table('parity_probe')->where('site_id', 7)->where('idx_price', 12.34)->exists())->toBeTrue();
});

it('drops a generated column again', function (): void {
    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal)));
    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeTrue();

    DB::statement($this->driver->dropGeneratedColumnSql('parity_probe', 'idx_price'));
    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeFalse();
});

it('projects a text field, not only numerics', function (): void {
    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_sku', 'values', 'sku', new Projection(LogicalType::String, 64)));

    DB::table('parity_probe')->insert(['site_id' => 1, 'values' => json_encode(['sku' => 'ABC-123'])]);

    expect(trim((string) DB::table('parity_probe')->value('idx_sku')))->toBe('ABC-123');
});

it('reports the STORED/VIRTUAL difference honestly', function (): void {
    // Not cosmetic: VIRTUAL computes on read, so SQLite pays no write
    // amplification and needs no table rewrite, while the other two do.
    expect($this->driver->supportsStoredGeneratedColumns())
        ->toBe($this->driver->name() !== 'sqlite');
});

it('quotes a reserved identifier so `values` is usable as a column name', function (): void {
    $sql = $this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal));

    // `values` is reserved on MySQL and Postgres. Unquoted, this is a syntax error.
    expect($sql)->toContain($this->driver->quote('values'));
});

it('renders a decimal column type this engine accepts', function (): void {
    // MySQL accepts NUMERIC as a column type but rejects it inside CAST,
    // where it demands DECIMAL. One string cannot serve both grammars, which
    // is why columnType() and the driver's private cast spelling are separate
    // — and why `integer` was unindexable on MySQL until they were.
    // MariaDB shares MySqlDriver, so it shares its rendered types.
    $expected = in_array($this->driver->name(), ['mysql', 'mariadb'], true)
        ? 'DECIMAL(12,2)'
        : 'NUMERIC(12,2)';

    expect($this->driver->columnType(new Projection(LogicalType::Decimal)))->toBe($expected);
});

it('renders an integer column type that ALTER TABLE accepts, not a cast keyword', function (): void {
    // MySQL's cast spelling is SIGNED, which is not a column type at all.
    // Returning it from one method left every integer field unindexable there.
    expect($this->driver->columnType(new Projection(LogicalType::Integer)))
        ->not->toBe('SIGNED');
});

it('drops an indexed generated column, index first', function (): void {
    // A generated column cannot be dropped while an index references it —
    // SQLite refuses outright. The driver owns the ordering and the syntax,
    // which diverges again: MySQL scopes DROP INDEX to a table.
    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal)));
    DB::statement($this->driver->createIndexSql('parity_probe', 'parity_probe_drop_me', 'site_id', 'idx_price'));

    DB::statement($this->driver->dropIndexSql('parity_probe', 'parity_probe_drop_me'));
    DB::statement($this->driver->dropGeneratedColumnSql('parity_probe', 'idx_price'));

    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeFalse();
});

/*
 * ⚠️ The suite this file should have had from the start.
 *
 * It previously exercised `decimal` and `string` only, and four defects hid
 * behind that: `integer` and `boolean` were unindexable on MySQL (`SIGNED` is
 * not a column type, and `->>` renders a JSON boolean as the text 'true'),
 * `date` and `datetime` were unindexable on PostgreSQL (a text-to-DATE cast is
 * STABLE, and a stored generated column demands IMMUTABLE), and every type was
 * vulnerable to another org's value in the same JSON key.
 *
 * Every indexable logical type, on whichever engine DB_CONNECTION selects.
 */
describe('every logical type projects, on every engine', function (): void {
    it('round-trips a value through a generated column and an index', function (
        LogicalType $logical,
        int $precision,
        mixed $stored,
        mixed $expected,
    ): void {
        $projection = new Projection($logical, $precision);

        DB::statement($this->driver->addGeneratedColumnSql(
            'parity_probe', 'idx_v', 'values', 'v', $projection,
        ));
        DB::statement($this->driver->createIndexSql('parity_probe', 'parity_probe_v', 'site_id', 'idx_v'));

        DB::table('parity_probe')->insert(['site_id' => 1, 'values' => json_encode(['v' => $stored])]);

        $value = DB::table('parity_probe')->where('site_id', 1)->value('idx_v');

        expect(is_float($expected) ? (float) $value : (is_int($expected) ? (int) $value : trim((string) $value)))
            ->toBe($expected);
    })->with([
        'decimal' => [LogicalType::Decimal, 12, 24.99, 24.99],
        'integer' => [LogicalType::Integer, 12, 4200, 4200],
        'string' => [LogicalType::String, 64, 'ABC-123', 'ABC-123'],
        // JSON true, not 1: the two are different JSON types and MySQL says so.
        'boolean true' => [LogicalType::Boolean, 1, true, 1],
        'boolean false' => [LogicalType::Boolean, 1, false, 0],
        'date' => [LogicalType::Date, 10, '2026-01-05', '2026-01-05'],
        'datetime' => [LogicalType::DateTime, 32, '2026-01-05T03:04:05+00:00', '2026-01-05T03:04:05+00:00'],
    ]);
});

/*
 * ADR-028 amendment. `entries` is one table shared by every org, so a
 * generated column reads its JSON key from rows belonging to orgs that gave
 * the same handle a different type. Naming the column for its projection
 * stopped the query collision; it did not stop this one.
 */
describe("another org's value cannot break a projection", function (): void {
    it('creates the column even though a foreign row holds the wrong type', function (): void {
        // Org B's text price is already in the table when org A indexes.
        DB::table('parity_probe')->insert([
            ['site_id' => 1, 'values' => json_encode(['price' => 10])],
            ['site_id' => 2, 'values' => json_encode(['price' => 'contact us'])],
        ]);

        DB::statement($this->driver->addGeneratedColumnSql(
            'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
        ));

        expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeTrue();
    });

    it('projects the wrong type to NULL rather than to a wrong value', function (): void {
        DB::statement($this->driver->addGeneratedColumnSql(
            'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
        ));

        DB::table('parity_probe')->insert([
            ['site_id' => 1, 'values' => json_encode(['price' => 10])],
            ['site_id' => 2, 'values' => json_encode(['price' => 'contact us'])],
        ]);

        // ⚠️ SQLite is why NULL matters rather than merely being tidy: it
        // casts text to 0 without complaint, so org B's "contact us" would
        // have indexed as a price of zero and answered queries for it.
        expect(DB::table('parity_probe')->where('site_id', 2)->value('idx_price'))->toBeNull()
            ->and((float) DB::table('parity_probe')->where('site_id', 1)->value('idx_price'))->toBe(10.0);
    });

    it('still accepts writes from the org whose type does not match', function (): void {
        DB::statement($this->driver->addGeneratedColumnSql(
            'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
        ));

        DB::table('parity_probe')->insert(['site_id' => 2, 'values' => json_encode(['price' => 'still text'])]);

        expect(DB::table('parity_probe')->where('site_id', 2)->count())->toBe(1);
    });
});

it('will not let another org\'s in-range number overflow this column', function (): void {
    /*
     * ⚠️ The JSON-kind guard alone was not enough. A shared generated column
     * reads its key from EVERY row in `entries`, including rows belonging to
     * an org that never indexed the field at all — so a perfectly valid
     * `10000000000` overflows a NUMERIC(12,2) column and stops it being
     * created. Type-correct and still out of range.
     */
    DB::table('parity_probe')->insert([
        ['site_id' => 1, 'values' => json_encode(['price' => 10])],
        ['site_id' => 2, 'values' => json_encode(['price' => 10000000000])],
    ]);

    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
    ));

    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeTrue()
        ->and(DB::table('parity_probe')->where('site_id', 2)->value('idx_price'))->toBeNull()
        ->and((float) DB::table('parity_probe')->where('site_id', 1)->value('idx_price'))->toBe(10.0);
});

it('still accepts a write of that out-of-range value', function (): void {
    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
    ));

    DB::table('parity_probe')->insert(['site_id' => 2, 'values' => json_encode(['price' => 99999999999999])]);

    expect(DB::table('parity_probe')->where('site_id', 2)->count())->toBe(1);
});

it('excludes a value that only overflows AFTER rounding', function (): void {
    /*
     * ⚠️ A magnitude bound was not enough. `9999999999.999` satisfies
     * abs(v) < 10^10, and the scale-2 cast then rounds it to
     * `10000000000.00` — which overflows NUMERIC(12,2) during column
     * creation, exactly what the guard exists to prevent.
     */
    DB::table('parity_probe')->insert([
        ['site_id' => 1, 'values' => json_encode(['price' => 1.5])],
        ['site_id' => 2, 'values' => json_encode(['price' => 9999999999.999])],
    ]);

    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
    ));

    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeTrue()
        ->and(DB::table('parity_probe')->where('site_id', 2)->value('idx_price'))->toBeNull()
        ->and((float) DB::table('parity_probe')->where('site_id', 1)->value('idx_price'))->toBe(1.5);
});

it('keeps a value that is inside the range once rounded', function (): void {
    // The guard has to be total in BOTH directions: rejecting a value the
    // column can hold would silently drop it from the index.
    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_price', 'values', 'price', new Projection(LogicalType::Decimal),
    ));

    DB::table('parity_probe')->insert(['site_id' => 1, 'values' => json_encode(['price' => 9999999999.99])]);

    expect(DB::table('parity_probe')->where('site_id', 1)->value('idx_price'))->not->toBeNull();
});

it('keeps an integer at the very top of its range', function (): void {
    /*
     * ⚠️ SQLite's round() returns a REAL, so round(9223372036854775807, 2)
     * becomes 9.2233720368547758e+18 — ABOVE the bound it was being compared
     * against. The guard rejected PHP_INT_MAX and an indexed integer field
     * holding it disappeared from every query. Integers compare exactly now;
     * only a decimal is rounded, because only a decimal is rounded by its
     * cast.
     */
    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_n', 'values', 'n', new Projection(LogicalType::Integer),
    ));

    DB::table('parity_probe')->insert(['site_id' => 1, 'values' => json_encode(['n' => PHP_INT_MAX])]);

    expect((int) DB::table('parity_probe')->where('site_id', 1)->value('idx_n'))->toBe(PHP_INT_MAX);
});

it('keeps an integer at the very BOTTOM of its range', function (): void {
    // The asymmetric end, which a magnitude bound excluded outright.
    DB::statement($this->driver->addGeneratedColumnSql(
        'parity_probe', 'idx_n', 'values', 'n', new Projection(LogicalType::Integer),
    ));

    DB::table('parity_probe')->insert(['site_id' => 1, 'values' => json_encode(['n' => PHP_INT_MIN])]);

    expect((int) DB::table('parity_probe')->where('site_id', 1)->value('idx_n'))->toBe(PHP_INT_MIN);
});
