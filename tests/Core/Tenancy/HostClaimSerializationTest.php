<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Two concurrent claimants of one hostname cannot both pass the overlap check — issue #61.
 *
 * ⚠️ THE CHECK IS A CHECK-THEN-ACT AND THE UNIQUE INDEX CANNOT CLOSE IT.
 * `Site::refuseOverlappingClaim()` reads the rival claims for a host and compares prefixes in PHP,
 * because "one prefix contains the other" is not an equality any index expresses. So two orgs
 * creating `example.test/` and `example.test/news` at the same moment could both complete the read
 * before either insert committed: the derived keys differ, the unique constraint accepts both, and
 * the resolver's longest-prefix rule then serves one org's URL from the other. ADR-021 says that
 * theft has no framework safety net.
 *
 * ⚠️ A LOCK ON `sites` CANNOT CLOSE IT EITHER, which is the reason for `site_host_claims`. When both
 * claims are NEW there is no row to lock; Postgres takes no gap lock, and depending on MySQL's would
 * make correctness engine-specific (invariant 5). The claim row is a durable mutex that exists
 * whether or not any site on that host does.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Claimant', 'slug' => 'claimant']);
    app(Context::class)->setOrg($this->org);
});

afterAll(function (): void {
    /*
     * ⚠️ COMMITTED ROWS FROM ANOTHER CONNECTION OUTLIVE `RefreshDatabase`, so the one test that needs
     * them has to sweep them — and it cannot do so itself, because it holds locks on them until its
     * transaction closes. This runs after the file's last test, which is the first safe moment.
     */
    /*
     * ⚠️ EVERYTHING INSIDE THE `try`, INCLUDING RESOLVING THE CONNECTION, and leaving it outside made
     * the whole file exit 2 — an ERROR rather than a failure — behind a green summary. On SQLite a
     * second connection to `:memory:` is a different, empty database, so resolving it and asking for
     * a table that does not exist throws from `afterAll`, which is outside any test.
     *
     * ⚠️ AND ONLY WHERE THE TEST THAT NEEDS IT RAN. The committing test skips on SQLite, so there is
     * nothing to sweep there and no reason to open a connection at all.
     */
    try {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $default = (string) config('database.default');
        config(['database.connections.sweep' => config("database.connections.{$default}")]);

        $sweep = DB::connection('sweep');

        $sweep->table('sites')->where('canonical_host', 'snapshot.test')->delete();
        $sweep->table('site_host_claims')->where('canonical_host', 'snapshot.test')->delete();
        $sweep->table('orgs')->where('slug', 'rival-org')->delete();

        DB::purge('sweep');
    } catch (Throwable) {
        // The engine may have rolled the whole schema away already, which is equally clean.
    }
});

afterEach(function (): void {
    app(Context::class)->forget();

    // Release anything a rival connection still holds, whatever the test did.
    if (array_key_exists('rival', config('database.connections') ?? [])) {
        try {
            DB::connection('rival')->rollBack();
        } catch (Throwable) {
            // Nothing open, which is the ordinary case.
        }

        DB::purge('rival');
    }
});

/**
 * A second connection to the SAME database, so a real lock can be held while this one saves.
 *
 * ⚠️ TWO CONNECTIONS, NOT TWO PROCESSES. Concurrency here means "another transaction holds the row",
 * which is exactly what a second connection gives — and it is deterministic, where two processes
 * would be a race the suite could pass by luck.
 */
function rivalConnection(): Connection
{
    $default = (string) config('database.default');

    config(['database.connections.rival' => config("database.connections.{$default}")]);

    return DB::connection('rival');
}

/** Whether this engine's locking is what the claim row exists to use. */
function lockingEngine(): bool
{
    return DB::connection()->getDriverName() !== 'sqlite';
}

it('takes the host mutex inside the transaction that writes the site', function (): void {
    /*
     * ⚠️ THE STRUCTURAL HALF, which runs on every engine including SQLite. The lock is only
     * meaningful if it is held while the site row is written, and `DB::transaction()` in
     * `Site::save()` is what makes that true — a lock taken in the `saving` hook would depend on the
     * CALLER having opened a transaction, which `Site::create()` outside one does not.
     */
    $baseline = DB::transactionLevel();
    $claimLevels = [];
    $siteLevels = [];

    DB::listen(function ($query) use (&$claimLevels, &$siteLevels): void {
        if (str_contains($query->sql, 'site_host_claims')) {
            $claimLevels[] = DB::transactionLevel();
        }

        if (preg_match('/^\s*insert\b/i', $query->sql) === 1 && str_contains($query->sql, 'sites')) {
            $siteLevels[] = DB::transactionLevel();
        }
    });

    Site::create([
        'org_id' => $this->org->id, 'handle' => 'mutex', 'slug' => 'mutex', 'name' => 'Mutex',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://mutex.test',
    ]);

    expect($claimLevels)->not->toBe([], 'the save never touched the host claim table')
        ->and($siteLevels)->not->toBe([], 'the save never inserted a site')
        ->and(min($claimLevels))->toBeGreaterThan($baseline, 'the mutex was taken outside a transaction')
        ->and(min($siteLevels))->toBeGreaterThan($baseline, 'the site was written outside the mutex transaction');
});

