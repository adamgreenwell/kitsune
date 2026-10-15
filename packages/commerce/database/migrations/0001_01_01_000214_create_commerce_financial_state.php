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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_fs_';
        Schema::create('commerce_financial_work', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'w');
            $table->string('order_reference', 32);
            $table->unsignedInteger('revision')->default(0);
            $table->string('basis_hash', 64)->nullable();
            $table->unsignedBigInteger('charge_refunded_minor')->nullable();
            $table->boolean('charge_disputed')->nullable();
            $table->dateTime('last_observed_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'order_id'], $names.'w_order_uq');
            $table->index(['site_id', 'replay_count', 'id'], $names.'w_replay_idx');
        });
        Schema::create('commerce_financial_refunds', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'r');
            $table->foreignId('work_id')->constrained('commerce_financial_work', indexName: $names.'r_work_fk')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('commerce_payment_receipts', indexName: $names.'r_payment_fk')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('request_key', 32)->nullable();
            $table->string('origin', 16);
            $table->string('purpose', 16)->default('units');
            $table->string('reason', 500);
            $table->json('selection');
            $table->string('selection_hash', 64);
            $table->string('basis_hash', 64);
            $table->json('authorization')->nullable();
            $table->string('authorization_hash', 64)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('idempotency_key', 100)->nullable();
            $table->json('request_payload')->nullable();
            $table->string('request_digest', 64)->nullable();
            $table->string('credential_witness', 64)->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->string('refund_id', 100)->nullable();
            $table->string('state', 16);
            $table->string('category', 32)->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'reference'], $names.'r_reference_uq');
            $table->unique(['site_id', 'order_id', 'request_key'], $names.'r_request_uq');
            // A provider object belongs to the original payment/account, independently of the selling Site.
            $table->unique(['org_id', 'payment_id', 'refund_id'], $names.'r_provider_uq');
            $table->index(['site_id', 'order_id', 'id'], $names.'r_order_idx');
            $table->index(['site_id', 'replay_count', 'id'], $names.'r_replay_idx');
        });
        Schema::create('commerce_financial_observations', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'o');
            $table->foreignId('work_id')->constrained('commerce_financial_work', indexName: $names.'o_work_fk')->restrictOnDelete();
            $table->string('account_id', 100);
            $table->string('mode', 4);
            $table->string('kind', 16);
            $table->string('object_id', 100);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('state', 32);
            $table->string('origin', 16);
            $table->string('local_reference', 32)->nullable();
            $table->string('request_digest', 64)->nullable();
            $table->dateTime('verified_at');
            $table->unique(['org_id', 'account_id', 'mode', 'kind', 'object_id'], $names.'o_provider_uq');
            $table->index(['site_id', 'order_id', 'id'], $names.'o_order_idx');
        });
        // Mandatory from preparation. Backfill records custody only; no refund, dispute, or access fact is guessed.
        DB::table('commerce_orders')->useWritePdo()->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('commerce_financial_work')->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'order_reference' => $order->reference, 'revision' => 0, 'basis_hash' => null,
                    'charge_refunded_minor' => null, 'charge_disputed' => null, 'last_observed_at' => null,
                    'lease_token' => null, 'lease_until' => null, 'replay_count' => 0,
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
        foreach (['commerce_financial_observations', 'commerce_financial_refunds', 'commerce_financial_work'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained financial adjustment custody must be resolved before rollback.');
            }
        }
        Schema::dropIfExists('commerce_financial_observations');
        Schema::dropIfExists('commerce_financial_refunds');
        Schema::dropIfExists('commerce_financial_work');
    }
};
