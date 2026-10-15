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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_ac_';
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('commerce_access_work', function (Blueprint $table) use ($names, $bytes): void {
            $table->id();
            $this->parents($table, $names.'w');
            $table->string('state', 24);
            $table->string('category', 24)->nullable();
            $table->json('manifest')->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->json('claim_binding')->nullable();
            $table->string('claim_hash', 64)->nullable();
            ($bytes ? $table->binary('claim_reader_id', 255) : $table->string('claim_reader_id', 255))->nullable();
            $table->unsignedSmallInteger('effect_count')->default(0);
            $table->unsignedInteger('replay_count')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'order_id'], $names.'w_order_uq');
            $table->index(['site_id', 'state', 'replay_count', 'id'], $names.'w_replay_idx');
            $table->index(['site_id', 'claim_reader_id', 'id'], $names.'w_reader_idx');
        });
        Schema::create('commerce_access_effects', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'e');
            $table->foreignId('work_id')->constrained('commerce_access_work', indexName: $names.'e_work_fk')->restrictOnDelete();
            $table->string('entitlement', 100);
            $table->string('source', 100);
            $table->dateTime('expires_at')->nullable();
            $table->string('state', 16);
            $table->string('category', 24)->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->unique(['site_id', 'order_id', 'entitlement'], $names.'e_name_uq');
        });
        // The marker exists before any payment or access effect. Recovery never recreates a missing marker.
        // Existing paid orders have one explicit migration-only initialization path; old NULL terms remain unknown.
        DB::table('commerce_orders')->useWritePdo()->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('commerce_access_work')->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'state' => $order->state === 'paid' ? 'initializing' : 'awaiting_payment',
                    'category' => null, 'manifest' => null, 'manifest_hash' => null, 'claim_binding' => null,
                    'claim_hash' => null, 'claim_reader_id' => null, 'effect_count' => 0, 'replay_count' => 0,
                    'created_at' => $order->created_at, 'updated_at' => $order->created_at]);
            }
        });
    }

    private function parents(Blueprint $table, string $name): void
    {
        $table->foreignId('org_id')->constrained(indexName: $name.'_org_fk')->restrictOnDelete();
        $table->foreignId('site_id')->constrained(indexName: $name.'_site_fk')->restrictOnDelete();
        $table->foreignId('order_id')->constrained('commerce_orders', indexName: $name.'_order_fk')->restrictOnDelete();
    }

    public function down(): void
    {
        foreach (['commerce_access_effects', 'commerce_access_work'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained purchased access custody must be resolved before rollback.');
            }
        }
        Schema::dropIfExists('commerce_access_effects');
        Schema::dropIfExists('commerce_access_work');
    }
};
