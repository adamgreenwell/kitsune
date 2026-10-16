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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\KitsuneServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Real outer commits/provider boundaries on disposable SQLite by default and each CI engine's test database. */
abstract class CommerceLifecycleTestCase extends Orchestra
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
            // These tests deliberately commit. Rebuild the dedicated test schema rather than hide leaked rows
            // behind RefreshDatabase's wrapper or leave financial custody for the next suite to inherit.
            $this->freshSchema();
        } finally {
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
            __DIR__.'/../Commerce/migrations'], '--realpath' => true, '--force' => true]) !== 0) {
            throw new \RuntimeException('The disposable commerce lifecycle schema could not be rebuilt.');
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
            throw new \RuntimeException('Unsupported commerce lifecycle test driver.');
        }
        if ($driver === 'testing') {
            $this->sqliteFile = tempnam(sys_get_temp_dir(), 'kitsune-commerce-level0-');
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
