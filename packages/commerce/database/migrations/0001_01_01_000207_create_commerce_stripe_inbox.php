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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_si_';
        Schema::create('commerce_checkout_routing', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'r_org_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained(indexName: $names.'r_site_fk')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders', indexName: $names.'r_order_fk')->restrictOnDelete();
            $table->foreignId('attempt_id')->constrained('commerce_checkout_attempts', indexName: $names.'r_attempt_fk')->restrictOnDelete();
            $table->string('order_reference', 32);
            $table->string('attempt_reference', 32);
            $table->string('account_id', 100);
            $table->string('mode', 4);
            $table->dateTime('created_at');
            // This receipt is org-owned and routes across its sites. The original SiteScoped indexes stay unchanged.
            $table->unique(['org_id', 'attempt_id'], $names.'r_attempt_uq');
            $table->index(['org_id', 'order_reference', 'attempt_reference'], $names.'r_hint_idx');
            $table->index(['org_id', 'order_id', 'id'], $names.'r_order_idx');
        });
        Schema::create('commerce_stripe_events', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'e_org_fk')->restrictOnDelete();
            // Checked erasure nulls every correlation atomically; restrictive keys prevent a silent cascade.
            $table->foreignId('site_id')->nullable()->constrained(indexName: $names.'e_site_fk')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('commerce_orders', indexName: $names.'e_order_fk')->restrictOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('commerce_checkout_attempts', indexName: $names.'e_attempt_fk')->restrictOnDelete();
            $table->string('event_id', 255);
            $table->string('event_type', 64);
            $table->string('api_version', 40);
            $table->string('session_id', 255)->nullable();
            $table->string('account_id', 100);
            $table->string('mode', 4);
            $table->string('order_reference', 32)->nullable();
            $table->string('attempt_reference', 32)->nullable();
            $table->string('payload_hash', 64);
            $table->longText('raw_payload')->nullable();
            $table->string('state', 16);
            $table->unsignedInteger('replay_count')->default(0);
            $table->string('category', 24)->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('verified_at');
            $table->dateTime('received_at');
            $table->dateTime('raw_expires_at');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('updated_at');
            $table->unique(['org_id', 'account_id', 'mode', 'event_id'], $names.'e_identity_uq');
            $table->index(['org_id', 'state', 'id'], $names.'e_state_idx');
            $table->index(['org_id', 'replay_count', 'id'], $names.'e_replay_idx');
            $table->index(['org_id', 'raw_expires_at', 'id'], $names.'e_expiry_idx');
            $table->index(['org_id', 'order_id', 'id'], $names.'e_order_idx');
            $table->index(['org_id', 'mode', 'event_id'], $names.'e_event_idx');
        });
        // Old Sessions already carry these two immutable hints. Backfill their small routing receipt without
        // changing any old provider request/hash or introducing an org-leading index on SiteScoped attempts.
        DB::table('commerce_checkout_attempts')->useWritePdo()->select(['id', 'org_id', 'site_id', 'order_id', 'reference', 'account_id', 'mode', 'created_at'])->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $order = DB::table('commerce_orders')->useWritePdo()->where('id', $row->order_id)->first();
                $site = DB::table('sites')->useWritePdo()->where('id', $row->site_id)->where('org_id', $row->org_id)->first();
                if ($order === null || $site === null || (string) $order->org_id !== (string) $row->org_id
                    || (string) $order->site_id !== (string) $row->site_id || $row->mode !== 'test'
                    || preg_match('/\A[a-f0-9]{32}\z/D', $row->reference) !== 1
                    || preg_match('/\A[a-f0-9]{32}\z/D', $order->reference) !== 1
                    || preg_match('/\Aacct_[A-Za-z0-9_]{1,95}\z/D', $row->account_id) !== 1) {
                    throw new RuntimeException('Existing Stripe routing custody is inconsistent.');
                }
                DB::table('commerce_checkout_routing')->insert(['org_id' => $row->org_id, 'site_id' => $row->site_id,
                    'order_id' => $row->order_id, 'attempt_id' => $row->id, 'order_reference' => $order->reference,
                    'attempt_reference' => $row->reference, 'account_id' => $row->account_id, 'mode' => $row->mode,
                    'created_at' => $row->created_at]);
            }
        });
    }

    public function down(): void
    {
        foreach (['commerce_stripe_events', 'commerce_checkout_routing'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Retained Stripe custody must be resolved before rollback.');
            }
        }
        Schema::dropIfExists('commerce_stripe_events');
        Schema::dropIfExists('commerce_checkout_routing');
    }
};
