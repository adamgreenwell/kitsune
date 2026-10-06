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
 * What a reader may reach, per site — ADR-040, entitlements in core as built: one row per (site, reader, entitlement,
 * source), so a refund revokes only what its own source gave (Adam, 2026-10-06).
 *
 * ⚠️ AGENTS.md §4. The one index leads with `site_id`, and no carve-out is claimed. It serves every lookup there is:
 * the check reads a prefix of three (every source at once), the writer's re-read all four (one source), the site
 * cascade guard a prefix of one, export and erasure a prefix of two. Laravel's generated name,
 * `entitlements_site_id_reader_id_entitlement_source_unique`, is 56 characters: under PostgreSQL's 63 and MySQL's 64.
 *
 * ⚠️ EVERY KEY COLUMN IS NOT NULL, `source` included. NULLs compare distinct on all four engines, so a unique index over
 * a nullable column admits duplicates; every grant names a source, so "no source" is not a value. `site_id` is NOT NULL
 * for a second reason: `EnforcesScope` accepts an explicit null as org-shared, and `SiteScope` would then show the row
 * on every site of the org — one grant made org-wide, reversing ADR-037. `org_id` is carried because `SiteScope`'s SQL
 * names it, and it is a second fence on the check: `SiteScope`'s site branch never compares the row's org.
 *
 * ⚠️ BYTES, NOT A COLLATION, on MySQL and MariaDB — `MySqlDriver`'s reasoning. `reader_id` is the host's identifier,
 * with no FK: core did not create the readers' table and does not control its key type. Under those engines' default
 * collation `Ab`, `ab` and `ab ` are one value, which in an access check is one reader answering for another; so it is
 * `varbinary(255)` there, as wide as `audit_log.actor_id`. `source` is `varbinary(100)` for the same reason: its
 * reference is a producer's case-sensitive id, and two orders differing only in case must be two rows, or refunding one
 * revokes both. PostgreSQL and SQLite compare bytes already. `entitlement` stays `varchar(100)`: its grammar admits only
 * `[a-z0-9.-]`, so no collation can merge two names.
 *
 * ⚠️ `dateTime`, NEVER `timestamp`. `expires_at` is the first core column holding a FUTURE instant, and MySQL's
 * `TIMESTAMP` ends on 2038-01-19. All three instants share one type and hold UTC wall clock, written only through
 * `UtcInstant`, so none passes through MySQL's session `time_zone` while another does not, and SQLite's text comparison
 * only ever meets `Y-m-d H:i:s`.
 *
 * No `created_at`/`updated_at`, no `granted_at`, no order id or granter: the source says from what; when and at whose
 * hand are `audit_log`'s. Rows are deleted only by the org's hard delete, a site's delete once nothing live remains on
 * it (`Site::guardCascade()`), and `EntitlementWriter::forget()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bytes = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('entitlements', function (Blueprint $table) use ($bytes): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            // NOT NULL; `Site::guardCascade()` refuses while live rows remain.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // The declared reader guard's identifier, as a string.
            $bytes ? $table->binary('reader_id', 255) : $table->string('reader_id', 255);
            // `course.advanced-php`: shape checked, existence never.
            $table->string('entitlement', 100);
            // Why the reader holds it: `commerce.order:4821`, `core.comp`.
            $bytes ? $table->binary('source', 100) : $table->string('source', 100);
            // Exclusive end, UTC; NULL = no end.
            $table->dateTime('expires_at')->nullable();
            // UTC; set = this source revoked, whatever `expires_at` says.
            $table->dateTime('revoked_at')->nullable();
            // UTC; the last write.
            $table->dateTime('changed_at');

            $table->unique(['site_id', 'reader_id', 'entitlement', 'source']);
        });
    }

    /** ⚠️ DESTROYS EVERY READER'S ACCESS, of every org, beyond recovery. For development only. */
    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