it('creates one claim row per host, however many sites share it', function (): void {
    /*
     * ⚠️ KEYED ON THE HOST ALONE, not the (host, prefix) pair, and this is why. Several sites
     * legitimately share a hostname — one org arranging `/` and `/fr` under its own host is a
     * documented arrangement — and a per-pair row would give each of them its own mutex and
     * serialise nothing.
     */
    foreach (['https://shared.test/', 'https://shared.test/fr', 'https://shared.test/de'] as $i => $url) {
        Site::create([
            'org_id' => $this->org->id, 'handle' => "shared{$i}", 'slug' => "shared{$i}",
            'name' => "Shared {$i}", 'locale' => 'en', 'url_strategy' => 'path', 'base_url' => $url,
        ]);
    }

    expect(DB::table('site_host_claims')->where('canonical_host', 'shared.test')->count())->toBe(1);
});

it('locks nothing for a site with no public URL', function (): void {
    // An admin-only site claims no address, so it contends with nobody and needs no mutex.
    Site::create([
        'org_id' => $this->org->id, 'handle' => 'private', 'slug' => 'private', 'name' => 'Private',
        'locale' => 'en', 'url_strategy' => 'path',
    ]);

    expect(DB::table('site_host_claims')->count())->toBe(0);
});

it('waits for a rival holding the same host, rather than passing the check beside it', function (): void {
    /*
     * ⚠️ THE ASSERTION #61 ASKS FOR, and it needs a real second transaction. The rival takes the
     * mutex for `rival.test` and holds it; this connection then attempts a site on the same host with
     * a short lock timeout. If the save serialises it times out; if it does not, it sails past the
     * overlap check with the rival's claim invisible — which is the defect.
     *
     * ⚠️ SQLITE IS SKIPPED AND THAT IS NOT A GAP. It compiles `FOR UPDATE` to nothing and serialises
     * writers at the database level, so a second writer waits on the transaction itself — there is no
     * row lock to demonstrate. Postgres and MySQL are the engines the claim row exists for, and the
     * suite runs all three, so this test executes where its subject exists.
     */
    $rival = rivalConnection();

    // Short waits, so a held lock surfaces as a failure rather than as a hung suite.
    match (DB::connection()->getDriverName()) {
        'pgsql' => DB::statement("SET lock_timeout = '750ms'"),
        'mysql', 'mariadb' => DB::statement('SET SESSION innodb_lock_wait_timeout = 1'),
        default => null,
    };

    $rival->beginTransaction();
    $rival->table('site_host_claims')->insertOrIgnore([
        'canonical_host' => 'rival.test',
        'created_at' => now(),
    ]);
    $rival->table('site_host_claims')->where('canonical_host', 'rival.test')->lockForUpdate()->first();

    $blocked = false;

    try {
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'contend', 'slug' => 'contend', 'name' => 'Contend',
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://rival.test/news',
        ]);
    } catch (PDOException) {  // A GLOBAL class: importing it in a global-namespace file is a warning.
        /*
         * ⚠️ `PDOException`, NOT `QueryException`, and the engines differ in a way worth recording.
         * Postgres blocks on the `SELECT … FOR UPDATE` and raises a `QueryException`; MySQL and
         * MariaDB block earlier, on the unique index during `insert ignore`, and raise
         * `DeadlockException` — which extends `PDOException` and NOT `QueryException`, so catching
         * the latter passed on Postgres and failed on both MySQL engines.
         *
         * Both are the save queueing behind the rival, which is the property under test. Where it
         * queues is the engine's business, and asserting the narrower type would have been asserting
         * Postgres's implementation.
         */
        $blocked = true;
    }

    $rival->rollBack();

    expect($blocked)->toBeTrue(
        'the save did not wait for the rival holding this host, so two claimants can pass the '
        .'overlap check concurrently',
    );
})->skip(fn (): bool => ! lockingEngine(), 'SQLite serialises writers at the database level, so there is no row lock to demonstrate');

