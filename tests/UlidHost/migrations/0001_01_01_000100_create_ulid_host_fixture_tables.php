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

/*
 * The host-side tables of a host whose users carry ULIDs — #91.
 *
 * ⚠️ SHAPED AS THE SKELETON'S MIGRATIONS ARE, THE KEY TYPE ASIDE, including the constraints: `org_user` cascades and
 * `role_user` restricts, for the reasons `tests/Core/migrations` and the skeleton record. A fixture more forgiving
 * than the shipped schema tests a different application, and this one exists to test the same application on a
 * different host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('email')->unique();
        });

        Schema::create('org_user', function (Blueprint $table): void {
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['org_id', 'user_id']);
            $table->index(['user_id', 'org_id']);
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'user_id']);
            $table->index(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('org_user');
        Schema::dropIfExists('users');
    }
};
