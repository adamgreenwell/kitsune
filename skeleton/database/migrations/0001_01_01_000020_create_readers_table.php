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
 * Readers — the people a site's paid or members-only content is for (ADR-037). Never staff: a reader cannot reach a
 * panel.
 *
 * In the skeleton rather than in core, as `users` is: ADR-037 has the host own a reader's guard, provider, model and
 * table, and core reach the row only through `ReaderAccount`. One org each, by their own `org_id` — one identity per
 * org, so the same address in two orgs is two readers with two passwords.
 *
 * ⚠️ THIS FILE IS FROZEN AT `create-project`, so the columns a later feature needs are here now rather than as a
 * migration every existing site would have to copy: `password` may be null (commerce's reader with no password yet),
 * `email_verified_at` is set when a link to the address is used, and `remember_token` waits for remember-me. Nothing
 * else: no name, no locale, no marketing flag (ADR-020's minimisation). No soft deletes: erasure is a hard delete.
 *
 * ⚠️ A PLAIN STRING ON EVERY ENGINE, NOT `varbinary`. Every write and lookup passes `EmailAddress::normalise()` — the
 * HTML `type=email` grammar, which is ASCII with no space, lower-cased — so nothing a MySQL or MariaDB collation folds
 * (case, accents, trailing spaces) can tell two stored values apart, and all four engines compare alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readers', function (Blueprint $table): void {
            // Never re-issued on the four engines, so an erased reader's session and entitlement rows name nobody new.
            $table->id();
            // An org's soft delete keeps its readers, for its restore; its hard delete takes them.
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // AGENTS.md §4: led by the scope key. The lookup index too, and the backstop for two sign-ups at once.
            $table->unique(['org_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readers');
    }
};
