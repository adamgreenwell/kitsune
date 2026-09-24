<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;

/*
 * The level-0 harness is what it claims (T10). Every test built on it rests on these, so they are checked rather than
 * assumed: the production transaction manager, two separate migrated files, and the pragmas a replaced PDO keeps.
 */

it('runs on the production transaction manager', function (): void {
    expect(get_class(app('db.transactions')))->toBe(DatabaseTransactionsManager::class)
        ->and(DB::connection()->getName())->toBe('custody')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('has two separate, migrated databases', function (): void {
    expect($this->custodyFile)->not->toBe($this->secondaryFile)
        ->and(Schema::connection('custody')->hasTable('media_files'))->toBeTrue()
        ->and(Schema::connection('secondary')->hasTable('media_files'))->toBeTrue();

    DB::connection('custody')->table('orgs')->insert(['slug' => 'only-here', 'name' => 'Only here', 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::connection('secondary')->table('orgs')->where('slug', 'only-here')->exists())->toBeFalse();
});

it('keeps foreign keys and the busy timeout on a replaced connection', function (): void {
    $pdo = FailingCommitPdo::installOn(DB::connection('custody'), $this->custodyFile);

    expect((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn())->toBe(1)
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(5000)
        ->and(DB::connection('custody')->getPdo())->toBe($pdo);
});

it('leaves no database file behind', function (): void {
    $files = [$this->custodyFile, $this->secondaryFile];

    DB::connection('custody')->statement('PRAGMA journal_mode = WAL');
    DB::connection('custody')->table('orgs')->insert(['slug' => 'wal', 'name' => 'WAL', 'created_at' => now(), 'updated_at' => now()]);

    $this->tearDown();

    foreach ($files as $file) {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            expect(is_file($file.$suffix))->toBeFalse($file.$suffix);
        }
    }

    // tearDown() ran once already; give Pest an application to tear down again.
    $this->setUp();
});
