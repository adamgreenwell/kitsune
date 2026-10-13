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
        Schema::create('commerce_sellables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            // A derived witness of Org.currency, never an independently selectable product currency.
            // RESTRICT keeps this protection effective while the optional module is disabled or absent.
            $table->string('currency', 3);
            $table->string('sku', 64);
            $table->bigInteger('price_minor');
            $table->bigInteger('stock')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'sku']);
            $table->index(['org_id', 'currency']);
            $table->foreign(['org_id', 'currency'])->references(['id', 'currency'])->on('orgs')
                ->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_sellables');
    }
};
