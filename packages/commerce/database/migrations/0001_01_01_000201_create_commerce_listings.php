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
        Schema::create('commerce_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('sellable_id')->constrained('commerce_sellables')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained()->restrictOnDelete();
            $table->boolean('is_available')->default(false);
            $table->timestamps();

            // Site-leading lookup/uniqueness indexes; a shared sellable is listed explicitly on each site.
            $table->unique(['site_id', 'sellable_id']);
            $table->unique(['site_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_listings');
    }
};