it('lets a rival on a DIFFERENT host through without waiting', function (): void {
    /*
     * ⚠️ THE COST OF THE MUTEX, BOUNDED. Serialising every site save on one global row would be a
     * write bottleneck for an installation serving many hostnames, so the mutex is per host — and
     * this is what proves it. The rival holds `other.test` and a save for `unrelated.test` must not
     * queue behind it.
     */
    $rival = rivalConnection();

    match (DB::connection()->getDriverName()) {
        'pgsql' => DB::statement("SET lock_timeout = '750ms'"),
        'mysql', 'mariadb' => DB::statement('SET SESSION innodb_lock_wait_timeout = 1'),
        default => null,
    };

    $rival->beginTransaction();
    $rival->table('site_host_claims')->insertOrIgnore(['canonical_host' => 'other.test', 'created_at' => now()]);
    $rival->table('site_host_claims')->where('canonical_host', 'other.test')->lockForUpdate()->first();

    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'free', 'slug' => 'free', 'name' => 'Free',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://unrelated.test',
    ]);

    $rival->rollBack();

    expect($site->canonical_host)->toBe('unrelated.test');
})->skip(fn (): bool => ! lockingEngine(), 'SQLite has no row lock to contend for');

it('sees a rival committed while this save waited, not an older snapshot', function (): void {
    /*
     * ⚠️ HOLDING THE MUTEX IS WORTHLESS IF THE READ ANSWERS FROM AN OLDER POINT IN TIME, which under
     * MySQL and MariaDB's REPEATABLE READ it can. Review found it: if a caller wrapped this save in a
     * transaction that had already read anything, `lockHostClaim()`'s nested `DB::transaction()` is
     * only a savepoint and the snapshot belongs to the OUTER transaction. A rival committing while
     * this save queued for the mutex is then invisible, and `/` and `/news` coexist across orgs after
     * all — the exact theft the mutex was added to prevent.
     *
     * ⚠️ THE SUITE SUPPLIES THE OUTER TRANSACTION, which is what makes this reachable rather than
     * hypothetical: `RefreshDatabase` wraps every test in one, and the fixture above has already read
     * through it. So this is the ordinary shape of a save inside a caller's transaction.
     *
     * `lockForUpdate()` on the rival lookup forces a current read on both MySQL engines. The lock is
     * incidental — the mutex is what serialises — and the clause costs Postgres and SQLite nothing.
     */
    /*
     * ⚠️ THIS READ IS LOAD-BEARING AND THE TEST WAS VACUOUS WITHOUT IT. In REPEATABLE READ the
     * consistent snapshot is established at the transaction's first READ, and the fixture above only
     * writes — so without this line the snapshot was taken AFTER the rival committed and an ordinary
     * read saw the rival anyway. The test passed with the fix and without it, which I only found by
     * reverting the fix to check.
     *
     * Pinning the snapshot first is what makes the condition reachable:
     *
     *     without lockForUpdate()   mysql  1 failed, 5 passed
     *     with it                   mysql  6 passed
     */
    DB::table('sites')->count();

    $rival = rivalConnection();

    /*
     * ⚠️ COMMITTED, BY ANOTHER CONNECTION, or there is no cross-snapshot visibility to test. It needs
     * its own org too: this connection's org lives in an uncommitted transaction the rival cannot see,
     * and the overlap check only refuses a claim held by a DIFFERENT org.
     */
    $rivalOrgId = $rival->table('orgs')->insertGetId([
        'name' => 'Rival Org', 'slug' => 'rival-org', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rival->table('sites')->insert([
        'org_id' => $rivalOrgId, 'handle' => 'rivalroot', 'slug' => 'rivalroot', 'name' => 'Rival Root',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://snapshot.test/',
        'canonical_host' => 'snapshot.test', 'path_prefix' => '',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => Site::create([
        'org_id' => $this->org->id, 'handle' => 'mine', 'slug' => 'mine', 'name' => 'Mine',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://snapshot.test/news',
    ]))->toThrow(RuntimeException::class, 'another org already holds');

    /*
     * ⚠️ THE SWEEP CANNOT HAPPEN HERE, and trying it cost fifty seconds and a deadlock. These rows
     * are committed on another connection, so `RefreshDatabase`'s rollback never reaches them — but
     * this connection now holds `lockForUpdate()` on them inside a transaction that is still open, so
     * a delete from the rival waits for a lock this test is holding and times out.
     *
     * `afterAll` runs once the file's transactions are closed, which is the first moment the rows can
     * be removed. Nothing between here and there uses `snapshot.test`.
     */
})->skip(fn (): bool => ! lockingEngine(), 'SQLite has one writer, so there is no second snapshot to be stale');

it('locks both hosts of a move, in hostname order', function (): void {
    /*
     * ⚠️ THE DEADLOCK REVIEW FOUND, and it is measured rather than reasoned. Two same-org sites
     * moving across each other's hosts each locked a mutex the other did not hold, then needed a
     * site row the other did. Staged as two real sessions against the running engines:
     *
     *     site 1 at a.test/x -> b.test/x        site 2 at b.test/y -> a.test/y
     *     TX1 locks mutex(b.test), then site 2  TX2 locks mutex(a.test), then site 1
     *     TX1 UPDATEs site 1 — held by TX2      TX2 UPDATEs site 2 — held by TX1
     *
     *   PostgreSQL 17  ERROR: deadlock detected — while updating tuple in relation "sites"
     *   MySQL 8.4      ERROR 1213 (40001): Deadlock found when trying to get lock
     *
     * `DB::transaction()` takes one attempt, so an otherwise-valid save surfaced as an exception.
     * With both mutexes held in sorted order both transactions committed and the swap completed.
     *
     * ⚠️ ASSERTED ON THE ORDER, NOT BY STAGING THE DEADLOCK, and that is the honest test rather than
     * the convenient one. A deadlock needs both transactions in flight; a second connection can hold
     * a lock but cannot then be asked for one, because this connection is blocked waiting. What
     * prevents the cycle is a total order every transaction agrees on — so that is what is pinned,
     * on both directions of the same move.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'mover', 'slug' => 'mover', 'name' => 'Mover',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://bbb.test/x',
    ]);

    $locked = [];

    DB::listen(function ($query) use (&$locked): void {
        // The locking read, not the upsert: `insertOrIgnore` names the host as a binding too.
        if (preg_match('/^\s*select\b/i', $query->sql) === 1 && str_contains($query->sql, 'site_host_claims')) {
            $locked[] = (string) ($query->bindings[0] ?? '');
        }
    });

    // bbb.test -> aaa.test. Origin sorts AFTER destination, so origin-then-destination would differ.
    $site->base_url = 'https://aaa.test/x';
    $site->save();

    expect($locked)->toBe(['aaa.test', 'bbb.test'], 'a move did not lock both hosts in hostname order');

    // And back again: the same order, from the opposite move.
    $locked = [];
    $site->base_url = 'https://bbb.test/x';
    $site->save();

    expect($locked)->toBe(['aaa.test', 'bbb.test'], 'the order followed the move rather than the hostnames');
});

it('locks the old host when a save removes the public URL', function (): void {
    /*
     * ⚠️ THE CASE THAT LOOKS LIKE IT NEEDS NO LOCK. Nothing needs checking for a site that claims no
     * address — `refuseOverlappingClaim()` returns early — but the save still UPDATEs a row sitting
     * at the old host, and a rival claiming that host may hold it. Dropping the lock here would
     * reopen the cycle for exactly the case that appears exempt.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'leaver', 'slug' => 'leaver', 'name' => 'Leaver',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://leaving.test',
    ]);

    $locked = [];

    DB::listen(function ($query) use (&$locked): void {
        if (preg_match('/^\s*select\b/i', $query->sql) === 1 && str_contains($query->sql, 'site_host_claims')) {
            $locked[] = (string) ($query->bindings[0] ?? '');
        }
    });

    $site->base_url = null;
    $site->save();

    expect($site->canonical_host)->toBeNull('the save did not actually remove the public URL')
        ->and($locked)->toBe(['leaving.test'], 'a save that gives up a host took no lock on it');
});

it('locks one host when a save does not move', function (): void {
    /*
     * ⚠️ THE COST STAYS BOUNDED. Locking two mutexes per save would double the contention the
     * per-host design exists to avoid, so a save that keeps its host must still take exactly one.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'stayer', 'slug' => 'stayer', 'name' => 'Stayer',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://staying.test/one',
    ]);

    $locked = [];

    DB::listen(function ($query) use (&$locked): void {
        if (preg_match('/^\s*select\b/i', $query->sql) === 1 && str_contains($query->sql, 'site_host_claims')) {
            $locked[] = (string) ($query->bindings[0] ?? '');
        }
    });

    $site->base_url = 'https://staying.test/two';
    $site->save();

    expect($locked)->toBe(['staying.test'], 'a save that kept its host locked more than one mutex');
});
