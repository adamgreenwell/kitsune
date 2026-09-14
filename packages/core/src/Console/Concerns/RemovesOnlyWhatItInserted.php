<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console\Concerns;

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Site;

/**
 * The entries a benchmark inserted, and the only entries it removes — for all three benchmarks, which each had
 * their own copy of this and got it wrong in different ways.
 *
 * ⚠️ BY IDENTITY RATHER THAN PATTERN. A slug prefix is a guess about somebody else's data: review found cleanup
 * matching one force-deleting a customer's own entry on a run that inserted nothing, and deleting the corpus an
 * earlier run had kept with `--keep`. The prefix narrows; the id range is the proof; a mark that was never taken
 * removes nothing.
 *
 * ⚠️ AND OUT THE WAY THEY WENT IN. The rows are inserted through the query builder, beneath the audit trail,
 * because a benchmark is not an edit. Removing them through `Entry` did not mirror that: `AuditedBuilder` records
 * a force-delete for every key, so a run in an org it did not create — the admin benchmark always borrows a
 * real site — left one `entry.force_deleted` row per benchmark entry in that org's audit log, up to a hundred
 * thousand, for content no one ever wrote.
 */
trait RemovesOnlyWhatItInserted
{
    /** The highest entry id before this run inserted anything, or null if it inserted nothing. */
    private ?int $insertedAbove = null;

    /** Take the mark. Called immediately before the first insert, and only when there will be one. */
    private function markBeforeInserting(): void
    {
        $this->insertedAbove = (int) DB::table('entries')->max('id');
    }

    /** Remove this run's rows: in this site, above the mark, and carrying the prefix this command inserts. */
    private function removeInserted(Site $site, string $slugPrefix): void
    {
        if ($this->insertedAbove === null) {
            return;
        }

        DB::table('entries')
            ->where('site_id', $site->getKey())
            ->where('id', '>', $this->insertedAbove)
            ->where('slug', 'like', $slugPrefix.'%')
            ->delete();
    }
}
