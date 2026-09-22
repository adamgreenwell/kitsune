<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Fixture\Module;

use Filament\Navigation\NavigationItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleServiceProvider;
use RuntimeException;

/**
 * A module provider that records being registered and booted, so a test can assert the kernel reached it
 * rather than assert that the kernel thinks it did.
 */
final class FixtureModuleServiceProvider extends ModuleServiceProvider
{
    /** @var list<string> */
    public static array $calls = [];

    /**
     * Makes `install()` write a row and THEN throw, so a test can prove the hook and the receipt commit
     * together rather than asserting that they are supposed to.
     */
    public static bool $installFailsAfterWriting = false;

    /**
     * Turns this module's migrations off, so a test can exercise install's transaction with NO DDL in it.
     *
     * ⚠️ NOT a test-only fiction: `ModuleServiceProvider::migrationPath()` returns null by default, so a
     * module that ships no schema is an ordinary production shape, and `ModuleVerifier` does not require one.
     *
     * It exists because DDL implicitly commits the wrapping transaction on MySQL and MariaDB. Migrating first
     * left Laravel's transaction counter describing a transaction the engine had already closed, so
     * `DB::transaction()` emitted a SAVEPOINT with nothing open and the rollback raised SQLSTATE 1305 rather
     * than the module's own refusal — the rollback assertion passed on SQLite and PostgreSQL and failed on
     * both MySQL engines, which is engine-specific test correctness (AGENTS.md §5).
     */
    public static bool $withoutMigrations = false;

    /**
     * Makes `install()` issue DDL from the hook — the thing its contract forbids.
     *
     * On MySQL and MariaDB that implicitly commits install's transaction, so the receipt is committed and
     * then Laravel's `commit()` throws on a connection holding no transaction: the operator sees install fail
     * for a module that is fully installed. `ModuleLifecycle` now asks the engine whether the transaction
     * survived the hook rather than trusting the contract.
     */
    public static bool $installIssuesDdl = false;

    /** The row `install()` writes and then refuses to write twice — this module's stand-in for person's global type. */
    private const SEED_ROW = 'written before the throw';

    /**
     * ⚠️ A table the BASE test schema already created, not this module's own.
     *
     * The seed row has to be writable with `$withoutMigrations` set, and `fixture_things` does not exist
     * then. `fixture_module_seeds` is created by the suite's fixture migration, which runs before
     * RefreshDatabase opens its transaction — see that file's docblock on why no DDL may run inside it.
     */
    private const SEED_TABLE = 'fixture_module_seeds';

    protected function registerModule(): void
    {
        self::$calls[] = 'register';

        /*
         * The seam, filled in registerModule() — in time because the kernel runs inside $app->booted(), and
         * KitsunePanel::apply() does not run until PanelRegistry is first resolved.
         */
        $this->app->make(AdminSurface::class)
            ->resource(FixtureThingResource::class)
            ->navigationItem(
                NavigationItem::make('Fixture things')
                    ->group('Structure')
                    ->url(fn (): string => '/admin/fixture-things'),
            );
    }

    protected function bootModule(): void
    {
        self::$calls[] = 'boot';
    }

    public function migrationPath(): ?string
    {
        return self::$withoutMigrations ? null : __DIR__.'/../database/migrations';
    }

    public function install(): void
    {
        self::$calls[] = 'install';

        /*
         * A row FIRST, then the throw. A hook that throws before writing anything proves nothing about a
         * transaction — the interesting failure is the half-done one, which is what actually shipped.
         */
        /*
         * ⚠️ THE MODULE'S OWN IDEMPOTENCY CHECK, which is what turns a leaked row into a DEAD END rather
         * than a mess. `PersonServiceProvider::install()` opens with exactly this shape — a hand-written
         * `exists()` probe, because a global row's handle is not protected by any unique index (NULLs
         * compare distinct on every engine). Without the transaction around the hook, a row left by a
         * failed install makes every later install refuse here while `uninstall` refuses for want of a
         * receipt, and the CLI has no way out.
         */
        if (DB::table(self::SEED_TABLE)->where('name', self::SEED_ROW)->exists()) {
            throw new RuntimeException('kitsune/fixture-module already has its seed row.');
        }

        if (self::$installIssuesDdl) {
            /* Dropped first, so a table left by an interrupted run cannot fail the next one at CREATE. */
            Schema::dropIfExists('fixture_module_ddl');

            Schema::create('fixture_module_ddl', function (Blueprint $table): void {
                $table->id();
            });
        }

        if (self::$installFailsAfterWriting) {
            /* The fixture table carries no timestamps, so this writes it the way the suite's other content test does. */
            DB::table(self::SEED_TABLE)->insert(['name' => self::SEED_ROW]);

            throw new RuntimeException('kitsune/fixture-module could not finish installing.');
        }
    }

    /**
     * ⚠️ THE MODULE'S OWN REFUSAL. Core cannot know what this module's content is, so uninstall asks the
     * module and the module throws. Nothing is rolled back before this runs.
     */
    public function uninstall(): void
    {
        self::$calls[] = 'uninstall';

        if (FixtureThing::query()->exists()) {
            throw new RuntimeException('kitsune/fixture-module still holds things. Delete them first.');
        }
    }
}
