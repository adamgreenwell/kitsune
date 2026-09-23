<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An ordered read for a list that admits the org's shared rows — ADR-042 decision 2.
 *
 * ⚠️ IT LEADS WITH `org_id`, AND AGENTS.md §4 WAS AMENDED FOR IT, by Adam. The rule is that a `#[SiteScoped]` table's
 * lookup indexes lead with `site_id`, and an org-shared row has no site to lead with. A media type's list admits this
 * site's rows and the org's `site_id IS NULL` ones, so it asks `org_id = ? AND entry_type_id = ?` with the site as a
 * filter, ordered by `updated_at`. Measured at 290,000 entries (ADR-042's *Measured*, `bin/benchmark-shared-media.php`):
 * the list's page 1 without the `org_id` conjunct takes 180 ms on SQLite and 156 ms on MySQL — a multi-index OR and a
 * sort of every matching row — and with this index and the conjunct 0.10 ms and 0.90 ms, one ordered read.
 *
 * §4's amendment holds while three things do, stated here as it requires: the rows this serves can have no site; every
 * query it serves says `org_id = ?` for the current org — the media list does, in `EntryResource::getEloquentQuery()`;
 * and the site-leading indexes stay for the reads that admit no shared row — `(site_id, entry_type_id, updated_at)` for
 * every other type's list and `(site_id, updated_at)` for the dashboard, which keep Filament's unwidened rule.
 *
 * ⚠️ `updated_at` LAST, WITH NOTHING AFTER IT. The list breaks ties on the primary key. SQLite ends every index entry with
 * the rowid and InnoDB with the primary key, so on those three engines the index serves the tiebreak too, and a column
 * after `updated_at` would sit between the two and cost the order. PostgreSQL finishes the tiebreak with an incremental
 * sort over rows sharing one timestamp, which `MediaListPlanTest` accepts and a full sort it does not.
 *
 * A migration of its own, for the reason `0001_01_01_000008` gives: `deploy/release.sh` runs `migrate --force`.
 */
return new class extends Migration
{
    public const INDEX = 'entries_org_id_entry_type_id_updated_at_index';

    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table): void {
            $table->index(['org_id', 'entry_type_id', 'updated_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        /*
         * ⚠️ MYSQL AND MARIADB MAY HAVE DROPPED THEIR OWN INDEX ON `org_id` when this one arrived, because it can
         * serve the foreign key — and then refuse to drop this one, which the key still needs. So on those two a plain
         * index on `org_id` is put back first, unless another already leads with it. SQLite and PostgreSQL index no
         * foreign key of their own, so there is nothing to put back and nothing is added.
         */
        $nothingToPutBack = ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || collect(Schema::getIndexes('entries'))
                ->reject(fn (array $index): bool => $index['name'] === self::INDEX)
                ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === 'org_id');

        Schema::table('entries', function (Blueprint $table) use ($nothingToPutBack): void {
            if (! $nothingToPutBack) {
                $table->index('org_id');
            }

            $table->dropIndex(self::INDEX);
        });
    }
};
