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

        // The staleness tests commit their own rows for the same reason, on their own hosts.
        $sweep->table('sites')->where('canonical_host', 'like', 'stale-%')->delete();
        $sweep->table('site_host_claims')->where('canonical_host', 'like', 'stale-%')->delete();
        $sweep->table('orgs')->where('slug', 'stale-rival')->delete();

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

it('takes the host mutex exclusively, even when the claim row already exists', function (): void {
    /*
     * ⚠️ `INSERT IGNORE` TAKES A SHARED LOCK ON A DUPLICATE KEY, which review found and which turns the
     * mutex into a deadlock exactly when it is doing its job. On MySQL and MariaDB, `INSERT IGNORE`
     * hitting an existing unique-index record leaves both transactions holding S — and both then ask
     * `FOR UPDATE` to upgrade to X, which neither can while the other holds S. Staged as two real
     * sessions against an EXISTING claim row:
     *
     *   INSERT IGNORE  then FOR UPDATE      ERROR 1213 Deadlock found
     *   ON DUPLICATE KEY UPDATE  then same  both commit
     *
     * `DB::transaction()` takes one attempt, so the first surfaced an otherwise-valid save as a database
     * exception rather than queueing it. `upsert()` compiles to the second on MySQL, which takes the
     * record exclusively so the loser queues on the insert and never reaches a conversion.
     *
     * ⚠️ ASSERTED ON THE SQL rather than by staging the deadlock, because a conversion deadlock needs
     * both transactions mid-flight and a second connection can hold a lock but cannot then be asked to
     * upgrade while this one blocks. What prevents it is which statement is issued, so that is what is
     * pinned — and the measurement above is what says the statement is the right one.
     */
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'site_host_claims')) {
            $statements[] = $query->sql;
        }
    });

    Site::create([
        'org_id' => $this->org->id, 'handle' => 'firstclaim', 'slug' => 'firstclaim', 'name' => 'First',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://shared-claim.test',
    ]);

    // A second site on the SAME host, so the claim row already exists and the duplicate path is taken.
    Site::create([
        'org_id' => $this->org->id, 'handle' => 'secondclaim', 'slug' => 'secondclaim', 'name' => 'Second',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://shared-claim.test/news',
    ]);

    $writes = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => preg_match('/^\s*insert/i', $sql) === 1,
    ));

    expect($writes)->not->toBe([], 'no statement wrote the claim table');

    /*
     * ⚠️ ONE NEEDLE AND NO MESSAGE, because `toContain()` is VARIADIC over needles rather than taking a
     * failure message — and my first version passed the message as a second needle. `not->toContain()`
     * over two needles passes when EITHER is absent, so the prose was absent, so the assertion could
     * never fail: it passed with `insert ignore` in the SQL. Caught by reverting the fix and watching
     * the test still pass, and the reasoning lives in this comment where it cannot be mistaken for an
     * argument.
     */
    /*
     * ⚠️ THE CLAUSE IS SPELLED PER ENGINE, and asserting MySQL's on every engine is what the matrix
     * caught: Postgres compiles `on conflict … do update set`, so the first version of this failed there
     * for the wrong reason. The PROPERTY is one sentence — the duplicate-key path takes the record
     * exclusively — and each engine has its own words for it.
     */
    $clause = match (DB::connection()->getDriverName()) {
        'pgsql' => 'on conflict',
        default => 'on duplicate key update',
    };

    foreach ($writes as $sql) {
        expect(strtolower($sql))->not->toContain('insert ignore');
        expect(strtolower($sql))->toContain($clause);
    }
})->skip(fn (): bool => ! lockingEngine(), 'SQLite has one writer, so there is no lock to convert');

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

/**
 * A committed site on a committed org, so another connection can move it under this one.
 *
 * ⚠️ COMMITTED, BY THE RIVAL CONNECTION, or there is nothing cross-connection to test. A row this
 * connection creates lives in `RefreshDatabase`'s open transaction, and the rival's UPDATE would
 * match zero rows — which is how the first version of these tests passed for no reason. The rows
 * outlive the rollback and are removed by the file's `afterAll`.
 *
 * @return array{0: int, 1: Site}
 */
