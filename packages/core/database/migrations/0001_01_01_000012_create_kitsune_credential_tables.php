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
 * An org's credentials, stored encrypted and never read back — ADR-040, the credential store as built.
 *
 * ⚠️ `mode` IS NOT NULL, WITH `'none'` FOR A CREDENTIAL KEPT ONCE WHATEVER THE MODE. NULLs compare distinct on all
 * four engines — the `blueprints` migration's own argument — so a nullable `mode` would let the unique index admit two
 * rows for one credential, and a read would choose between them by row order.
 *
 * ⚠️ AGENTS.md §4. Both unique indexes lead with `org_id` and serve the only lookups there are. No carve-out is
 * claimed: a credential's name is not globally scarce. Laravel's `unique` and `exists` rules are not used anywhere;
 * `CredentialWriter` re-reads under the org's lock, and these constraints are the backstop.
 *
 * ⚠️ `text`, NOT `string`. Measured: with its envelope under AES-256-GCM, a 32-character value seals to 264 characters,
 * a 255-character one to 664, and the longest a credential may declare, 1,024 characters, to 2,032. `string(255)` would
 * overflow before a value reached 32.
 *
 * ⚠️ A REMOVED VALUE KEEPS ITS ROW, ciphertext and key id null, so `credential.removed` always names a target that
 * resolves and "who removed the live key" stays answerable. Nothing deletes a row but the org's own hard delete; an
 * org's soft delete leaves its credentials, encrypted, for its restore.
 *
 * No `created_at`/`updated_at`: `changed_at` is the one time the admin shows, and who did it is `audit_log`'s.
 * Nothing derived from a value is stored — no hash, length, prefix or last four (Adam, 2026-10-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            // A declared credential's name, `commerce.stripe-secret-key`.
            $table->string('slot', 100);
            // 'test' | 'live' | 'none'.
            $table->string('mode', 4);
            // NULL once removed.
            $table->text('ciphertext')->nullable();
            // Which derived key sealed it, so a page can tell current, previous and lost keys apart without decrypting.
            $table->string('key_id', 16)->nullable();
            $table->timestamp('changed_at');

            $table->unique(['org_id', 'slot', 'mode']);
        });

        /*
         * Which of an org's credentials are in force — ADR-040's test and live mode. One row per org, absent meaning
         * test, written only by `CredentialWriter::switchTo()`. Not a column on `orgs`: that model is unscoped and its
         * saves are unaudited, and the mode decides whether real money moves.
         */
        Schema::create('org_credential_modes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            // 'test' | 'live'.
            $table->string('mode', 4);
            $table->timestamp('changed_at');

            $table->unique('org_id');
        });
    }

    /**
     * ⚠️ DESTROYS EVERY STORED CREDENTIAL, of every org, beyond recovery: they can only be entered again from each
     * provider's dashboard. For development only.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_credential_modes');
        Schema::dropIfExists('credentials');
    }
};
