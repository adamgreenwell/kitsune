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
