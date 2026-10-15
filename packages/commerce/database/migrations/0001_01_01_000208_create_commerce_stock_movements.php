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
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_sm_';
        Schema::create('commerce_stock_movements', function (Blueprint $table) use ($names): void {
            $table->id();
            $table->foreignId('org_id')->constrained(indexName: $names.'org_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained(indexName: $names.'site_fk')->restrictOnDelete();
            // Historical identity survives product removal. This is not a live catalogue relationship.
            $table->unsignedBigInteger('sellable_ref');
            $table->string('sku', 64);
            $table->string('reference', 32);
            $table->string('kind', 16);
            $table->bigInteger('before_stock')->nullable();
            $table->bigInteger('after_stock')->nullable();
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->string('reason', 500);
            $table->string('actor_type', 255)->nullable();
            $table->string('actor_id', 255)->nullable();
            $table->string('order_reference', 32)->nullable();
            $table->unsignedBigInteger('line_reference')->nullable();
            $table->dateTime('created_at');
            $table->unique(['org_id', 'reference'], $names.'reference_uq');
            $table->unique(['org_id', 'order_reference', 'line_reference'], $names.'sale_uq');
            $table->index(['org_id', 'sellable_ref', 'id'], $names.'sellable_idx');
        });
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->table('commerce_stock_movements')->useWritePdo()->exists()) {
            throw new RuntimeException('Remove retained stock history explicitly before rolling back its store.');
        }
        Schema::connection($this->getConnection())->dropIfExists('commerce_stock_movements');
    }
};
