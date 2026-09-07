<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

use Kitsune\Core\KitsuneServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench gives the package a Laravel application without vendoring one.
     * That is what keeps the default suite runnable on a bare clone (ADR-024).
     */
    protected function getPackageProviders($app): array
    {
        return [KitsuneServiceProvider::class];
    }

    /**
     * Point the suite at whichever engine the environment selects.
     *
     * Unset, this is SQLite in memory - the bare-clone default (ADR-024).
     * CI overrides DB_CONNECTION to re-run the same suite on Postgres and
     * MySQL, which is what makes the matrix meaningful rather than decorative.
     */
    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'testing');
        $app['config']->set('database.default', $connection);

        if ($connection === 'pgsql' || $connection === 'mysql') {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => $connection,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $connection === 'pgsql' ? '5432' : '3306'),
                'database' => env('DB_DATABASE', 'kitsune'),
                'username' => env('DB_USERNAME', 'kitsune'),
                'password' => env('DB_PASSWORD', 'kitsune'),
                'charset' => $connection === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ]);
        }
    }
}
