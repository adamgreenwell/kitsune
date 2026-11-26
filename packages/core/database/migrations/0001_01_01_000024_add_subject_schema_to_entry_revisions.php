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
        Schema::table('entry_revisions', function (Blueprint $table): void {
            // Legacy revisions have no witnessed nomination. There is no historical schema to backfill from.
            $table->json('subject_schema')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entry_revisions', function (Blueprint $table): void {
            $table->dropColumn('subject_schema');
        });
    }
};
