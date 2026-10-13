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
use Kitsune\Core\Entitlements\EntitlementName;

/**
 * The entitlement a reader needs to download a file — ADR-041 decision 1 (Adam, 2026-10-11): *Offer to readers*, one name
 * per file, null for a file no reader is offered.
 *
 * ⚠️ WRITTEN IN ONE PLACE. `MediaVisibility::writeRow()` writes it, below the model and under custody's lock, for *Offer
 * to readers*, *Change* and *Stop offering*; every model door refuses it (`MediaFile::columnsFixedAtCreation()`). A
 * value that is not a name reads as no offer (`MediaFile::readerEntitlement()`).
 *
 * ⚠️ NO INDEX: nothing finds files by the name. The download route reads one row by its entry's key, and a list of
 * what a reader may download waits on the batch check (ADR-040's owed list).
 *
 * As wide as the longest name (`EntitlementName::LONGEST`). Nullable, so every row stored before it is offered to no one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', static function (Blueprint $table): void {
            $table->string('reader_entitlement', EntitlementName::LONGEST)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_files', static function (Blueprint $table): void {
            $table->dropColumn('reader_entitlement');
        });
    }
};
