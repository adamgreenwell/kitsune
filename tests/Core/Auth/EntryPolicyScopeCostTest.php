<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * What the policy's scope check COSTS, pinned — ADR-027's floor, and review's price for an unforgeable guard.
 *
 * ⚠️ THE GUARD ASKS THE DATABASE, SO THE NUMBER OF TIMES IT ASKS IS PART OF THE DESIGN. Review established
 * that comparing the record's attributes is forgeable; reading the stored row is not, and Filament asks five
 * abilities of every row it renders. Measured at 100k entries with `kitsune:benchmark-admin`:
 *
 *   attributes only            entry list, page 1   17 queries   63.7 ms
 *   stored row, unmemoised     entry list, page 1   67 queries   77.3 ms
 *   stored row, memoised       entry list, page 1   17 queries   64.8 ms
 *
 * ⚠️ AND THE MIDDLE ROW OF THAT TABLE WAS A NUMBER I EXPECTED RATHER THAN MEASURED on the first write-up —
 * I wrote 27 for the memoised case because 10 rows "should" cost 10 reads, then measured 17. A grouped
 * query-shape dump of a real list request shows the reads are there and are one per distinct row; the
 * benchmark's single GET simply authorizes fewer rows than the page renders. The ratio this test pins is the
 * claim worth making, and the page-level figures are quoted as what they are.
 *
 * ⚠️ AND THE MEMO ONLY WORKS FROM A STATIC FRAME, which is what this test actually pins. `once()` keys on the
 * CALLING frame including its `$this`, and `Gate::resolvePolicy()` builds a new policy for every check — so
 * memoising from the instance method keyed every call differently and the query count did not move at all.
 * A regression to an instance-bound `once()` would be invisible in every functional test and would put 5N
 * queries on a page that renders N rows.
 */
it('reads the stored scope once per row, however many abilities are asked', function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $org = Org::create(['slug' => 'memo2', 'name' => 'M']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['org_id' => $org->getKey(), 'handle' => 'm', 'slug' => 'm', 'name' => 'M']);
    app(Context::class)->setSite($site);
    $type = EntryType::create(['org_id' => $org->getKey(), 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);
    $one = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'One']);
    $two = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Two']);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'memo2@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    Role::create(['handle' => 'owner', 'name' => 'O', 'is_owner' => true])->assignTo($user->getKey());

    $seen = 0;
    DB::listen(function (QueryExecuted $q) use (&$seen): void {
        /*
         * ⚠️ IDENTIFIER QUOTES ARE DIALECT, AND MATCHING THEM MADE THIS COUNT ZERO ON MYSQL. Postgres and
         * SQLite quote with `"`, MySQL and MariaDB with backticks — so `from "entries"` matched nothing on
         * half the matrix and the assertion failed with 0 reads where it expected 2, on a guard that was
         * working. Strip the quotes and the shape is the same everywhere. Third instrument in this file's
         * history to be caught by a dialect difference rather than by a defect.
         */
        $sql = str_replace(['"', '`'], '', $q->sql);

        /*
         * ⚠️ THE COLUMN LIST IS PART OF THE SHAPE, and it changed under this test once already: the scope read
         * grew `type_handle` when review found the policy resolving the permission from the record's mutable
         * attribute, and this filter stopped matching — reporting 0 reads, which is exactly the "the guard
         * stopped asking the database" failure the assertion below watches for. It failed loudly rather than
         * going quiet, which is the whole reason it names the shape instead of grepping for the table.
         */
        if (str_contains($sql, 'select site_id, org_id, type_handle from entries')) {
            $seen++;
        }
    });

    foreach ([$one, $two] as $entry) {
        foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            (new EntryPolicy)->{$ability}($user, $entry);   // a fresh instance, as the Gate does
        }
    }

    /*
     * Ten checks, ten fresh policy instances, two rows — and two reads. Not vacuous: the assertion would be
     * 10 with the memo keyed per instance, and 0 if the guard had stopped asking the database at all, which
     * is the other way this could go quiet.
     */
    expect($seen)->toBe(2);
});
