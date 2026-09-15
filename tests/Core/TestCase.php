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
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\KitsuneServiceProvider;

use function Orchestra\Testbench\laravel_or_fail;
use function Orchestra\Testbench\load_migration_paths;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Every test starts from an empty database.
     *
     * The schema is migrated once per process, and again when the host fixtures change, and each test runs inside a
     * transaction that is rolled back. In-memory SQLite is the exception: Testbench resets its state after every test,
     * so each test migrates a new, empty database. On PostgreSQL, MySQL and MariaDB the data would otherwise persist,
     * and the suite was passing only because nothing had polluted those databases first. A single benchmark run
     * against them was enough to turn 52 passes into 42 failures on unique-constraint violations.
     */
    use RefreshDatabase;

    /** The fixture migrations the database was last built with in this process. */
    private static ?string $migratedFixtures = null;

    /** @var array<string, list<string>> The tables each fixture set builds, listed once per process. */
    private static array $tablesByFixtures = [];

    /** The last test that began on an empty database, so rows found later name the test that left them. */
    private static ?string $lastCleanStart = null;

    /** @var list<Closure(): void> */
    private array $afterRollbackCallbacks = [];

    /**
     * ⚠️ ROWS THAT OUTLIVE A TEST FAIL THE NEXT TEST THAT WOULD SEE THEM, AND NAME THE TEST THEY CAME FROM.
     *
     * `RefreshDatabase` rolls back the default connection only. A write through a second connection survives that
     * rollback, and with the schema migrated once per process nothing removes it before the next test. Two files did
     * exactly this: their `afterAll` sweeps ran after Testbench had flushed the container, threw, and swallowed the
     * error. Only the old rebuild after every other test hid it, and without that rebuild the rows failed assertions
     * three files later.
     *
     * So every test that starts on a database that should be empty checks it. The check runs before `RefreshDatabase`
     * opens its transaction, so it pins no snapshot: under REPEATABLE READ a read inside the transaction would take one
     * before a test's rival connection commits, and the tests built on that ordering would stop measuring anything.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->refuseRowsLeftBehind();

        /*
         * ⚠️ REGISTERED HERE BECAUSE TEARDOWN RUNS BACKWARDS. `beforeApplicationDestroyed()` prepends, so the callback
         * registered last runs first. `RefreshDatabase` registers its rollback after this hook returns, so this runs
         * once the rollback has released the test's locks, and before the application is flushed. Registered in a test
         * or in `afterEach`, it would run while those locks are still held. In `afterAll` there is no application.
         */
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->afterRollbackCallbacks as $callback) {
                $callback();
            }
        });
    }

    /**
     * Run a callback once this test's transaction has been rolled back.
     *
     * For a test that has to commit through another connection: it removes what it committed here, with its locks
     * released and the application still alive. A callback that throws fails a test that would otherwise pass. After
     * a test has already failed, PHPUnit discards that error, and the rows the callback left fail the next test that
     * starts on a migrated database.
     *
     * @param  Closure(): void  $callback
     */
    protected function afterRollback(Closure $callback): void
    {
        $this->afterRollbackCallbacks[] = $callback;
    }

    private function refuseRowsLeftBehind(): void
    {
        // Every test is a Pest test (tests/Pest.php), and Pest's Testable trait supplies both halves of its name.
        $test = static::getPrintableTestCaseName().' > '.$this->getPrintableTestCaseMethodName();

        // A rebuild is about to empty the database anyway, and in-memory SQLite is a new database for every test.
        if (! RefreshDatabaseState::$migrated || $this->usingInMemoryDatabases()) {
            self::$lastCleanStart = $test;

            return;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();

        $tables = self::$tablesByFixtures[static::fixtureMigrations()] ??= array_values(array_diff(
            Schema::getTableListing(Schema::getCurrentSchemaListing(), false),
            ['migrations'],
        ));

        // One round trip, and `exists` reads at most one row of each table.
        $found = (array) $connection->selectOne('select '.implode(', ', array_map(
            static fn (string $table): string => 'exists (select 1 from '.$grammar->wrapTable($table).') as '
                .$grammar->wrap($table),
            $tables,
        )));

        $dirty = array_keys(array_filter($found));

        if ($dirty === []) {
            self::$lastCleanStart = $test;

            return;
        }

        // Rebuild before the next test, so one leak is one failure rather than every test after it.
        RefreshDatabaseState::$migrated = false;

        self::fail(sprintf(
            'Rows committed outside RefreshDatabase\'s transaction survived into this test: %s. The database was empty '
            .'when [%s] began, so that test, or an afterAll hook in its file, left them.',
            implode(', ', array_map(
                static fn (string $table): string => $table.' ('.$connection->table($table)->count().')',
                $dirty,
            )),
            self::$lastCleanStart ?? 'no earlier test',
        ));
    }

    /**
     * Testbench gives the package a Laravel application without vendoring one.
     * That is what keeps the default suite runnable on a bare clone (ADR-024).
     */
    protected function getPackageProviders($app): array
    {
        // ⚠️ Blade Icons is registered explicitly, because Testbench boots only
        // what it is told to. Without it the icon factory is unresolvable, which
        // made `Icons::judge()` return "cannot tell" for every name and quietly
        // stood the icon guard down in the one place that tests it. No service
        // is involved — it is a package provider, so invariant 11 is untouched.
        return [
            BladeIconsServiceProvider::class,
            // ⚠️ And the SET provider, not just the factory. Blade Icons
            // resolves nothing on its own — `blade-heroicons` is what registers
            // the `heroicon` set, so with only the factory booted every name was
            // unjudgeable and the icon guard stood itself down in the one place
            // that tests it.
            BladeHeroiconsServiceProvider::class,
            KitsuneServiceProvider::class,
        ];
    }

    /**
     * Run kitsune/core's own migrations against the in-memory database.
     *
     * The service provider registers them for a host application; Testbench
     * needs telling explicitly, because it builds the application itself.
     */
    protected function defineDatabaseMigrations(): void
    {
        /*
         * ⚠️ THE DATABASE IS REBUILT WHEN THE HOST CHANGES — #91 — and this is the only place that can decide it.
         * `RefreshDatabase` migrates once per process, so whichever host's tests ran second would run against the
         * first host's tables. Clearing `$migrated` here, before `RefreshDatabase` runs, is what makes it rebuild with
         * the new host's fixture paths registered.
         *
         * A second connection with a table prefix was tried before this and cannot work on MySQL or MariaDB: Laravel
         * names foreign keys without the prefix, those engines keep constraint names unique per database, and every
         * core foreign key collided with the other host's (SQLSTATE 1826).
         */
        if (self::$migratedFixtures !== static::fixtureMigrations()) {
            RefreshDatabaseState::$migrated = false;
            self::$migratedFixtures = static::fixtureMigrations();
        }

        /*
         * ⚠️ THE PATHS ARE REGISTERED, NEVER RUN, so `RefreshDatabase` is the only thing that migrates. This called
         * `loadMigrationsFrom()`, which registers paths only while `$migrated` is false; once it was true, it ran
         * `migrate` itself and Testbench rolled that back at teardown and reset the flag. So on PostgreSQL, MySQL and
         * MariaDB every other test rebuilt the whole schema with `migrate:fresh` — about 1,000 rebuilds a run, at least
         * 58% of a normal MySQL lane, and the multiplier behind the MySQL lane cancelled at its 90-minute ceiling on a
         * slow runner. In-memory SQLite is unaffected: Testbench resets its state per test either way.
         *
         * Fixture tables live here rather than in beforeEach(), because DDL implicitly commits on MySQL and breaks
         * RefreshDatabase's rollback.
         */
        load_migration_paths(laravel_or_fail($this->app), [
            __DIR__.'/../../packages/core/database/migrations',
            static::fixtureMigrations(),
        ]);
    }

    /**
     * The host-side tables this test case runs against, on top of core's own.
     *
     * ⚠️ A METHOD, SO A SECOND HOST CAN BE A SECOND SET — #91. Core owns no user model, so the shape of `users`,
     * `org_user` and `role_user` is the host's, and the key type is part of that shape. This is the reference host,
     * with integer keys; `UlidHostTestCase` is a host whose users carry ULIDs.
     */
    protected static function fixtureMigrations(): string
    {
        return __DIR__.'/migrations';
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
