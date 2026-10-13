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

/**
 * An org's explicit currency selection (ADR-040); no default and no business records in core.
 *
 * The unique pair is a foreign-key parent, not a lookup index: optional modules may retain a witness of the
 * selected currency and RESTRICT a change while records using it remain, even with their code disabled or absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orgs', static function (Blueprint $table): void {
            $table->string('currency', 3)->nullable();
            $table->unique(['id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('orgs', static function (Blueprint $table): void {
            $table->dropUnique(['id', 'currency']);
            $table->dropColumn('currency');
        });
    }
};
