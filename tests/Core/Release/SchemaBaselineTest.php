<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Release\SchemaBaseline;

require_once dirname(__DIR__, 3).'/bin/release/SchemaBaseline.php';

it('accepts the current recorded baseline on the configured test engine', function (): void {
    $migrator = app('migrator');

    expect(SchemaBaseline::refusals($migrator->resolveConnection(null), $migrator->getRepository()->getRan()))->toBe([]);
});

it('refuses existing tables with a missing or incomplete original migration ledger', function (): void {
    $original = '0001_01_01_000000_create_kitsune_tenancy_tables';

    expect(SchemaBaseline::refusals(DB::connection(), []))->toBe([
        'orgs exists without the original Kitsune migration records',
    ])->and(SchemaBaseline::refusals(DB::connection(), [$original]))->toBe([
        'the original Kitsune migration records are incomplete or duplicated',
    ]);
});

it('accepts an empty database and refuses an unrecorded partial schema without changing it', function (): void {
    config(['database.connections.baseline_scratch' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    $connection = DB::connection('baseline_scratch');
    $schema = $connection->getSchemaBuilder();

    try {
        expect(SchemaBaseline::refusals($connection, []))->toBe([]);
        $schema->create('entry_revisions', fn (Blueprint $table) => $table->id());
        expect(SchemaBaseline::refusals($connection, []))->toBe([
            'entry_revisions exists without the original Kitsune migration records',
        ])->and($schema->getColumnListing('entry_revisions'))->toBe(['id']);
    } finally {
        DB::purge('baseline_scratch');
    }
});
