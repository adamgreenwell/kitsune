<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;

/*
 * A nested audited write that fails on a lock-wait timeout takes its own changes with it (T7) — ADR-042 decision 5.
 *
 * ⚠️ LARAVEL DOES NOT, and on MySQL and MariaDB a lock-wait timeout ends only the statement: it decrements its level and
 * throws `DeadlockException` without `ROLLBACK TO`, so a host that catches the exception and commits commits the
 * savepoint's writes with it. Under `RefreshDatabase` the host's transaction is level 2 and the write level 3, the shape
 * a host's own transaction around an admin save has. The timeout is scripted into the audit write, after the UPDATE.
 */

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'recovery', 'name' => 'Recovery']);
    app(Context::class)->setOrg($this->org);
    $site = Site::create(['handle' => 'main', 'slug' => 'recovery-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($site);

    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $this->entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Original', 'slug' => 'original']);
});

afterEach(fn () => app(Context::class)->forget());

it('rolls back its own savepoint when a nested write times out waiting for a lock', function (): void {
    AuditorStandIn::install()->throwOnce(new QueryException(
        (string) DB::connection()->getName(),
        'insert into "audit_log"',
        [],
        new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
    ));

    $caught = null;

    DB::transaction(function () use (&$caught): void {
        try {
            $this->entry->update(['title' => 'Changed']);
        } catch (DeadlockException $timeout) {
            $caught = $timeout;
        }
    });

    expect($caught)->toBeInstanceOf(DeadlockException::class)
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('title'))->toBe('Original')
        ->and(DB::table('audit_log')->where('action', 'entry.updated')->exists())->toBeFalse();
});

/*
 * ⚠️ A TRANSACTION MYSQL ENDED WITH AN ERROR, which leaves PDO's view of it stale — review. A deadlock ends the whole
 * transaction on an error packet, and pdo_mysql reads its transaction flag from the last success, so it still reports one
 * open. A nested write asked there must refuse — and leave Laravel's level where it found it: the first version checked
 * again inside its savepoint, which the server never opened, and Laravel's rollback of that savepoint then failed and
 * left its level one too high.
 *
 * The deadlock is made in one process: a second connection, through mysqli, holds one row and waits for the other
 * asynchronously while this connection asks for the first. The second connection has written more, so the engine makes
 * this one the victim.
 */
it('refuses a nested write after the server ended the transaction on an error, and keeps Laravel\'s level', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Only pdo_mysql reads its transaction flag from the last success rather than the server.');
    }

    $config = DB::connection()->getConfig();
    $rival = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], (string) $config['database'], (int) $config['port']);
    $rival->query('SET SESSION innodb_lock_wait_timeout = 5');

    // Two rows both connections can see, committed by the rival and removed by it afterwards.
    $slug = 'deadlock-'.bin2hex(random_bytes(3));
    $rival->query("INSERT INTO orgs (slug, name) VALUES ('{$slug}-a', 'A'), ('{$slug}-b', 'B')");
    $a = (int) $rival->query("SELECT id FROM orgs WHERE slug = '{$slug}-a'")->fetch_row()[0];
    $b = (int) $rival->query("SELECT id FROM orgs WHERE slug = '{$slug}-b'")->fetch_row()[0];

    try {
        $rival->query('START TRANSACTION');

        for ($i = 0; $i < 200; $i++) {
            $rival->query("INSERT INTO orgs (slug, name) VALUES ('{$slug}-weight-{$i}', 'Weight')");
        }

        $rival->query("SELECT id FROM orgs WHERE id = {$b} FOR UPDATE");
        DB::table('orgs')->where('id', $a)->lockForUpdate()->first();
        $rival->query("SELECT id FROM orgs WHERE id = {$a} FOR UPDATE", MYSQLI_ASYNC);
        usleep(200_000);

        $deadlocked = false;

        try {
            DB::table('orgs')->where('id', $b)->lockForUpdate()->first();
        } catch (QueryException $deadlock) {
            $deadlocked = str_contains($deadlock->getMessage(), 'Deadlock');
        }

        $level = DB::transactionLevel();
        $refused = null;

        try {
            $this->entry->update(['title' => 'Autocommitted']);
        } catch (RuntimeException $refusal) {
            $refused = $refusal;
        }

        $levelAfter = DB::transactionLevel();

        expect($deadlocked)->toBeTrue()
            ->and($refused?->getMessage())->toContain('already ended the transaction')
            ->and($levelAfter)->toBe($level)
            ->and(DB::table('entries')->where('id', $this->entry->id)->value('title'))->not->toBe('Autocommitted');
    } finally {
        $rival->reap_async_query();
        $rival->query('ROLLBACK');
        $rival->query("DELETE FROM orgs WHERE slug IN ('{$slug}-a', '{$slug}-b')");
        $rival->close();

        // The engine ended the suite's own transaction too; open one for it to roll back.
        if (! DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});
