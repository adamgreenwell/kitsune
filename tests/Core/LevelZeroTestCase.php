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

/**
 * Tests that must see transactions as production runs them — ADR-042 decision 5.
 *
 * ⚠️ NO `RefreshDatabase`, AND THAT IS THE WHOLE POINT. Its wrapper transaction means no write in the rest of the suite
 * ever reaches level 0: a commit is a savepoint release, a rollback never empties the transaction manager, and Laravel's
 * testing manager runs `afterCommit()` callbacks at level 1. Custody's rules are about exactly those moments — what runs
 * after the OUTERMOST commit, what a rollback to level 0 discards — so these tests run on the production manager, on two
 * SQLite files of their own: `custody`, the default connection, and `secondary`, for the second-connection cases.
 *
 * Each test builds both files from scratch and removes them, with their journals, afterwards. They run on the default
 * leg only; the engine legs have their own rival-connection tests.
 */
abstract class LevelZeroTestCase extends Orchestra
{
    protected string $custodyFile = '';

    protected string $secondaryFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'testing') !== 'testing') {
            $this->markTestSkipped('The level-0 suite runs on the default leg, on SQLite files of its own.');
        }

        foreach (['custody', 'secondary'] as $connection) {
            Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => [__DIR__.'/../../packages/core/database/migrations', __DIR__.'/migrations'],
                '--realpath' => true,
                '--force' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('custody');
        DB::purge('secondary');

        parent::tearDown();

        foreach ([$this->custodyFile, $this->secondaryFile] as $file) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                if ($file !== '' && is_file($file.$suffix)) {
                    unlink($file.$suffix);
                }
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->custodyFile = (string) tempnam(sys_get_temp_dir(), 'kitsune-level0-a-');
        $this->secondaryFile = (string) tempnam(sys_get_temp_dir(), 'kitsune-level0-b-');

        foreach (['custody' => $this->custodyFile, 'secondary' => $this->secondaryFile] as $name => $file) {
            $app['config']->set("database.connections.{$name}", [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
            ]);
        }

        $app['config']->set('database.default', 'custody');
    }
}
