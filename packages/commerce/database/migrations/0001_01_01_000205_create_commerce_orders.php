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
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('commerce_orders', function (Blueprint $table) use ($bytes): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('reference', 32);
            $bytes ? $table->binary('reader_id', 255)->nullable() : $table->string('reader_id', 255)->nullable();
            $table->string('owner_key', 64);
            $table->string('contact_email', 255);
            // Historical references survive cart pruning and catalogue removal; they are not live relationships.
            $table->unsignedBigInteger('cart_ref');
            $table->unsignedInteger('cart_version');
            $table->string('request_key', 64);
            $table->string('request_digest', 64);
            $table->string('snapshot_hash', 64);
            // One prepared order per exact owned cart revision; cancellation releases this claim explicitly.
            $table->string('active_cart_key', 64)->nullable();
            $table->string('state', 16);
            $table->string('mode', 4);
            $table->string('currency', 3);
            // The order keeps its own witness even when every sellable is removed or the module is disabled.
            $table->foreign(['org_id', 'currency'])->references(['id', 'currency'])->on('orgs')->restrictOnUpdate()->restrictOnDelete();
            $table->string('tax_mode', 16);
            $table->json('billing');
            $table->json('delivery')->nullable();
            $table->json('shipping')->nullable();
            $table->json('site_snapshot');
            $table->bigInteger('quoted_item_net_minor');
            $table->bigInteger('quoted_shipping_net_minor');
            $table->bigInteger('quoted_tax_minor');
            $table->bigInteger('quoted_total_minor');
            // No writer in this slice can set confirmed money or claim payment/fulfilment.
            $table->bigInteger('confirmed_total_minor')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->unique(['site_id', 'reference']);
            $table->unique(['site_id', 'request_key']);
            $table->unique(['site_id', 'active_cart_key']);
            $table->index(['site_id', 'reader_id']);
            $table->index(['site_id', 'owner_key', 'id']);
            $table->index(['site_id', 'state', 'id']);
        });
        Schema::create('commerce_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('sellable_ref');
            $table->unsignedBigInteger('listing_ref');
            $table->unsignedBigInteger('entry_ref');
            $table->string('sku', 100);
            $table->string('title', 255);
            $table->unsignedSmallInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->string('tax_class', 64);
            $table->unsignedInteger('tax_rate_parts');
            $table->string('delivery_kind', 16);
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('gross_minor');
            $table->string('entitlement_name', 100)->nullable();
            // NULL is deliberately unknown. A badge's entitlement name is not a purchase duration or grant.
            $table->json('offer_terms')->nullable();
            $table->index(['site_id', 'order_id', 'id']);
        });
        Schema::create('commerce_order_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
            $table->dateTime('consumed_at')->nullable();
            $table->unique(['site_id', 'token_hash']);
            $table->index(['site_id', 'order_id', 'id']);
            $table->index(['site_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        foreach (['commerce_orders', 'commerce_order_lines', 'commerce_order_access_tokens'] as $table) {
            if ($connection->table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Remove every retained order, line and access token explicitly before rolling back orders.');
            }
        }
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('commerce_order_access_tokens');
        $schema->dropIfExists('commerce_order_lines');
        $schema->dropIfExists('commerce_orders');
    }
};
