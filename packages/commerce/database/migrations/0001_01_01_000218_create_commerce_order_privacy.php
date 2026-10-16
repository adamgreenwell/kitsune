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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_priv_';
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('contact_email', 255)->nullable()->change();
            $table->json('billing')->nullable()->change();
            $table->unsignedBigInteger('cart_ref')->nullable()->change();
            $table->unsignedInteger('cart_version')->nullable()->change();
        });
        Schema::create('commerce_order_privacy', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'w');
            $table->string('order_reference', 32);
            $table->json('policy_snapshot')->nullable();
            $table->string('policy_hash', 64)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('retained_until')->nullable();
            $table->dateTime('subject_erased_at')->nullable();
            $table->dateTime('invoice_expired_at')->nullable();
            $table->string('subject_key', 64)->nullable();
            $table->json('commitments')->nullable();
            $table->string('commitments_hash', 64)->nullable();
            $table->string('order_representation_hash', 64)->nullable();
            $table->string('safe_financial_hash', 64)->nullable();
            $table->string('access_representation_hash', 64)->nullable();
            $table->string('adjustment_representation_hash', 64)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('maintenance_count')->default(0);
            $table->json('guard_keys')->nullable();
            $table->json('retained_billing_fields')->nullable();
            $table->string('guard_set_hash', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'order_id'], $names.'w_order_uq');
            $table->index(['site_id', 'retained_until', 'id'], $names.'w_due_idx');
            $table->index(['site_id', 'maintenance_count', 'id'], $names.'w_fair_idx');
        });
        Schema::create('commerce_privacy_transitions', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'t');
            $table->foreignId('work_id')->constrained('commerce_order_privacy', indexName: $names.'t_work_fk')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('action', 32);
            $table->string('reason', 500)->nullable();
            $table->string('policy_before_hash', 64)->nullable();
            $table->string('policy_after_hash', 64)->nullable();
            $table->unsignedInteger('revision');
            $table->string('before_hash', 64);
            $table->string('after_hash', 64);
            $table->dateTime('created_at');
            $table->unique(['site_id', 'reference'], $names.'t_ref_uq');
            $table->unique(['site_id', 'work_id', 'revision'], $names.'t_rev_uq');
        });
        Schema::create('commerce_privacy_deliveries', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'d');
            $table->foreignId('work_id')->constrained('commerce_order_privacy', indexName: $names.'d_work_fk')->restrictOnDelete();
            $table->string('state', 12);
            $table->json('payload')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('reason', 500);
            $table->dateTime('review_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'order_id'], $names.'d_order_uq');
        });
        Schema::create('commerce_privacy_holds', function (Blueprint $table) use ($names): void {
            $table->id();
            $this->parents($table, $names.'h');
            $table->foreignId('work_id')->constrained('commerce_order_privacy', indexName: $names.'h_work_fk')->restrictOnDelete();
            $table->string('reference', 32);
            $table->string('reason', 500);
            $table->dateTime('review_at');
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 500)->nullable();
            $table->dateTime('created_at');
            $table->unique(['site_id', 'reference'], $names.'h_ref_uq');
        });
        Schema::create('commerce_privacy_tombstones', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'g_org_fk')->restrictOnDelete();
            $table->string('mode', 4);
            $table->string('kind', 12);
            $table->string('digest', 64);
            $table->string('account_digest', 64);
            $table->string('custody_digest', 64);
            $table->dateTime('subject_erased_at')->nullable();
            $table->dateTime('invoice_expired_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->json('commitments');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            // Org-owned replay identities deliberately outlive the removed order and Site relationships.
            $table->unique(['org_id', 'mode', 'kind', 'digest', 'account_digest', 'custody_digest'], $names.'g_identity_uq');
        });
        // Existing orders receive only an absence-detecting marker. Policy adoption is an explicit owner action.
        DB::table('commerce_orders')->useWritePdo()->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('commerce_order_privacy')->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'order_reference' => $order->reference,
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
        foreach (['commerce_privacy_tombstones', 'commerce_privacy_holds', 'commerce_privacy_deliveries', 'commerce_privacy_transitions', 'commerce_order_privacy'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained privacy custody must be resolved before rollback.');
            }
        }
        foreach (['commerce_privacy_tombstones', 'commerce_privacy_holds', 'commerce_privacy_deliveries', 'commerce_privacy_transitions', 'commerce_order_privacy'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('contact_email', 255)->nullable(false)->change();
            $table->json('billing')->nullable(false)->change();
            $table->unsignedBigInteger('cart_ref')->nullable(false)->change();
            $table->unsignedInteger('cart_version')->nullable(false)->change();
        });
    }
};