function committedSiteAt(string $host, string $handle): array
{
    $rival = rivalConnection();

    $orgId = (int) ($rival->table('orgs')->where('slug', 'stale-rival')->value('id')
        ?? $rival->table('orgs')->insertGetId([
            'name' => 'Stale Rival', 'slug' => 'stale-rival', 'created_at' => now(), 'updated_at' => now(),
        ]));

    $siteId = (int) $rival->table('sites')->insertGetId([
        'org_id' => $orgId, 'handle' => $handle, 'slug' => $handle, 'name' => $handle,
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://'.$host,
        'canonical_host' => $host, 'path_prefix' => '',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /** @var Site $site */
    $site = Site::query()->withoutGlobalScopes()->findOrFail($siteId);

    return [$siteId, $site];
}

it('refuses a save whose row moved hosts since the instance was loaded', function (): void {
    /*
     * ⚠️ THE ORIGIN CAME FROM THE LOADED INSTANCE, AND THAT REOPENED THE CYCLE — review's finding,
     * and what it broke is the proof rather than the precision. That proof rests on "every site row
     * this transaction touches sits at a host whose mutex it holds", and a row moved after this
     * instance was read is at a host whose mutex this save never takes. Two such saves take DISJOINT
     * mutex sets, serialise against nothing, and their rival reads acquire each other's rows.
     * Measured as two real sessions — row 1 believed at `a.test` but actually at `b.test` moving to
     * `c.test`, against row 2 believed at `d.test` but actually at `c.test` moving to `b.test`:
     * PostgreSQL 17 reports `deadlock detected`. With this check both stop with their own message.
     */
    /*
     * ⚠️ THE LOCKING READ IS LOAD-BEARING AND ONLY MySQL PROVES IT. Under Postgres's READ COMMITTED a
     * plain read already sees the rival's committed move, so this test passes either way there —
     * under REPEATABLE READ it answers from the snapshot taken when `committedSiteAt()` loaded the
     * model, which predates the move, and the check sees nothing to refuse. Measured by reverting the
     * clause:
     *
     *     plain read           pgsql  11 passed        mysql  1 failed, 10 passed
     *     with lockForUpdate   pgsql  11 passed        mysql  11 passed
     *
     * The engine matrix is the only reason that is visible, which is what ADR-024 is for.
     */
    [$id, $site] = committedSiteAt('stale-before.test', 'stalemoved');

    expect($site->getRawOriginal('canonical_host'))->toBe('stale-before.test');

    $rival = rivalConnection();
    $rival->table('sites')->where('id', $id)->update([
        'base_url' => 'https://stale-elsewhere.test', 'canonical_host' => 'stale-elsewhere.test',
    ]);

    // This instance still believes `stale-before.test`, and is moving to a third host.
    $site->base_url = 'https://stale-after.test';

    expect(fn () => $site->save())->toThrow(RuntimeException::class, 'another save moved it');

    /*
     * ⚠️ READ BACK THROUGH THE RIVAL, not through this connection. Under REPEATABLE READ this
     * transaction's snapshot predates the rival's UPDATE, so an ordinary read here would report the
     * old host and the assertion would be about the snapshot rather than about the row.
     */
    expect((string) $rival->table('sites')->where('id', $id)->value('canonical_host'))
        ->toBe('stale-elsewhere.test', 'the stale save overwrote the move it had not seen');
})->skip(fn (): bool => ! lockingEngine(), 'a second connection to SQLite :memory: is a different database');

it('allows a save whose row moved to the host it was already heading for', function (): void {
    /*
     * ⚠️ THE TEST IS THE INVARIANT, NOT "THE HOST CHANGED", and this is the case that separates them.
     * A row moved to the very host this save is moving it TO is already under a mutex this save
     * holds, so the lock discipline is intact — and refusing here would be policing lost updates in
     * general, which is a separate decision about `Site` rather than a consequence of the proof.
     */
    [$id, $site] = committedSiteAt('stale-start.test', 'staleraced');

    $rival = rivalConnection();
    $rival->table('sites')->where('id', $id)->update([
        'base_url' => 'https://stale-target.test', 'canonical_host' => 'stale-target.test',
    ]);

    $site->base_url = 'https://stale-target.test/news';

    expect($site->save())->toBeTrue()
        ->and($site->canonical_host)->toBe('stale-target.test')
        ->and($site->path_prefix)->toBe('/news');
})->skip(fn (): bool => ! lockingEngine(), 'a second connection to SQLite :memory: is a different database');

it('refuses a stale save on a site that was loaded with no host', function (): void {
    /*
     * ⚠️ THE BYPASS MY OWN FIX INTRODUCED, which review found. The first version returned early when
     * the loaded `canonical_host` was null, reasoning that an admin-only site claims no host and so
     * has no origin to be stale about. That is true of the INSTANCE and not of the ROW: another
     * transaction can give that row a host, and this save then locks only its destination while its
     * row sits somewhere else — the same disjoint-mutex cycle, reached through the one path that
     * skipped the check. What the instance believes cannot decide whether the row is worth reading.
     */
    $rival = rivalConnection();

    $orgId = (int) ($rival->table('orgs')->where('slug', 'stale-rival')->value('id')
        ?? $rival->table('orgs')->insertGetId([
            'name' => 'Stale Rival', 'slug' => 'stale-rival', 'created_at' => now(), 'updated_at' => now(),
        ]));

    // An admin-only site: no public URL, so no host and no prefix.
    $siteId = (int) $rival->table('sites')->insertGetId([
        'org_id' => $orgId, 'handle' => 'staleadmin', 'slug' => 'staleadmin', 'name' => 'Stale Admin',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => null,
        'canonical_host' => null, 'path_prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /** @var Site $site */
    $site = Site::query()->withoutGlobalScopes()->findOrFail($siteId);

    expect($site->getRawOriginal('canonical_host'))->toBeNull('the fixture is not the shape under test');

    // Another transaction gives the row a host this instance has never seen.
    $rival->table('sites')->where('id', $siteId)->update([
        'base_url' => 'https://stale-granted.test', 'canonical_host' => 'stale-granted.test',
    ]);

    $site->base_url = 'https://stale-third.test';

    expect(fn () => $site->save())->toThrow(RuntimeException::class, 'another save moved it');
})->skip(fn (): bool => ! lockingEngine(), 'a second connection to SQLite :memory: is a different database');

it('allows a save on a site whose row genuinely has no host', function (): void {
    /*
     * ⚠️ A ROW ON NO HOST IS REACHED BY NOTHING, so it needs no mutex and cannot be in a cycle:
     * `refuseOverlappingClaim()` finds rivals by `canonical_host` equality, which a NULL never
     * satisfies, so the only save that ever locks such a row is a save of that row. Returning there
     * is the invariant holding rather than an exemption — and this is what stops the fix above from
     * becoming "an admin-only site cannot be given a URL".
     */
    $rival = rivalConnection();

    $orgId = (int) ($rival->table('orgs')->where('slug', 'stale-rival')->value('id')
        ?? $rival->table('orgs')->insertGetId([
            'name' => 'Stale Rival', 'slug' => 'stale-rival', 'created_at' => now(), 'updated_at' => now(),
        ]));

    $siteId = (int) $rival->table('sites')->insertGetId([
        'org_id' => $orgId, 'handle' => 'stalequiet', 'slug' => 'stalequiet', 'name' => 'Stale Quiet',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => null,
        'canonical_host' => null, 'path_prefix' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /** @var Site $site */
    $site = Site::query()->withoutGlobalScopes()->findOrFail($siteId);
    $site->base_url = 'https://stale-firsturl.test/news';

    expect($site->save())->toBeTrue()
        ->and($site->canonical_host)->toBe('stale-firsturl.test')
        ->and($site->path_prefix)->toBe('/news');
})->skip(fn (): bool => ! lockingEngine(), 'a second connection to SQLite :memory: is a different database');

it('refuses a save inside a REPEATABLE READ transaction, and only that transaction', function (): void {
    /*
     * ⚠️ `lockForUpdate()` DOES NOT ESCAPE A POSTGRES SNAPSHOT. Under MySQL and MariaDB's REPEATABLE
     * READ a locking read IS a current read, measured, which is why the clause exists. PostgreSQL is
     * different: at REPEATABLE READ a row INSERTED after the snapshot is invisible, `FOR UPDATE` or not.
     * Measured on PostgreSQL 17 with two sessions — one took a snapshot, a rival committed `x.test/`,
     * and the locking read returned only the pre-snapshot `x.test/other`.
     *
     * ⚠️ AND THE FIRST VERSION CACHED THE ANSWER PER CONNECTION, which review found was wrong in both
     * directions: a connection first seen at READ COMMITTED had that recorded for ever, so a later
     * REPEATABLE READ transaction skipped the check, and one first seen at REPEATABLE READ would have
     * refused every valid save afterwards. The level is a property of the TRANSACTION — measured on one
     * connection: `read committed` outside, `repeatable read` inside, `read committed` again after.
     *
     * ⚠️ SO THIS DRIVES A REAL TRANSACTION rather than seeding a cache, which is what makes it test the
     * probe as well as the refusal. `RefreshDatabase` holds the default connection inside a transaction
     * whose level cannot change, so the save runs on a second connection — and the SAME connection is
     * then asked to save outside that transaction, which is the half a per-connection cache got wrong.
     */
    $name = 'isolation';
    $default = (string) config('database.default');

    config(['database.connections.'.$name => config("database.connections.{$default}")]);

    $connection = DB::connection($name);

    try {
        $orgId = (int) $connection->table('orgs')->insertGetId([
            'name' => 'Isolation', 'slug' => 'isolation-org', 'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * ⚠️ THE CONTEXT HAS TO POINT AT THIS CONNECTION'S ORG, or the scope guard refuses the save
         * before the isolation check is reached — which is a refusal for the wrong reason and would have
         * made this test pass without exercising anything. The fixture org lives inside
         * `RefreshDatabase`'s transaction on the default connection and is invisible here.
         */
        app(Context::class)->setOrg(Org::on($name)->findOrFail($orgId));

        $row = fn (string $handle, string $host): array => [
            'org_id' => $orgId, 'handle' => $handle, 'slug' => $handle, 'name' => $handle,
            'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://'.$host,
        ];

        /*
         * ⚠️ THE SESSION DEFAULT, NOT A MANUAL `BEGIN`. Laravel's `transaction()` refuses to open one
         * inside a transaction it did not start — "There is already an active transaction" — so driving
         * the level that way tests PDO's bookkeeping rather than the check. `SET SESSION CHARACTERISTICS`
         * sets the level for SUBSEQUENT transactions, which is what `config/database.php`'s
         * `isolation_level` does and therefore the shape a real deployment would arrive in.
         *
         * ⚠️ REPEATABLE READ FIRST, so no cache could have been primed with the safe answer.
         */
        $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $refused = null;

        try {
            $site = new Site($row('isorr', 'iso-rr.test'));
            $site->setConnection($name);
            $site->save();
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }

        expect($refused)->toContain('REPEATABLE READ');

        // ⚠️ THE SAME CONNECTION, back at READ COMMITTED, must save. A per-connection cache would have
        // recorded `repeatable read` above and refused this for ever — the inverse half of the finding.
        $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');

        $ok = new Site($row('isook', 'iso-ok.test'));
        $ok->setConnection($name);

        expect($ok->save())->toBeTrue()
            ->and($ok->canonical_host)->toBe('iso-ok.test');

        /*
         * ⚠️ AND THE MUTEX MUST BE ON THE MODEL'S CONNECTION, which is a second defect this test found
         * rather than one review reported. `DB::transaction()` and `DB::table()` both resolve the DEFAULT
         * connection, so a Site on any other one took its mutex and opened its transaction on one
         * connection while `parent::save()` wrote through another — the serialisation and the write in
         * different transactions entirely, which defeats the mechanism rather than weakening it.
         *
         * Asserted by looking for the claim row HERE: with the default connection it would have been
         * written inside `RefreshDatabase`'s transaction on that one, and this connection would not see
         * it.
         */
        expect($connection->table('site_host_claims')->where('canonical_host', 'iso-ok.test')->exists())
            ->toBeTrue('the host mutex was taken on a different connection from the write');
    } finally {
        try {
            $connection->table('sites')->where('org_id', $orgId ?? 0)->delete();
            $connection->table('site_host_claims')->where('canonical_host', 'like', 'iso-%')->delete();
            $connection->table('orgs')->where('slug', 'isolation-org')->delete();
        } catch (Throwable) {
            // The schema may already be gone, which is equally clean.
        }

        DB::purge($name);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'the requirement is PostgreSQL-specific');

it('reads the isolation level the connection actually reports', function (): void {
    /*
     * ⚠️ THE INSTRUMENT, ASSERTED SEPARATELY. The test above seeds the cache, so it says nothing about
     * whether the probe fills it correctly — and a check that reads the wrong thing would refuse
     * nothing while looking implemented. `SHOW transaction_isolation` is what `refuseUnusableIsolation()`
     * runs, and this asserts it comes back as one of the levels the refusal compares against.
     */
    $level = DB::connection()->selectOne('SHOW transaction_isolation');

    expect(strtolower((string) ($level->transaction_isolation ?? '')))
        ->toBeIn(['read committed', 'repeatable read', 'serializable', 'read uncommitted']);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL syntax');

it('leaves a save on any other engine alone', function (): void {
    /*
     * ⚠️ THE BOUND. MySQL and MariaDB run at REPEATABLE READ by DEFAULT and are safe there, because a
     * locking read is a current read — measured, and the reason `refuseOverlappingClaim()` locks. A
     * check that refused every REPEATABLE READ connection would refuse both of them outright.
     */
    $site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'anyengine', 'slug' => 'anyengine', 'name' => 'Any',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://anyengine.test',
    ]);

    expect($site->canonical_host)->toBe('anyengine.test');
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'the refusal is PostgreSQL-specific');
