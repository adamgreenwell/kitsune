<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_sm_';
        // Org-scoped history reads, including historical SKU/product filters, remain ordered and bounded.
        Schema::table('commerce_stock_movements', function (Blueprint $table) use ($names): void {
            $table->index(['org_id', 'created_at', 'id'], $names.'time_idx');
            $table->index(['org_id', 'sku', 'created_at', 'id'], $names.'sku_time_idx');
            $table->index(['org_id', 'sellable_ref', 'created_at', 'id'], $names.'product_time_idx');
        });
    }

    public function down(): void
    {
        $names = str_replace(['-', '.'], '_', Schema::getConnection()->getTablePrefix()).'commerce_sm_';
        Schema::table('commerce_stock_movements', function (Blueprint $table) use ($names): void {
            $table->dropIndex($names.'time_idx');
            $table->dropIndex($names.'sku_time_idx');
            $table->dropIndex($names.'product_time_idx');
        });
    }
};
