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
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->uuid('erasure_anchor')->nullable();
            $table->unique(['org_id', 'erasure_anchor']);
        });

        $grants = Schema::getConnection()->table('entitlements');
        $grants->select('id')->whereNull('erasure_anchor')->chunkById(500, static function ($rows) use ($grants): void {
            foreach ($rows as $row) {
                (clone $grants)->where('id', $row->id)->whereNull('erasure_anchor')->update([
                    'erasure_anchor' => Str::uuid()->toString(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('entitlements')->whereNotNull('erasure_anchor')->exists()
            || Schema::getConnection()->table('reader_erasure_receipts')->where('kind', 'grant')->exists()) {
            throw new RuntimeException('Preserve reader erasure anchors before rolling this migration back.');
        }

        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dropUnique(['org_id', 'erasure_anchor']);
            $table->dropColumn('erasure_anchor');
        });
    }
};
