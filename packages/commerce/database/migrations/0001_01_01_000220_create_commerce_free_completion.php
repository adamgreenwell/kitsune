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
        Schema::create('commerce_free_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('request_hash', 64);
            $table->string('order_hash', 64);
            $table->string('purchase_hash', 64);
            $table->string('allocation_hash', 64);
            $table->dateTime('completed_at');
            $table->unique(['site_id', 'order_id']);
            $table->unique(['site_id', 'reference']);
        });
        Schema::create('commerce_free_access_work', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->string('head_hash', 64);
            $table->unique(['site_id', 'order_id']);
        });
        Schema::create('commerce_free_access_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('request_key', 32);
            $table->unsignedInteger('revision');
            $table->string('action', 8);
            $table->json('selection');
            $table->string('reason', 500);
            $table->string('previous_hash', 64);
            $table->string('record_hash', 64);
            $table->dateTime('created_at');
            $table->unique(['site_id', 'order_id', 'revision'], 'free_access_revision_unique');
            $table->unique(['site_id', 'request_key'], 'free_access_request_unique');
        });
    }

    public function down(): void
    {
        foreach (['commerce_free_access_corrections', 'commerce_free_access_work', 'commerce_free_completions'] as $table) {
            if (DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retire every free order explicitly before rolling back free completion.');
            }
        }
        Schema::dropIfExists('commerce_free_access_corrections');
        Schema::dropIfExists('commerce_free_access_work');
        Schema::dropIfExists('commerce_free_completions');
    }
};
