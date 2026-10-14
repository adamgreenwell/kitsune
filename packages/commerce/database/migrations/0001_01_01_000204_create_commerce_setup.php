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
        Schema::create('commerce_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->string('tax_mode', 9);
            $table->string('shipping_tax_mode', 9);
            $table->string('shipping_tax_class', 64);
            $table->string('ship_from_name', 200)->nullable();
            $table->json('ship_from_address')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            // One explicitly configured calculator per org; the only lookup is its org-leading unique key.
            $table->unique('org_id');
        });

        Schema::create('commerce_tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->string('country', 2);
            $table->string('region', 32);
            $table->string('tax_class', 64);
            $table->unsignedInteger('rate_parts');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            // Exact region precedes the explicit '*' fallback. No external country or rate registry is implied.
            $table->unique(['org_id', 'country', 'region', 'tax_class'], 'commerce_tax_rates_org_destination_class_unique');
        });

        Schema::create('commerce_sales_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('sellable_id')->constrained('commerce_sellables')->restrictOnDelete();
            $table->string('tax_class', 64);
            $table->string('delivery_kind', 8);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('tare_grams')->nullable();
            $table->unsignedSmallInteger('units_per_parcel')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            // A SKU shares one tax/packing policy across its org's sites; every lookup leads with org_id.
            $table->unique(['org_id', 'sellable_id']);
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        // Inspect every authoritative table before any DDL, without module/org context and without trusting a replica.
        foreach (['commerce_settings', 'commerce_tax_rates', 'commerce_sales_policies'] as $table) {
            if ($connection->table($table)->useWritePdo()->exists()) {
                throw new RuntimeException('Remove every retained commerce setting, tax rate and sales policy explicitly before rolling back calculator setup.');
            }
        }

        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('commerce_sales_policies');
        $schema->dropIfExists('commerce_tax_rates');
        $schema->dropIfExists('commerce_settings');
    }
};
