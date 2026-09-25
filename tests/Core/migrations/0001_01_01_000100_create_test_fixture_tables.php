<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixture tables for the scope tests, created as a migration rather than
 * inside beforeEach().
 *
 * DDL inside a test is not portable. RefreshDatabase wraps each test in a
 * transaction, but CREATE TABLE and DROP TABLE implicitly COMMIT on MySQL —
 * so fixtures written after them were autocommitted, teardown could not roll
 * them back, and the next test collided on the same globally unique slugs.
 * The suite then failed on MySQL only, for reasons that looked nothing like
 * the cause.
 *
 * Creating them here means no DDL runs inside the transaction at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_things', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('label');
            $table->index(['site_id', 'org_id']);
        });

        Schema::create('shared_things', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->string('label');
            $table->index(['org_id']);
        });

        /*
         * A row a module's `install()` hook can write WITHOUT its own migration.
         *
         * ⚠️ It lives here for the reason this file exists. Proving that install's transaction rolls the
         * hook's writes back means throwing inside that transaction — and if the module migrates first, the
         * CREATE TABLE implicitly commits RefreshDatabase's wrapping transaction on MySQL and MariaDB while
         * Laravel's counter keeps counting it, so `DB::transaction()` emits a SAVEPOINT against a connection
         * holding no transaction and the rollback dies with SQLSTATE 1305 instead of surfacing the module's
         * refusal. Measured: the two tests passed on SQLite and PostgreSQL and failed on both MySQL engines.
         * With the table already here, the fixture module can turn its migrations off and the rollback is
         * observable on all four.
         */
        Schema::create('fixture_module_seeds', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        // Stands in for the skeleton's `User`: org membership through a
        // pivot, which is the one shape OrgScope cannot express.
        Schema::create('pivot_scoped_things', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('pivot_scoped_thing_org', function (Blueprint $table): void {
            $table->foreignId('org_id');
            $table->foreignId('pivot_scoped_thing_id');
            $table->primary(['org_id', 'pivot_scoped_thing_id']);
            $table->index(['pivot_scoped_thing_id', 'org_id']);
        });

        /*
         * The host application's `users`, and the skeleton's `role_user` — ADR-033.
         *
         * ⚠️ THEY ARE HERE BECAUSE CORE DOES NOT OWN THEM AND THE RESOLVER READS THEM ANYWAY. `role_user`
         * lives in the skeleton, for the reason `org_user` and `site_user` do: it references a `users` table
         * core did not create. So the core suite would have nothing to resolve against, and the cross-org
         * tests — the ones with no framework safety net (ADR-021) — would have to be written against a
         * stub of the very join they are supposed to distrust.
         *
         * Shaped exactly as the skeleton's migration shapes it, `constrained()` included, so the cascade
         * the test relies on is the cascade production has.
         */
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        Schema::create('org_user', function (Blueprint $table): void {
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['org_id', 'user_id']);
            $table->index(['user_id', 'org_id']);
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            /*
             * ⚠️ RESTRICTIVE, LIKE THE SKELETON'S — review found this fixture cascading while the reference
             * migration restricts, which meant the suite could never exercise the backstop and would silently
             * erase any assignment the observer missed. A fixture schema that is more forgiving than the
             * shipped one tests a different application.
             */
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'user_id']);
            $table->index(['user_id', 'role_id']);
        });

        /*
         * `media_files`' path and its entry, with no index on the path — ADR-042 decision 5 (Adam, decision 8, 2026-09-25).
         *
         * ⚠️ `media_files_path_unique` refuses a second row naming one path, so the migration's check for rows that
         * already do — the state an installation migrated before the index can be in — cannot be asked of `media_files`
         * itself. Its column is declared as `media_files.path` is, so the engine compares it by the same collation.
         */
        Schema::create('media_path_fixtures', function (Blueprint $table): void {
            $table->unsignedBigInteger('entry_id')->unique();
            $table->string('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_path_fixtures');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('org_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('fixture_module_seeds');
        Schema::dropIfExists('pivot_scoped_thing_org');
        Schema::dropIfExists('pivot_scoped_things');
        Schema::dropIfExists('shared_things');
        Schema::dropIfExists('site_things');
    }
};
