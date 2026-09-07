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
    // Ask the driver, rather than hardcoding per engine at the call site —
    // doing that here is what hid the NUMERIC/DECIMAL divergence until the
    // storage benchmark passed one type to all three engines.
    $type = $this->driver->sqlType('decimal');

    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', $type));

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
    // Ask the driver, rather than hardcoding per engine at the call site —
    // doing that here is what hid the NUMERIC/DECIMAL divergence until the
    // storage benchmark passed one type to all three engines.
    $type = $this->driver->sqlType('decimal');

    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', $type));

    // ADR-021: composite indexes lead with the model's scope key.
    DB::statement($this->driver->createIndexSql('parity_probe', 'parity_probe_site_price', 'site_id', 'idx_price'));

    DB::table('parity_probe')->insert(['site_id' => 7, 'values' => json_encode(['price' => 12.34])]);

    expect(DB::table('parity_probe')->where('site_id', 7)->where('idx_price', 12.34)->exists())->toBeTrue();
});

it('drops a generated column again', function (): void {
    // Ask the driver, rather than hardcoding per engine at the call site —
    // doing that here is what hid the NUMERIC/DECIMAL divergence until the
    // storage benchmark passed one type to all three engines.
    $type = $this->driver->sqlType('decimal');

    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', $type));
    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeTrue();

    DB::statement($this->driver->dropGeneratedColumnSql('parity_probe', 'idx_price'));
    expect(Schema::hasColumn('parity_probe', 'idx_price'))->toBeFalse();
});

it('projects a text field, not only numerics', function (): void {
    $type = $this->driver->sqlType('string', 64);

    DB::statement($this->driver->addGeneratedColumnSql('parity_probe', 'idx_sku', 'values', 'sku', $type));

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
    $sql = $this->driver->addGeneratedColumnSql('parity_probe', 'idx_price', 'values', 'price', 'NUMERIC(12,2)');

    // `values` is reserved on MySQL and Postgres. Unquoted, this is a syntax error.
    expect($sql)->toContain($this->driver->quote('values'));
});

it('renders a decimal type this engine accepts inside CAST', function (): void {
    // MySQL accepts NUMERIC as a column type but rejects it inside CAST,
    // where it demands DECIMAL. The parity test used to hardcode the right
    // spelling per engine, which hid the divergence until the storage
    // benchmark passed one type to all three.
    $expected = $this->driver->name() === 'mysql' ? 'DECIMAL(12,2)' : 'NUMERIC(12,2)';

    expect($this->driver->sqlType('decimal'))->toBe($expected);
});

it('throws on an unknown logical type rather than guessing', function (): void {
    // A generated column silently created with the wrong type would index
    // the wrong thing, which is worse than failing loudly.
    expect(fn () => $this->driver->sqlType('nonsense'))->toThrow(UnhandledMatchError::class);
});
