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
        Schema::table('sites', function (Blueprint $table): void {
            // This key lets child records constrain Site ownership as a pair.
            $table->unique(['org_id', 'id'], 'sites_org_id_id_unique');
        });

        Schema::create('import_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->foreignId('site_id');
            $table->string('source_key', 96);
            $table->unsignedBigInteger('active_execution_id')->nullable();
            $table->foreign(['org_id', 'site_id'], 'import_source_site_fk')
                ->references(['org_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'source_key']);
            $table->unique(['site_id', 'org_id', 'id']);
        });

        Schema::create('import_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->foreignId('site_id');
            $table->foreignId('import_source_id');
            $table->string('plan_digest', 64);
            $table->string('state', 10)->default('open');
            $table->unique(['site_id', 'import_source_id', 'plan_digest'], 'import_execution_plan_unique');
            $table->unique(['site_id', 'org_id', 'import_source_id', 'id'], 'import_execution_source_unique');
            $table->foreign(['site_id', 'org_id', 'import_source_id'], 'import_execution_source_fk')
                ->references(['site_id', 'org_id', 'id'])->on('import_sources')->cascadeOnDelete();
        });

        Schema::create('migration_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->foreignId('site_id');
            $table->foreignId('import_source_id');
            $table->string('source_type', 96);
            $table->string('source_id_hash', 64);
            // Keep the destination ID after deletion so a retry can report the lost mapping target.
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('entry_revision_id');
            $table->string('entry_state_hash', 64);
            $table->unsignedBigInteger('last_execution_id');
            $table->unique(['site_id', 'import_source_id', 'source_type', 'source_id_hash'], 'migration_map_source_unique');
            $table->foreign(['site_id', 'org_id', 'import_source_id', 'last_execution_id'], 'migration_map_execution_fk')
                ->references(['site_id', 'org_id', 'import_source_id', 'id'])->on('import_executions')->cascadeOnDelete();
        });

        Schema::create('import_action_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->foreignId('site_id');
            $table->foreignId('import_source_id');
            $table->foreignId('import_execution_id');
            $table->unsignedInteger('position');
            $table->string('outcome', 10);
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('entry_revision_id');
            $table->unique(['site_id', 'import_execution_id', 'position'], 'import_action_receipt_position_unique');
            $table->foreign(['site_id', 'org_id', 'import_source_id', 'import_execution_id'], 'import_action_receipt_execution_fk')
                ->references(['site_id', 'org_id', 'import_source_id', 'id'])->on('import_executions')->cascadeOnDelete();
        });

        Schema::create('import_media_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->foreignId('site_id');
            $table->foreignId('import_source_id');
            $table->foreignId('import_execution_id');
            $table->string('source_type', 96);
            $table->string('source_id_hash', 64);
            $table->string('descriptor_sha256', 64);
            $table->string('disk');
            $table->string('path', 100)->nullable();
            $table->string('managed_path', 100);
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('content_sha256', 64);
            $table->unsignedBigInteger('source_size_bytes');
            $table->boolean('staged')->default(false);
            $table->text('staged_object_version')->nullable();
            $table->boolean('staging_purged')->default(false);
            $table->unique(['site_id', 'import_execution_id', 'source_type', 'source_id_hash'], 'import_media_execution_asset_unique');
            $table->index(['site_id', 'import_source_id', 'source_type', 'source_id_hash'], 'import_media_source');
            $table->unique(['site_id', 'path'], 'import_media_path_unique');
            $table->foreign(['site_id', 'org_id', 'import_source_id', 'import_execution_id'], 'import_media_execution_fk')
                ->references(['site_id', 'org_id', 'import_source_id', 'id'])->on('import_executions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('import_sources') && Schema::getConnection()->table('import_sources')->exists()) {
            throw new RuntimeException('Preserve the retained import journal before rolling back its tables.');
        }

        Schema::dropIfExists('import_media_reservations');
        Schema::dropIfExists('import_action_receipts');
        Schema::dropIfExists('migration_map');
        Schema::dropIfExists('import_executions');
        Schema::dropIfExists('import_sources');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique('sites_org_id_id_unique');
        });
    }
};
