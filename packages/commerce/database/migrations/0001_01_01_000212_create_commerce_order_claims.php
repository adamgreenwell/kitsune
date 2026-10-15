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
        $name = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_oc_';
        Schema::create('commerce_order_claims', function (Blueprint $table) use ($bytes, $name): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $name.'org_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained(indexName: $name.'site_fk')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('commerce_orders', indexName: $name.'order_fk')->restrictOnDelete();
            $bytes ? $table->binary('reader_id', 255) : $table->string('reader_id', 255);
            // Original guest witnesses are immutable; attachment never rewrites the accepted invoice.
            $table->string('owner_key', 64);
            $table->string('order_reference', 32);
            $table->string('snapshot_hash', 64);
            $table->string('proof_kind', 8);
            $table->dateTime('claimed_at');
            $table->unique(['site_id', 'order_id'], $name.'order_uq');
            $table->index(['site_id', 'reader_id', 'id'], $name.'reader_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('commerce_order_claims') && DB::table('commerce_order_claims')->useWritePdo()->exists()) {
            throw new RuntimeException('Retained order claims must be resolved before rollback.');
        }
        Schema::dropIfExists('commerce_order_claims');
    }
};
