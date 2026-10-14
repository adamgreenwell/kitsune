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
            // Shared offer metadata, not a grant. Read by the existing org-scoped product lookup; no new index.
            $table->string('entitlement_name', 100)->nullable();
        });
    }

    public function down(): void
    {
        // A rollback must not silently discard configured offers, including when no module or org context exists.
        // Raw connection access also honours the host's table prefix; a failed query refuses the rollback.
        if (DB::connection($this->getConnection())->table('commerce_sellables')->useWritePdo()->whereNotNull('entitlement_name')->exists()) {
            throw new RuntimeException('Clear every product entitlement mapping explicitly before removing its column.');
        }

        Schema::connection($this->getConnection())->table('commerce_sellables', function (Blueprint $table): void {
            $table->dropColumn('entitlement_name');
        });
    }
};
