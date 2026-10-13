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
 * Synthetic future commerce records, never production schema. Build them before RefreshDatabase opens
 * its wrapper: DDL in a test would commit that wrapper on MySQL/MariaDB and invalidate the refusal proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['commerce_fixture_empty', 'commerce_fixture_kept', 'commerce_fixture_future', 'fixture_commerce_unowned'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('org_id');
                $table->string('payload');
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['fixture_commerce_unowned', 'commerce_fixture_future', 'commerce_fixture_kept', 'commerce_fixture_empty'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
