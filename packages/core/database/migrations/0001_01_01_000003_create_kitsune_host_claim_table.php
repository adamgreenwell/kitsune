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
 * One durable row per public hostname, existing only to be locked — issue #61.
 *
 * ⚠️ IT HOLDS NO CLAIM. The claim lives on `sites`, and this table's only job is to give two
 * concurrent claimants of one hostname something they can both lock. `Site::refuseOverlappingClaim()`
 * is a check-then-act: it reads the rival claims for a host, compares prefixes in PHP — because
 * "one prefix contains the other" is not an equality any unique index can express — and then the
 * row is written. Two orgs creating `example.test/` and `example.test/news` at the same moment can
 * both complete the read before either insert commits, the derived index keys differ so the unique
 * constraint accepts both, and the resolver's longest-prefix rule then serves one org's URL from the
 * other. That is the cross-org theft ADR-021 says has no framework safety net.
 *
 * ⚠️ A LOCK ON `sites` CANNOT CLOSE IT, which is why this table exists rather than a
 * `lockForUpdate()` in the save path. When both claims are NEW there is no row to lock: locking the
 * existing rows for a host works only when a rival already exists, which is the sequential case that
 * was already closed. Postgres takes no gap lock here, and relying on MySQL's would make correctness
 * engine-dependent — the thing invariant 5 exists to prevent.
 *
 * ⚠️ KEYED ON THE HOST ALONE, not on the (host, prefix) pair. Several sites legitimately share a
 * hostname — that is what path prefixes are for, and one org arranging `/` and `/fr` under its own
 * host is a documented arrangement. The pair would give each of them its own row and serialize
 * nothing. The host is the unit of contention because it is the unit the overlap check reads.
 *
 * ⚠️ NO FOREIGN KEY, AND NO CASCADE. A claim row outlives the sites that referenced it, deliberately:
 * it is a mutex, and deleting one because the last site on a host went away would drop the lock two
 * concurrent re-creations of that host need. The rows are tiny, bounded by the number of distinct
 * hostnames an installation serves, and never read for their contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_host_claims', function (Blueprint $table): void {
            $table->id();

            /*
             * ⚠️ The canonical form, matching `sites.canonical_host` exactly — `Site::canonicalHost()`
             * produces both. A different spelling here would be a different mutex, which is the same
             * defect as two spellings of one claim.
             *
             * ⚠️ `''` IS A REAL VALUE, not an absent one: it is the host-less claim a path-addressed
             * site makes, meaning "whatever host serves this installation". Two orgs claiming
             * overlapping prefixes there contend exactly as they would on a named host, so it needs a
             * row like any other.
             */
            $table->string('canonical_host')->unique();

            /*
             * ⚠️ NO `updated_at`, because nothing updates it. A row is inserted once and thereafter
             * only locked, and a timestamp that never changes is a column that invites someone to
             * believe it means something.
             */
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_host_claims');
    }
};
