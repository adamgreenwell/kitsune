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
        Schema::create('reader_erasure_receipts', function (Blueprint $table): void {
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->uuid('anchor');
            $table->timestamp('erased_at')->nullable();
            $table->boolean('host_pending')->default(false);
            $table->primary(['org_id', 'kind', 'anchor']);
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('reader_erasure_receipts')->exists()) {
            throw new RuntimeException('Preserve reader erasure receipts before rolling this migration back.');
        }

        Schema::dropIfExists('reader_erasure_receipts');
    }
};
