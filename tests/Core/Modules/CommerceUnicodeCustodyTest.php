<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\CommerceCustody;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleLifecycle;

/** Disposable DDL on a separately connected random namespace; never in RefreshDatabase's transaction. */
function withUnicodeCommerceTables(string $prefix, array $suffixes, Closure $test): void
{
    $name = 'uc_'.getmypid().'_'.bin2hex(random_bytes(4));
    $config = DB::connection()->getConfig();
    $config['prefix'] = '';
    $config['name'] = $name;
    $connection = DB::connectUsing($name, $config);
    $prefix .= '_'.$name.'_';
    $tables = [];

    try {
        foreach ($suffixes as $suffix) {
            $table = $prefix.$suffix;
            $connection->getSchemaBuilder()->create($table, function (Blueprint $definition) use ($suffix): void {
                if ($suffix === 'module_uninstalls') {
                    $definition->string('handle')->primary();
                    $definition->string('version');
                    $definition->timestamp('started_at');

                    return;
                }
                $definition->id();
                if ($suffix === 'modules') {
                    $definition->string('handle');
                    $definition->string('version');
                    $definition->boolean('is_enabled');
                    $definition->timestamp('installed_at');

                    return;
                }
                $definition->unsignedBigInteger('org_id');
                $definition->timestamp('deleted_at')->nullable();
            });
            $tables[] = $table;
        }

        $connection->setTablePrefix($prefix);
        $test($connection, $prefix);
    } finally {
        $connection->setTablePrefix('');
        foreach (array_reverse($tables) as $table) {
            $connection->getSchemaBuilder()->dropIfExists($table);
        }
        DB::purge($name);
    }
}

beforeEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Physical Unicode table ownership is a MySQL/MariaDB boundary.');
    }

    if ((string) DB::connection()->selectFromWriteConnection('select @@lower_case_table_names as folding')[0]->folding !== '1') {
        $this->markTestSkipped('This group qualifies lower_case_table_names=1; exact-case and unknown settings have separate tests.');
    }
});

it('reads a retained physical table despite Unicode folding and changed prefix byte length', function (string $prefix): void {
    withUnicodeCommerceTables($prefix, ['commerce_empty', 'commerce_future'], function (Connection $connection): void {
        $connection->table('commerce_future')->insert(['org_id' => 999999, 'deleted_at' => now()]);
        expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'still holds data');
        expect($connection->table('commerce_future')->count())->toBe(1);
        $connection->table('commerce_future')->delete();
        CommerceCustody::refuseKeptRows($connection);
    });
})->with(['dotted I' => ['İ'], 'Greek sigma' => ['ΟΣ'], 'capital sharp S' => ['ẞ'],
    'Kelvin sign' => ['K'], 'embedded namespace' => ['İ_commerce_'], 'quoted prefix' => ['Ä`x']]);

it('does not mistake a similar Unicode prefix for this installation', function (string $foreign, string $local): void {
    withUnicodeCommerceTables($foreign, ['commerce_future'], function (Connection $connection, string $prefix) use ($foreign, $local): void {
        $connection->table('commerce_future')->insert(['org_id' => 888888]);
        $connection->setTablePrefix($local.substr($prefix, strlen($foreign)));
        CommerceCustody::refuseKeptRows($connection);
        $connection->setTablePrefix($prefix);
        expect($connection->table('commerce_future')->count())->toBe(1);
    });
})->with(['accent' => ['é', 'è'], 'sharp S' => ['ß', 'ẞ'], 'ligature' => ['ﬁÄ', 'fiÄ'],
    'normalization' => ['é', "e\u{0301}"], 'extra namespace' => ['Ä_commerce_', 'Ä']]);

it('refuses an unquoted physical metadata format instead of declaring it empty', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future'], function (Connection $connection): void {
        $original = $connection->selectFromWriteConnection('select @@sql_quote_show_create as quoting')[0]->quoting;
        try {
            $connection->unprepared('set session sql_quote_show_create=0');
            expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'ownership could not be determined');
        } finally {
            $connection->unprepared('set session sql_quote_show_create='.(int) $original);
        }
    });
});

