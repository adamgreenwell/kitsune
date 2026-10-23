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
        // Installation-wide, like modules: no Org/Site scope key exists to lead this primary key.
        // No receipt foreign key: the marker must survive removal of the installed receipt.
        Schema::create('module_uninstalls', function (Blueprint $table): void {
            $table->string('handle')->primary();
            $table->string('version');
            $table->timestamp('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_uninstalls');
    }
};
