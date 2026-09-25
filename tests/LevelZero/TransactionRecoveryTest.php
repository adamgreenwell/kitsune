<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;

/*
 * What a failed audited transaction leaves, at a real level 0 — ADR-042 decision 5 (T8, T9, and the ended transaction).
 *
 * Each of these is a state only the outermost transaction can reach, so none of them can be seen under `RefreshDatabase`.
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

/**
 * T8. A reader holding SQLite's shared lock makes the COMMIT fail with `database is locked`. Laravel resets its level
 * and, for a concurrency error, leaves the engine's transaction open — so the next BEGIN on this connection would fail.
 */
it('leaves no transaction open after a busy COMMIT, and the next one commits', function (): void {
    $pdo = DB::connection()->getPdo();
    $pdo->exec('PRAGMA busy_timeout = 50');
    expect((string) $pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('delete')
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(50);

    $reader = new PDO('sqlite:'.$this->custodyFile);
    $reader->exec('BEGIN');
    $reader->query('select count(*) from entries')->fetchColumn();

    expect(fn () => $this->entry->update(['title' => 'Busy']))->toThrow(PDOException::class, 'database is locked');

    expect($pdo->inTransaction())->toBeFalse();

    $reader->exec('COMMIT');

    DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->id)->update(['name' => 'After']));

    expect(DB::table('orgs')->where('id', $this->org->id)->value('name'))->toBe('After')
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('title'))->toBe('Original');
});

/**
 * T9. A COMMIT that fails is never reported to the transaction manager, so work registered inside the write would run
 * at the next commit anywhere in the process — for a write that never landed.
 */
it('discards work registered inside a write whose COMMIT failed', function (): void {
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $ran = false;

    AuditorStandIn::install()->beforeRecording(function () use (&$ran): void {
        DB::afterCommit(function () use (&$ran): void {
            $ran = true;
        });
    });

    $pdo->failNextCommit = 'before';

    expect(fn () => $this->entry->update(['title' => 'Lost']))->toThrow(PDOException::class, 'database is locked');

    DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->id)->update(['name' => 'Later']));

    expect($ran)->toBeFalse()
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('title'))->toBe('Original');
});

/**
 * ⚠️ A TRANSACTION THE DATABASE ALREADY ENDED. Laravel's level stays where the host left it, so without the check a nested
 * write would open a savepoint outside any transaction — on SQLite that begins a new one — and commit on its own.
 */
it('refuses a nested write once the database has ended the transaction around it', function (): void {
    $refused = null;

    try {
        DB::transaction(function () use (&$refused): void {
            // As a deadlock or a lock-wait timeout with innodb_rollback_on_timeout leaves it.
            DB::connection()->getPdo()->rollBack();

            try {
                $this->entry->update(['title' => 'Autocommitted']);
            } catch (RuntimeException $refusal) {
                $refused = $refusal;
            }
        });
    } catch (Throwable) {
        // The host's own COMMIT then fails, having nothing to commit.
    }

    expect($refused?->getMessage())->toContain('already ended the transaction')
        ->and(DB::table('entries')->where('id', $this->entry->id)->value('title'))->toBe('Original');
});
