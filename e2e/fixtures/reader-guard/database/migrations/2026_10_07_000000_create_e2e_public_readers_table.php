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

/** The browser suite's readers: one org each, by their own `org_id`, as ADR-037 has a host's readers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e2e_public_readers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2e_public_readers');
    }
};
