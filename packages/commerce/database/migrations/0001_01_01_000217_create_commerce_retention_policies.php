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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_rp_';
        Schema::create('commerce_retention_policies', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'org_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->json('billing_fields');
            $table->string('legal_basis', 500);
            $table->string('reason', 2000);
            $table->string('clock', 32);
            $table->unsignedInteger('duration');
            $table->string('timezone', 64)->nullable();
            $table->string('config_hash', 64);
            $table->dateTime('created_at');
            $table->unique(['org_id', 'version'], $names.'version_uq');
        });
        Schema::create('commerce_retention_policy_activations', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'a_org_fk')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('commerce_retention_policies', indexName: $names.'a_policy_fk')->restrictOnDelete();
            $table->unsignedInteger('policy_version');
            $table->string('config_hash', 64);
            $table->dateTime('activated_at');
            $table->unique(['org_id'], $names.'a_org_uq');
        });
    }

    public function down(): void
    {
        foreach (['commerce_retention_policy_activations', 'commerce_retention_policies'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained operator policy versions must be resolved before rollback.');
            }
        }
        Schema::dropIfExists('commerce_retention_policy_activations');
        Schema::dropIfExists('commerce_retention_policies');
    }
};