it('propagates a denied physical ownership read without treating it as missing', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future'], function (Connection $connection): void {
        $connection->table('commerce_future')->insert(['org_id' => 777777]);
        $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        try {
            $connection->beforeExecuting(function (string $sql) use ($connection): void {
                if (str_starts_with($sql, 'show create table ')) {
                    $denied = new PDOException('Physical metadata denied.');
                    $denied->errorInfo = ['42000', 1142, 'Metadata access denied.'];
                    throw new QueryException($connection->getName(), $sql, [], $denied);
                }
            });
            expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(QueryException::class, 'Physical metadata denied.');
        } finally {
            $callbacks->setValue($connection, $original);
        }
        expect($connection->table('commerce_future')->count())->toBe(1);
    });
});

it('keeps physical ownership and retained-row queries on the write connection', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future'], function (Connection $connection): void {
        $connection->table('commerce_future')->insert(['org_id' => 999999]);
        $connection->setReadPdo(new PDO('sqlite::memory:'));
        expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'still holds data');
    });
});

it('propagates a failed physical row query after ownership is proved', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future'], function (Connection $connection): void {
        $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        try {
            $connection->beforeExecuting(function (string $sql): void {
                if (str_starts_with($sql, 'select exists(')) {
                    throw new RuntimeException('Physical rows unavailable.');
                }
            });
            expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'Physical rows unavailable.');
        } finally {
            $callbacks->setValue($connection, $original);
        }
    });
});

it('refuses a temporary shadow instead of accepting an empty physical table', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future'], function (Connection $connection, string $prefix): void {
        $connection->table('commerce_future')->insert(['org_id' => 555555]);
        $quoted = '`'.str_replace('`', '``', $prefix.'commerce_future').'`';
        $connection->unprepared('create temporary table '.$quoted.' (id int)');
        try {
            expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'ownership could not be determined');
        } finally {
            $connection->unprepared('drop temporary table '.$quoted);
        }
        expect($connection->table('commerce_future')->count())->toBe(1);
    });
});

it('ignores a foreign retained table when this installation owns an empty table of the same suffix', function (): void {
    withUnicodeCommerceTables('é', ['commerce_future'], function (Connection $connection, string $prefix): void {
        $connection->table('commerce_future')->insert(['org_id' => 444444]);
        $local = 'è'.substr($prefix, strlen('é'));
        $connection->setTablePrefix($local);
        $connection->getSchemaBuilder()->create('commerce_future', function (Blueprint $definition): void {
            $definition->id();
        });
        try {
            CommerceCustody::refuseKeptRows($connection);
            expect($connection->table('commerce_future')->count())->toBe(0);
        } finally {
            $connection->getSchemaBuilder()->dropIfExists('commerce_future');
        }
        $connection->setTablePrefix($prefix);
        expect($connection->table('commerce_future')->count())->toBe(1);
    });
});

it('checks a future table whose own suffix contains another commerce namespace', function (): void {
    withUnicodeCommerceTables('İ_commerce_', ['commerce_sub_commerce_future'], function (Connection $connection): void {
        $connection->table('commerce_sub_commerce_future')->insert(['org_id' => 333333]);
        expect(fn () => CommerceCustody::refuseKeptRows($connection))->toThrow(RuntimeException::class, 'still holds data');
    });
});

it('refuses real lifecycle uninstall before deleting its prefixed receipt or issuing teardown DDL', function (): void {
    withUnicodeCommerceTables('İ', ['commerce_future', 'modules', 'module_uninstalls'], function (Connection $connection): void {
        $connection->table('commerce_future')->insert(['org_id' => 222222, 'deleted_at' => now()]);
        $connection->table('modules')->insert(['handle' => 'kitsune/commerce', 'version' => 'fixture', 'is_enabled' => false, 'installed_at' => now()]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        $connection->enableQueryLog();
        try {
            expect(fn () => ModuleLifecycle::uninstall(app(), 'kitsune/commerce'))->toThrow(RuntimeException::class, 'still holds data');
            expect(Module::query()->where('handle', 'kitsune/commerce')->exists())->toBeTrue()
                ->and($connection->table('module_uninstalls')->count())->toBe(0)
                ->and($connection->table('commerce_future')->count())->toBe(1);
            foreach ($connection->getQueryLog() as $query) {
                expect(preg_match('/\A(?:drop|delete|alter|truncate)\b/i', $query['query']))->toBe(0);
            }
        } finally {
            $connection->disableQueryLog();
            DB::setDefaultConnection($original);
        }
    });
});
