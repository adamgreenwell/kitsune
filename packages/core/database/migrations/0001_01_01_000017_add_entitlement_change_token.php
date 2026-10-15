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

/** A new identity for every actual write, so same-second manual revokes cannot reuse a producer witness. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlements', static function (Blueprint $table): void {
            // The empty sentinel permits older/raw inserts, but verified producer doors refuse it. The writer
            // always supplies a fresh opaque token; every retained row gets its own token below.
            $table->string('change_token', 64)->default('');
        });
        DB::table('entitlements')->orderBy('id')->chunkById(100, static function ($rows): void {
            foreach ($rows as $row) {
                DB::table('entitlements')->where('id', $row->id)->update(['change_token' => bin2hex(random_bytes(32))]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('entitlements')->exists()) {
            throw new RuntimeException('Entitlement write identities cannot be removed while retained access exists.');
        }
        Schema::table('entitlements', static fn (Blueprint $table) => $table->dropColumn('change_token'));
    }
};
