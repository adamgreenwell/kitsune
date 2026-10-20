<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Schema\RequiredColumns;

beforeEach(function (): void {
    $this->connection = DB::connection();
    $this->schema = $this->connection->getSchemaBuilder();
    $this->table = 'commerce_column_presence_probe';
    $this->schema->create($this->table, function (Blueprint $table): void {
        $table->id();
        $table->string('label')->default('probe');
        $table->integer('amount')->nullable();
    });
});

afterEach(function (): void {
    $this->schema->dropIfExists($this->table);
});

it('preserves the framework column-presence contract', function (array $columns, bool $present): void {
    expect(RequiredColumns::present($this->connection, $this->table, $columns))->toBe($present)
        ->and($this->schema->hasColumns($this->table, $columns))->toBe($present);
})->with([
    'all required names' => [['id', 'label', 'amount'], true],
    'case-insensitive names' => [['ID', 'LaBeL'], true],
    'missing name' => [['id', 'not_a_column'], false],
    'system column is not an application column' => [['ctid'], false],
]);

it('does not reuse a positive result after a column is renamed or dropped', function (): void {
    expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeTrue();
    $this->schema->table($this->table, fn (Blueprint $table) => $table->renameColumn('label', 'renamed'));
    expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeFalse()
        ->and(RequiredColumns::present($this->connection, $this->table, ['renamed']))->toBeTrue();
    $this->schema->table($this->table, fn (Blueprint $table) => $table->dropColumn('renamed'));
    expect(RequiredColumns::present($this->connection, $this->table, ['renamed']))->toBeFalse();
});

it('does not reuse absence after a table is created or a presence after it is removed', function (): void {
    $table = $this->table.'_late';
    try {
        expect(RequiredColumns::present($this->connection, $table, ['id']))->toBeFalse();
        $this->schema->create($table, fn (Blueprint $blueprint) => $blueprint->id());
        expect(RequiredColumns::present($this->connection, $table, ['id']))->toBeTrue();
        $this->schema->drop($table);
        expect(RequiredColumns::present($this->connection, $table, ['id']))->toBeFalse();
    } finally {
        $this->schema->dropIfExists($table);
    }
});

it('honors a changed connection prefix without mixing the physical tables', function (): void {
    $previous = $this->connection->getTablePrefix();
    $prefix = 'column_probe_';
    try {
        $this->connection->setTablePrefix($prefix);
        expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeFalse();
        $this->schema->create($this->table, fn (Blueprint $table) => $table->string('other'));
        expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeFalse()
            ->and(RequiredColumns::present($this->connection, $this->table, ['other']))->toBeTrue();
    } finally {
        $this->schema->dropIfExists($this->table);
        $this->connection->setTablePrefix($previous);
    }
    expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeTrue()
        ->and(RequiredColumns::present($this->connection, $this->table, ['other']))->toBeFalse();
});

it('keeps PostgreSQL quoted table names as data rather than SQL', function (): void {
    if ($this->connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The optimized catalog read is PostgreSQL-specific.');
    }
    $table = 'column_probe_odd\'name';
    try {
        $this->schema->create($table, fn (Blueprint $blueprint) => $blueprint->id());
        expect(RequiredColumns::present($this->connection, $table, ['id']))->toBeTrue()
            ->and(RequiredColumns::present($this->connection, $table."' OR 1=1 --", ['id']))->toBeFalse();
    } finally {
        $this->schema->dropIfExists($table);
    }
});

it('resolves the current PostgreSQL schema afresh and honors an explicit schema', function (): void {
    if ($this->connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL schema resolution is driver-specific.');
    }
    $original = $this->connection->selectOne('show search_path')->search_path;
    $namespace = 'commerce_column_presence_namespace';
    $this->connection->statement('create schema "'.$namespace.'"');
    try {
        $this->schema->create($namespace.'.'.$this->table, fn (Blueprint $table) => $table->string('other'));
        expect(RequiredColumns::present($this->connection, $namespace.'.'.$this->table, ['other']))->toBeTrue()
            ->and(RequiredColumns::present($this->connection, $namespace.'.'.$this->table, ['label']))->toBeFalse();
        $this->connection->statement('set search_path to "'.$namespace.'", public');
        expect(RequiredColumns::present($this->connection, $this->table, ['other']))->toBeTrue()
            ->and(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeFalse()
            ->and($this->schema->hasColumns($this->table, ['other']))->toBeTrue();
        $this->connection->statement('set search_path to public');
        expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeTrue()
            ->and(RequiredColumns::present($this->connection, $this->table, ['other']))->toBeFalse();
    } finally {
        $this->connection->statement('select set_config(?, ?, false)', ['search_path', $original]);
        $this->schema->dropIfExists($namespace.'.'.$this->table);
        $this->connection->statement('drop schema "'.$namespace.'"');
    }
});

it('reads PostgreSQL column presence from the writer when a read connection is configured', function (): void {
    if ($this->connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The optimized writer catalog read is PostgreSQL-specific.');
    }
    $this->connection->setReadPdo(static fn () => throw new RuntimeException('A schema check used the read connection.'));
    try {
        expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeTrue();
        $this->schema->table($this->table, fn (Blueprint $table) => $table->dropColumn('label'));
        expect(RequiredColumns::present($this->connection, $this->table, ['label']))->toBeFalse();
    } finally {
        $this->connection->setReadPdo($this->connection->getPdo());
    }
});

it('distinguishes absent tables partial columns and views in one fresh inspection', function (): void {
    $view = 'commerce_column_presence_view';
    $missing = 'commerce_column_presence_absent';
    $requirements = [$this->table => ['id', 'label'], $view => ['id'], $missing => ['id']];
    $this->connection->statement('create view '.$view.' as select 1 as id');
    try {
        $first = RequiredColumns::inspect($this->connection, $requirements);
        expect($first)->toBe([
            $this->table => ['table' => true, 'columns' => true],
            $view => ['table' => false, 'columns' => true],
            $missing => ['table' => false, 'columns' => false],
        ]);
        foreach ($requirements as $table => $columns) {
            expect($first[$table])->toBe(['table' => $this->schema->hasTable($table), 'columns' => $this->schema->hasColumns($table, $columns)]);
        }
        $this->schema->table($this->table, fn (Blueprint $table) => $table->dropColumn('label'));
        $this->schema->create($missing, fn (Blueprint $table) => $table->string('different'));
        expect(RequiredColumns::inspect($this->connection, $requirements))->toBe([
            $this->table => ['table' => true, 'columns' => false],
            $view => ['table' => false, 'columns' => true],
            $missing => ['table' => true, 'columns' => false],
        ]);
    } finally {
        $this->connection->statement('drop view if exists '.$view);
        $this->schema->dropIfExists($missing);
    }
});
