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
        $foreign = $this->physicalName('entry_relations_group_foreign');
        $index = $this->physicalName('entry_relations_group_index');

        Schema::table('entry_relations', function (Blueprint $table) use ($foreign, $index): void {
            $table->unsignedBigInteger('target_entry_id')->nullable()->change();
            $table->foreignId('target_group_id')->nullable();
            $table->foreign(['org_id', 'target_group_id'], $foreign)
                ->references(['org_id', 'id'])->on('entry_translation_groups')->cascadeOnDelete();
            $table->index(['org_id', 'target_group_id'], $index);
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
            $orgLookup = $this->physicalName('entry_relations_org_lookup');
            Schema::table('entry_relations', fn (Blueprint $table) => $table->index('org_id', $orgLookup));
        }

        $groupKey = $driver === 'sqlite'
            ? ['org_id', 'target_group_id']
            : $this->physicalName('entry_relations_group_foreign');
        $index = $this->physicalName('entry_relations_group_index');

        Schema::table('entry_relations', function (Blueprint $table) use ($groupKey, $index): void {
            $table->dropForeign($groupKey);
            $table->dropIndex($index);
            $table->dropColumn('target_group_id');
            $table->unsignedBigInteger('target_entry_id')->nullable(false)->change();
        });
    }

    private function physicalName(string $name): string
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        // Constraints and indexes can share a database namespace across installations. A short ASCII suffix keeps
        // their names distinct even when prefixes contain Unicode or punctuation.
        return $prefix === '' ? $name : $name.'_'.substr(hash('sha256', $prefix), 0, 24);
    }
};
