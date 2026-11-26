<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Benchmarks\SchemaScaleEstimatedPlans;

require_once dirname(__DIR__, 3).'/bin/benchmarks/SchemaScaleEstimatedPlans.php';

it('counts each captured SELECT and keeps plans for distinct binding sets', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO entries (id) VALUES (1), (2)');
    $pdo->exec('PRAGMA query_only = ON');

    $plans = new SchemaScaleEstimatedPlans($pdo, 'sqlite');
    $lookup = 'SELECT id FROM entries WHERE id = ?';
    $first = ['sql' => $lookup, 'bindings' => [1]];
    $second = ['sql' => $lookup, 'bindings' => [2]];

    $plans->record([$first, $first, $second]);
    expect($plans->occurrences)->toBe(3)
        ->and($plans->digests)->toHaveCount(2)
        ->and(array_keys($plans->digests))->toBe([
            hash('sha256', json_encode([$lookup, [1]], JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode([$lookup, [2]], JSON_THROW_ON_ERROR)),
        ]);

    expect(fn () => $plans->record([['sql' => 'DELETE FROM entries', 'bindings' => []]]))
        ->toThrow(RuntimeException::class, 'Only captured SELECT statements are planned.');
    expect($pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn())->toBe(2);
});

it('refuses to defer an unfamiliar MariaDB locking query', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $plans = new SchemaScaleEstimatedPlans($pdo, 'mariadb', allowMariaDbLockPlans: true);

    expect(fn () => $plans->record([['sql' => 'SELECT id FROM entries FOR UPDATE', 'bindings' => []]]))
        ->toThrow(RuntimeException::class, 'The captured locking SELECT is outside the benchmark allowlist.');
    $plans->replayMariaDbLocks();
    expect($plans->mariaDbLockPlans)->toBe(0)->and($plans->digests)->toBe([]);
});
