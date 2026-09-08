<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kitsune\Core\KitsuneServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Every test starts from an empty database.
     *
     * On SQLite each test already gets a fresh in-memory database, so this
     * changes nothing there — which is exactly why its absence went unnoticed.
     * On PostgreSQL and MySQL the data persists, and the suite was passing
     * only because nothing had polluted those databases first. A single
     * benchmark run against them was enough to turn 52 passes into 42
     * failures on unique-constraint violations.
     */
    use RefreshDatabase;

    /**
     * Testbench gives the package a Laravel application without vendoring one.
     * That is what keeps the default suite runnable on a bare clone (ADR-024).
     */
    protected function getPackageProviders($app): array
    {
        return [KitsuneServiceProvider::class];
    }

    /**
     * Run kitsune/core's own migrations against the in-memory database.
     *
     * The service provider registers them for a host application; Testbench
     * needs telling explicitly, because it builds the application itself.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../packages/core/database/migrations');
        // Fixture tables live here rather than in beforeEach(), because DDL
        // implicitly commits on MySQL and breaks RefreshDatabase's rollback.
        $this->loadMigrationsFrom(__DIR__.'/migrations');
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

        // ⚠️ SQLite does not enforce foreign keys unless asked, and Testbench
        // does not ask. PostgreSQL and MySQL always do — so without this the
        // default suite never exercised a single `cascadeOnDelete`,
        // `nullOnDelete` or `constrained()` in any migration, while the two
        // engine legs silently did.
        //
        // That matters beyond tidiness: deleting an org is supposed to remove
        // its data, and deleting a nominated field is supposed to NULL the
        // nomination — behaviour ADR-020 relies on and nothing was checking.
        if ($connection === 'testing' || $connection === 'sqlite') {
            $app['config']->set('database.connections.'.$connection.'.foreign_key_constraints', true);
        }

        // ⚠️ mariadb is a distinct DRIVER name, not a flavour of mysql.
        // DriverFactory routes it to MySqlDriver, and README, architecture.md
        // and roadmap.md all document it as supported — but nothing ran
        // against it, so two MySQL-only constructs had shipped: a JSON cast
        // MariaDB rejects outright, and a collation it does not have.
        if ($connection === 'pgsql' || $connection === 'mysql' || $connection === 'mariadb') {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => $connection,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $connection === 'pgsql' ? '5432' : '3306'),
                'database' => env('DB_DATABASE', 'kitsune'),
                'username' => env('DB_USERNAME', 'kitsune'),
                'password' => env('DB_PASSWORD', 'kitsune'),
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
            ]);
        }
    }
}
