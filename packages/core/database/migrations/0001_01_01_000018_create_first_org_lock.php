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
        Schema::create('first_org_lock', function (Blueprint $table): void {
            // An installation-wide lock must exist before its first org. It has no model or org scope.
            $table->unsignedTinyInteger('id')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_org_lock');
    }
};
