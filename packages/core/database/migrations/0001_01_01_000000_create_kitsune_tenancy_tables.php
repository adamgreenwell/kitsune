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

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orgs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('name');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
            // ADR-021: composite indexes lead with the scope key.
            $table->index(['org_id', 'handle']);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('handle');
            $table->string('name');
            $table->string('locale')->default('en');
            $table->string('url_strategy')->default('path');
            $table->string('base_url')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'handle']);
            $table->index(['org_id', 'site_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
        Schema::dropIfExists('site_groups');
        Schema::dropIfExists('orgs');
    }
};
