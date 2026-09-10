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
use PDOException;

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
    } catch (PDOException) {
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
