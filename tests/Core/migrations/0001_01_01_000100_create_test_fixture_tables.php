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
    }

    public function down(): void
    {
        Schema::dropIfExists('pivot_scoped_thing_org');
        Schema::dropIfExists('pivot_scoped_things');
        Schema::dropIfExists('shared_things');
        Schema::dropIfExists('site_things');
    }
};
