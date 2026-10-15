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
        // The default Session index is already 63 characters; a valid table prefix exceeds MySQL's 64-character cap.
        // Keep the prefix on explicit short names so two installations also have distinct foreign-key names.
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_ca_';
        Schema::create('commerce_checkout_attempts', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'org_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained(indexName: $names.'site_fk')->restrictOnDelete();
            // An order must not disappear while a remote Session may still accept payment.
            $table->foreignId('order_id')->constrained('commerce_orders', indexName: $names.'order_fk')->restrictOnDelete();
            $table->string('reference', 32);
            // Checked writers set this to order_id while an attempt remains remotely actionable or uncertain.
            $table->unsignedBigInteger('active_order_id')->nullable();
            $table->string('mode', 4);
            $table->string('currency', 3);
            $table->string('account_id', 100);
            $table->string('api_version', 40);
            $table->string('credential_witness', 64);
            $table->string('state', 16);
            $table->json('request_payload');
            $table->string('request_hash', 64);
            $table->string('order_hash', 64);
            $table->string('session_id', 255)->nullable();
            $table->string('checkout_url', 4096)->nullable();
            $table->dateTime('reviewed_at');
            $table->dateTime('expires_at');
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['site_id', 'reference'], $names.'reference_uq');
            $table->unique(['site_id', 'active_order_id'], $names.'active_uq');
            $table->unique(['site_id', 'account_id', 'session_id'], $names.'session_uq');
            $table->index(['site_id', 'order_id', 'id'], $names.'order_idx');
            $table->index(['site_id', 'state', 'id'], $names.'state_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('commerce_checkout_attempts') && DB::table('commerce_checkout_attempts')->useWritePdo()->exists()) {
            throw new RuntimeException('Retained checkout attempts must be resolved and removed before rollback.');
        }
        Schema::dropIfExists('commerce_checkout_attempts');
    }
};
