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
        Schema::table('erasure_log', function (Blueprint $table): void {
            // Historical row identities remain usable after deletion, so there are no foreign keys to those rows.
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('entry_type_id')->nullable();
            $table->unsignedBigInteger('entry_revision_id')->nullable();
            $table->unsignedBigInteger('field_storage_id')->nullable();
            $table->char('revision_witness', 36)->nullable();
            $table->string('representation', 16)->nullable();
            $table->string('record_target', 12)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('erasure_log')->whereNotNull('entry_revision_id')->exists()) {
            throw new RuntimeException('Preserve retained revision erasure receipts before rolling this migration back.');
        }

        Schema::table('erasure_log', function (Blueprint $table): void {
            $table->dropColumn(['site_id', 'entry_type_id', 'entry_revision_id', 'field_storage_id', 'revision_witness', 'representation', 'record_target']);
        });
    }
};
