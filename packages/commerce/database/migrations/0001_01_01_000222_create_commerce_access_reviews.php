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
use Kitsune\Commerce\Orders\HistoricalAccess;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(HistoricalAccess::WORK, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('head_hash', 64);
            $table->unique(['site_id', 'order_id']);
        });
        Schema::create(HistoricalAccess::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('commerce_order_lines')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('request_key', 32);
            $table->unsignedInteger('revision');
            $table->string('decision', 8);
            $table->json('terms')->nullable();
            $table->string('actor_type', 255)->nullable();
            $table->string('actor_id', 255)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('evidence', 255)->nullable();
            $table->string('notes_hash', 64);
            $table->string('previous_hash', 64);
            $table->string('record_hash', 64);
            $table->dateTime('created_at');
            $table->dateTime('redacted_at')->nullable();
            $table->unique(['site_id', 'order_id', 'revision'], 'access_review_revision_unique');
            $table->unique(['site_id', 'request_key'], 'access_review_request_unique');
        });
        // Explicit zero-review custody for historical orders. Missing parents never mean no reviewed decision.
        DB::table('commerce_orders')->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::table(HistoricalAccess::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'revision' => 0, 'head_hash' => HistoricalAccess::genesis($order)]);
            }
        });
    }

    public function down(): void
    {
        foreach ([HistoricalAccess::TABLE, HistoricalAccess::WORK] as $table) {
            if (DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retire all order review custody explicitly before rollback.');
            }
        }
        Schema::dropIfExists(HistoricalAccess::TABLE);
        Schema::dropIfExists(HistoricalAccess::WORK);
    }
};
