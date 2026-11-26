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
        Schema::table('entry_relations', function (Blueprint $table): void {
            $table->unsignedBigInteger('target_entry_id')->nullable()->change();
            $table->foreignId('target_group_id')->nullable();
            $table->foreign(['org_id', 'target_group_id'], 'entry_relations_group_foreign')
                ->references(['org_id', 'id'])->on('entry_translation_groups')->cascadeOnDelete();
            $table->index(['org_id', 'target_group_id'], 'entry_relations_group_index');
        });
    }

    public function down(): void
    {
        if (DB::table('entry_relations')->useWritePdo()
            ->whereNotNull('target_group_id')->orWhereNull('target_entry_id')->exists()) {
            throw new RuntimeException('Only row pins can remain before reverting the translation-group relation schema.');
        }

        $driver = DB::connection()->getDriverName();
        // InnoDB may replace its implicit Org foreign-key index with the group index.
        if (in_array($driver, ['mysql', 'mariadb'], true) && ! Schema::hasIndex('entry_relations', ['org_id'])) {
            Schema::table('entry_relations', fn (Blueprint $table) => $table->index('org_id', 'entry_relations_org_lookup'));
        }

        $groupKey = $driver === 'sqlite'
            ? ['org_id', 'target_group_id']
            : 'entry_relations_group_foreign';

        Schema::table('entry_relations', function (Blueprint $table) use ($groupKey): void {
            $table->dropForeign($groupKey);
            $table->dropIndex('entry_relations_group_index');
            $table->dropColumn('target_group_id');
            $table->unsignedBigInteger('target_entry_id')->nullable(false)->change();
        });
    }
};
