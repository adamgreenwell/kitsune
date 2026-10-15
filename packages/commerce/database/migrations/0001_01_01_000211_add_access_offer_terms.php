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
        Schema::table('commerce_sellables', function (Blueprint $table): void {
            // One shared offer per org-owned product. Existing mapped products remain unknown, never permanent.
            // Reads use the existing org-scoped product lookup; this metadata needs no additional index.
            $table->json('offer_terms')->nullable();
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        if ($connection->table('commerce_sellables')->useWritePdo()->whereNotNull('offer_terms')->exists()
            || $connection->table('commerce_order_lines')->useWritePdo()->whereNotNull('offer_terms')->exists()) {
            throw new RuntimeException('Retained purchased access terms must be resolved before rollback.');
        }
        Schema::connection($this->getConnection())->table('commerce_sellables', function (Blueprint $table): void {
            $table->dropColumn('offer_terms');
        });
    }
};
