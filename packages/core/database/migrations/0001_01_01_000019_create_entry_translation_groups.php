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
        Schema::create('entry_translation_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid');
            $table->unique(['org_id', 'uuid']);
            // Enables relation targets to reference the group and its owning Org together.
            $table->unique(['org_id', 'id']);
        });

        try {
            // Keep historical identities even if their only members are soft-deleted.
            DB::table('entry_translation_groups')->insertUsing(
                ['org_id', 'uuid'],
                DB::table('entries')->select('org_id', 'translation_group')
                    ->whereNotNull('translation_group')->distinct(),
            );
        } catch (Throwable $exception) {
            // MySQL and MariaDB commit the table creation before the backfill.
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                Schema::drop('entry_translation_groups');
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_translation_groups');
    }
};
