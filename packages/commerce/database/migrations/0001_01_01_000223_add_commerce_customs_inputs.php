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
        Schema::table('commerce_settings', function (Blueprint $table): void {
            $table->json('international_shipping')->nullable();
        });
        Schema::table('commerce_sales_policies', function (Blueprint $table): void {
            $table->string('customs_description', 50)->nullable();
            $table->string('customs_origin_country', 2)->nullable();
            $table->string('customs_tariff_number', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_sales_policies', function (Blueprint $table): void {
            $table->dropColumn(['customs_description', 'customs_origin_country', 'customs_tariff_number']);
        });
        Schema::table('commerce_settings', function (Blueprint $table): void {
            $table->dropColumn('international_shipping');
        });
    }
};
