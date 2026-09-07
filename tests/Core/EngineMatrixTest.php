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

/*
 * The engine matrix earns its cost only if something actually touches the
 * configured engine. This is that something: a real table, a real round-trip,
 * on whichever driver DB_CONNECTION selects.
 *
 * It is deliberately trivial today. ADR-006 stakes the storage design on
 * generated columns whose syntax diverges across all three engines, so when
 * Phase 4 builds that driver abstraction, its parity tests belong here.
 */

beforeEach(function (): void {
    Schema::dropIfExists('engine_probe');
    Schema::create('engine_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('handle');
        $table->json('payload')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('engine_probe');
});

it('round-trips a row on the configured engine', function (): void {
    DB::table('engine_probe')->insert(['handle' => 'article', 'payload' => json_encode(['price' => 24.99])]);

    $row = DB::table('engine_probe')->where('handle', 'article')->first();

    expect($row)->not->toBeNull();
    expect($row->handle)->toBe('article');
    expect(json_decode((string) $row->payload, true))->toBe(['price' => 24.99]);
});

it('reports which driver it ran against', function (): void {
    $driver = DB::connection()->getDriverName();

    expect($driver)->toBeIn(['sqlite', 'pgsql', 'mysql']);
})->group('engine');
