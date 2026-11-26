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
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('reader_consent_events', function (Blueprint $table) use ($bytes): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Reader models are owned by the host. Compare their keys byte for byte, as reader tokens do.
            $bytes ? $table->binary('reader_key', 255) : $table->string('reader_key', 255);
            $table->string('purpose', 20);
            $table->string('notice_version', 64);
            $table->boolean('granted');
            $table->dateTime('recorded_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('retain_until');

            $table->index(['org_id', 'reader_key', 'site_id', 'purpose', 'id'], 'reader_consent_lookup');
            $table->index(['org_id', 'retain_until'], 'reader_consent_retention');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('reader_consent_events') && Schema::getConnection()->table('reader_consent_events')->exists()) {
            throw new RuntimeException('Remove or preserve retained reader consent records before rolling this migration back.');
        }

        Schema::dropIfExists('reader_consent_events');
    }
};
