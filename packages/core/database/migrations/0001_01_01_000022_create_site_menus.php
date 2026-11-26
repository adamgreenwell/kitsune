<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 64);
            $table->string('name', 120);
            $table->json('items');
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique(['site_id', 'handle']);
        });
    }

    public function down(): void
    {
        if (DB::table('site_menus')->useWritePdo()->exists()) {
            throw new RuntimeException('Remove or preserve the Site menus before reverting their schema.');
        }

        Schema::drop('site_menus');
    }
};
