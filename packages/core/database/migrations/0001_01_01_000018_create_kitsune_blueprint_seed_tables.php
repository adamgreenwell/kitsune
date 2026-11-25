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
        Schema::create('blueprint_seeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blueprint_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // Retain identity after site deletion. A reused handle grants no recovery authority.
            $table->unsignedBigInteger('original_site_id');
            $table->string('locale');
            $table->string('version');
            $table->json('manifest');
            $table->timestamp('seeded_at');
            // Every scoped receipt lookup leads with org_id (AGENTS §4).
            $table->unique(['org_id', 'blueprint_id', 'original_site_id'], 'blueprint_seeds_org_blueprint_site_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_seeds');
    }
};
