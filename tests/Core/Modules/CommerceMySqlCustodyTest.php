<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\CommerceCustody;

beforeEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL and MariaDB decide table-name folding through their server setting.');
    }

    // DDL uses another connection, never the RefreshDatabase wrapper. The random prefix owns cleanup too.
    $this->mysqlCustodyName = 'commerce_custody_'.getmypid().'_'.bin2hex(random_bytes(6));
    $this->mysqlCustodyPrefix = $this->mysqlCustodyName.'_';
    $this->mysqlCustodyTable = $this->mysqlCustodyPrefix.'commerce_kept';
    $configuration = DB::connection()->getConfig();
    $configuration['prefix'] = '';
    $this->mysqlCustody = DB::connectUsing($this->mysqlCustodyName, $configuration);
    $this->mysqlCustody->getSchemaBuilder()->create($this->mysqlCustodyTable, function (Blueprint $table): void {
        $table->id();
    });
    $this->mysqlCustody->table($this->mysqlCustodyTable)->insert(['id' => 1]);
    $this->mysqlCustody->setTablePrefix($this->mysqlCustodyPrefix);
});

afterEach(function (): void {
    if (! isset($this->mysqlCustody)) {
        return;
    }

    try {
        $this->mysqlCustody->setTablePrefix('');
        $this->mysqlCustody->getSchemaBuilder()->dropIfExists($this->mysqlCustodyTable);
    } finally {
        DB::purge($this->mysqlCustodyName);
    }
});

it('refuses retained rows under the exact MySQL or MariaDB connection prefix', function (): void {
    expect($this->mysqlCustody->table('commerce_kept')->useWritePdo()->exists())->toBeTrue();
    expect(fn () => CommerceCustody::refuseKeptRows($this->mysqlCustody))
        ->toThrow(RuntimeException::class, 'still holds data');
});

it('uses actual MySQL or MariaDB table-name folding for another cased prefix', function (): void {
    $setting = (string) $this->mysqlCustody
        ->selectFromWriteConnection('select @@lower_case_table_names as folding')[0]->folding;
    expect($setting)->toBeIn(['0', '1', '2']);
    $this->mysqlCustody->setTablePrefix(strtoupper($this->mysqlCustodyPrefix));

    if ($setting !== '0') {
        expect($this->mysqlCustody->table('commerce_kept')->useWritePdo()->exists())->toBeTrue();
        expect(fn () => CommerceCustody::refuseKeptRows($this->mysqlCustody))
            ->toThrow(RuntimeException::class, 'still holds data');

        return;
    }

    expect($this->mysqlCustody->getSchemaBuilder()->hasTable('commerce_kept'))->toBeFalse();
    CommerceCustody::refuseKeptRows($this->mysqlCustody);
    $this->mysqlCustody->setTablePrefix('');
    expect($this->mysqlCustody->table($this->mysqlCustodyTable)->count())->toBe(1);
});

it('refuses unsupported non-ASCII folding and preserves exact-case server behavior', function (bool $kept): void {
    $connection = $this->mysqlCustody;
    $connection->setTablePrefix('');
    $table = 'ä_'.$this->mysqlCustodyTable;
    $connection->getSchemaBuilder()->create($table, function (Blueprint $definition): void {
        $definition->id();
    });

    try {
        if ($kept) {
            $connection->table($table)->insert(['id' => 1]);
        }

        $setting = (string) $connection->selectFromWriteConnection('select @@lower_case_table_names as folding')[0]->folding;
        $connection->setTablePrefix('Ä_'.$this->mysqlCustodyPrefix);

        if ($setting !== '0') {
            expect($connection->table('commerce_kept')->useWritePdo()->exists())->toBe($kept);
            expect(fn () => CommerceCustody::refuseKeptRows($connection))
                ->toThrow(RuntimeException::class, 'cannot safely inspect a non-ASCII table prefix');
        } else {
            // This differently cased prefix belongs to another installation on an exact-case server.
            CommerceCustody::refuseKeptRows($connection);
            $connection->setTablePrefix('ä_'.$this->mysqlCustodyPrefix);

            if ($kept) {
                expect(fn () => CommerceCustody::refuseKeptRows($connection))
                    ->toThrow(RuntimeException::class, 'still holds data');
            } else {
                CommerceCustody::refuseKeptRows($connection);
            }
        }

        $connection->setTablePrefix('');
        expect($connection->table($table)->count())->toBe((int) $kept);
    } finally {
        $connection->setTablePrefix('');
        $connection->getSchemaBuilder()->dropIfExists($table);
    }
})->with(['retained data' => [true], 'empty table' => [false]]);
