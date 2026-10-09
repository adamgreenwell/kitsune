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
 * The links core mails to readers — ADR-037, reader accounts' second part, as built: one row per live link, for
 * finishing a sign-up or choosing a new password, made by `ReaderTokens` and used up by deleting it.
 *
 * ⚠️ ONE LIVE LINK PER SUBJECT, KEPT BY THE DATABASE. `unique (org_id, purpose, subject)` means a newer link replaces an
 * older one rather than standing beside it, and `unique (org_id, token_hash)` means a link names one row. The subject is
 * the normalised address for a sign-up (no account exists yet) and the reader's key, as `ReaderGuard::key()` spells it,
 * for a recovery. There is no foreign key to `readers`: core did not create that table and does not control its key.
 *
 * ⚠️ ONLY THE HASH. `token_hash` is the SHA-256 of a 256-bit secret, which is in the mail and nowhere else; a copy of this
 * table opens nothing.
 *
 * ⚠️ AGENTS.md §4. Every index leads with `org_id`, and each serves a door: the subject index the mint and erasure, the
 * hash index the link's use, the expiry index the sweep. Every key column is NOT NULL, for the entitlements migration's
 * reason: NULLs compare distinct, and a unique index over a nullable column admits duplicates.
 *
 * ⚠️ BYTES, NOT A COLLATION, on MySQL and MariaDB, for that migration's reason too: a ULID host's keys, and two
 * addresses one collation would merge, must stay two subjects. PostgreSQL and SQLite compare bytes already.
 *
 * ⚠️ `dateTime`, NEVER `timestamp`, UTC wall clock in whole seconds, written only through `UtcInstant`. No `created_at`.
 *
 * Rows go with their site and their org by cascade. `Site::guardCascade()` does not refuse for them: a link to a deleted
 * site is dead anyway, and nothing a reader holds depends on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('reader_tokens', function (Blueprint $table) use ($bytes): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            // The site the link was mailed from, and the only one where it works.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // `register` or `recover`.
            $table->string('purpose', 8);
            // The normalised address for `register`; the reader's key for `recover`.
            $bytes ? $table->binary('subject', 255) : $table->string('subject', 255);
            // SHA-256, hex, of the secret in the link.
            $table->char('token_hash', 64);
            // Exclusive end, UTC.
            $table->dateTime('expires_at');

            $table->unique(['org_id', 'purpose', 'subject']);
            $table->unique(['org_id', 'token_hash']);
            $table->index(['org_id', 'expires_at']);
        });
    }

    /** Every link waiting to be used, of every org, stops working. For development only. */
    public function down(): void
    {
        Schema::dropIfExists('reader_tokens');
    }
};
