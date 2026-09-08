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
 * Org membership, as `architecture.md` §3 specifies it.
 *
 * In the skeleton rather than in core, alongside `site_user`, because it
 * references the host application's `users` table — core does not own the
 * user model and cannot constrain a table it did not create.
 *
 * This is what makes `User` org-scopable at all: membership is many-to-many,
 * so `OrgScope`'s `org_id = current` never applied and the model has been
 * honestly `#[Unscoped]` until now (issue #21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_user', function (Blueprint $table): void {
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['org_id', 'user_id']);

            // The scope reads the other direction — "is this user in that
            // org?" — and the primary key's leading column cannot serve it.
            $table->index(['user_id', 'org_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_user');
    }
};
