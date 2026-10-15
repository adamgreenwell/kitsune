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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_pc_';
        Schema::create('commerce_payment_receipts', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'p');
            $table->foreignId('attempt_id')->constrained('commerce_checkout_attempts', indexName: $names.'p_attempt_fk')->restrictOnDelete();
            $table->string('account_id', 100);
            $table->string('mode', 4);
            $table->string('api_version', 40);
            $table->string('session_id', 255);
            $table->string('payment_intent_id', 100);
            $table->string('charge_id', 100);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('allocation_state', 16);
            $table->string('category', 24)->nullable();
            $table->dateTime('verified_at');
            // Org-owned custody claims a payment once across every selling Site in the Org.
            $table->unique(['org_id', 'account_id', 'mode', 'payment_intent_id'], $names.'p_intent_uq');
            $table->unique(['org_id', 'account_id', 'mode', 'charge_id'], $names.'p_charge_uq');
            $table->unique(['org_id', 'order_id'], $names.'p_order_uq');
        });
        Schema::create('commerce_order_allocations', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'l');
            $table->foreignId('line_id')->constrained('commerce_order_lines', indexName: $names.'l_line_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('awarded');
            $table->unsignedSmallInteger('unavailable');
            foreach (['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor'] as $column) {
                $table->unsignedBigInteger('refund_'.$column);
            }
            $table->dateTime('created_at');
            $table->unique(['site_id', 'order_id', 'line_id'], $names.'l_line_uq');
        });
        Schema::create('commerce_refund_obligations', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'o');
            $table->foreignId('payment_id')->constrained('commerce_payment_receipts', indexName: $names.'o_payment_fk')->restrictOnDelete();
            $table->string('reference', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 32);
            $table->dateTime('created_at');
            $table->unique(['site_id', 'order_id'], $names.'o_order_uq');
            $table->unique(['site_id', 'reference'], $names.'o_reference_uq');
        });
        Schema::create('commerce_refund_attempts', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'a');
            $table->foreignId('obligation_id')->constrained('commerce_refund_obligations', indexName: $names.'a_obligation_fk')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('idempotency_key', 100);
            $table->json('request_payload');
            $table->string('request_hash', 64);
            $table->string('state', 16);
            $table->string('refund_id', 100)->nullable();
            $table->string('category', 24)->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->dateTime('first_submitted_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'reference'], $names.'a_reference_uq');
            $table->unique(['site_id', 'idempotency_key'], $names.'a_key_uq');
            $table->index(['site_id', 'obligation_id', 'id'], $names.'a_obligation_idx');
            $table->index(['site_id', 'replay_count', 'id'], $names.'a_replay_idx');
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
        $tables = ['commerce_refund_attempts', 'commerce_refund_obligations', 'commerce_order_allocations', 'commerce_payment_receipts'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained paid custody must be resolved before rollback.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
