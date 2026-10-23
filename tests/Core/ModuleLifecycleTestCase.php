<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\KitsuneServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Actual outer commits and failed DDL on the disposable test database, never RefreshDatabase's wrapper. */
abstract class ModuleLifecycleTestCase extends Orchestra
{
    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->freshSchema();
        } finally {
            // Another test case may otherwise believe its schema survived this dedicated rebuild.
            RefreshDatabaseState::$migrated = false;
            DB::purge();
            parent::tearDown();
            if ($this->sqliteFile !== null) {
                foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                    if (is_file($this->sqliteFile.$suffix)) {
                        unlink($this->sqliteFile.$suffix);
                    }
                }
            }
        }
    }

    private function freshSchema(): void
    {
        if (Artisan::call('migrate:fresh', ['--path' => [__DIR__.'/../../packages/core/database/migrations',
            __DIR__.'/migrations', TestCase::READERS_MIGRATION], '--realpath' => true, '--force' => true]) !== 0) {
            throw new \RuntimeException('The disposable module lifecycle schema could not be rebuilt.');
        }
    }

    protected function getPackageProviders($app): array
    {
        return [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = env('DB_CONNECTION', 'testing');
        if (! in_array($driver, ['testing', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new \RuntimeException('Unsupported module lifecycle test driver.');
        }
        if ($driver === 'testing') {
            $this->sqliteFile = tempnam(sys_get_temp_dir(), 'kitsune-module-level0-');
            $configuration = ['driver' => 'sqlite', 'database' => $this->sqliteFile, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => 5000];
        } else {
            $configuration = ['driver' => $driver, 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT'),
                'database' => env('DB_DATABASE', 'kitsune'), 'username' => env('DB_USERNAME', 'kitsune'),
                'password' => env('DB_PASSWORD', 'kitsune'), 'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '', 'strict' => true];
        }
        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.'.$driver, $configuration);
    }
}
