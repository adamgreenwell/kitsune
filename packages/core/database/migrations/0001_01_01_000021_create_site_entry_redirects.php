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
        // An entry can have no Site. The portable foreign key below looks up the target
        // with its Org key, and public entry lookups continue to use the Site-leading indexes.
        Schema::table('entries', fn (Blueprint $table) => $table->unique(['org_id', 'id'], 'entries_org_identity'));

        Schema::create('site_entry_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('target_entry_id');
            $table->string('source_type', 64);
            $table->string('source_slug');
            $table->char('source_key', 64);
            $table->timestamps();

            $table->unique(['site_id', 'source_key'], 'site_entry_redirects_source');
            // Supports foreign-key cleanup across Site moves.
            $table->index(['org_id', 'target_entry_id'], 'site_entry_redirects_target_lookup');
            $table->foreign(['org_id', 'target_entry_id'], 'site_entry_redirects_target_fk')
                ->references(['org_id', 'id'])->on('entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('site_entry_redirects')->useWritePdo()->exists()) {
            throw new RuntimeException('Remove or preserve the Site entry redirects before reverting their schema.');
        }

        Schema::drop('site_entry_redirects');
        Schema::table('entries', fn (Blueprint $table) => $table->dropUnique('entries_org_identity'));
    }
};
