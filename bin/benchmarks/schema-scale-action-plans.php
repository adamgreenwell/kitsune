<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Benchmarks\SchemaScaleEstimatedPlans;
use Kitsune\Benchmarks\SchemaScaleOwnedReadConnection;

require_once __DIR__.'/SchemaScaleEstimatedPlans.php';
require_once __DIR__.'/SchemaScaleOwnedReadConnection.php';

try {
    if (count($argv) !== 3 || ($application = realpath($argv[1])) === false || ($fixture = realpath($argv[2])) !== $argv[2]) {
        throw new InvalidArgumentException('Provide an application and canonical action fixture.');
    }

    $connection = SchemaScaleOwnedReadConnection::open(dirname(__DIR__, 2), $application, $fixture);
    if (! is_file($fixture.'/panel-writes.started') || ! is_file($fixture.'/browser-stage.active')) {
        throw new RuntimeException('The action stage must own the fixture.');
    }

    $receiptFile = SchemaScaleOwnedReadConnection::privateFile($fixture, 'schema-scale-panel-write-sql.partial.json');
    $receipt = json_decode(file_get_contents($receiptFile), true, flags: JSON_THROW_ON_ERROR);
    if (($receipt['complete'] ?? null) !== false || ($receipt['capture_sql'] ?? null) !== true
        || ($receipt['audited_writes'] ?? null) !== 600 || ($receipt['pairs_per_case'] ?? null) !== 25
        || ($receipt['engine'] ?? null) !== $connection->driver || ($receipt['rows'] ?? null) !== $connection->rows
        || count($receipt['cases'] ?? []) !== 12 || ($receipt['fixture']['seed_result_sha256'] ?? null) !== $connection->seedDigest) {
        throw new RuntimeException('The action browser receipt must match the seed.');
    }
    $directory = $fixture.'/action-sql';
    $files = glob($directory.'/a[0-9][0-9][0-9][0-9][0-9].json');
    if ($files === false || count($files) !== $receipt['audited_writes'] || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('The action profiles must be present and private.');
    }
    $expected = [];
    foreach ($receipt['cases'] as $case) {
        if (! in_array($case['entry'], [1, 3, 11], true) || count($case['tags']) !== $receipt['pairs_per_case']
            || count($case['instrumented_edit_response_ms']) !== $receipt['pairs_per_case']
            || count($case['instrumented_restore_response_ms']) !== $receipt['pairs_per_case']) {
            throw new RuntimeException('The action browser receipt has an incomplete case.');
        }
        foreach ($case['tags'] as $pair) {
            if (array_keys($pair) !== ['change', 'restore']) {
                throw new RuntimeException('The action browser receipt has an incomplete pair.');
            }
            foreach ($pair as $tag) {
                if ($tag !== sprintf('a%05d', count($expected) + 1)) {
                    throw new RuntimeException('The action receipt reused a profile.');
                }
                $expected[$tag] = '/admin/scale-site-1/c/article/'.$case['entry'].'/edit';
            }
        }
    }

    $plans = new SchemaScaleEstimatedPlans($connection->pdo, $connection->driver, $connection->driver === 'mariadb');
    foreach ($files as $index => $file) {
        $tag = sprintf('a%05d', $index + 1);
        $profileFile = SchemaScaleOwnedReadConnection::privateFile($directory, $tag.'.json');
        if ($file !== $profileFile || filesize($profileFile) > 1024 * 1024) {
            throw new RuntimeException('The action profile sequence must be complete.');
        }
        $profile = json_decode(file_get_contents($profileFile), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($expected[$tag]) || ($profile['version'] ?? null) !== 1 || ($profile['component']['method'] ?? null) !== 'save'
            || ! is_string($profile['component']['name'] ?? null) || $profile['component']['name'] === '' || $profile['tag'] !== $tag
            || $profile['path'] !== $expected[$tag] || $profile['status'] !== 200 || $profile['overflow'] !== false
            || ! is_array($profile['selects']) || $profile['selects'] === []) {
            throw new RuntimeException('The action SQL profile is invalid.');
        }
        unset($expected[$tag]);

        $plans->record($profile['selects']);
    }

    if ($expected !== []) {
        throw new RuntimeException('The action profiles do not cover all timed changes and restores.');
    }
    $plans->replayMariaDbLocks();
    if ($connection->driver === 'mariadb' && $plans->mariaDbLockPlans === 0) {
        throw new RuntimeException('The MariaDB action stage must include estimated locking SELECT plans.');
    }

    echo json_encode([
        'complete' => true,
        'engine' => $connection->driver,
        'rows' => $connection->rows,
        'pairs_per_case' => $receipt['pairs_per_case'],
        'profiles' => count($files),
        'select_occurrences' => $plans->occurrences,
        'pairs_with_plans' => count($plans->digests),
        'mariadb_lock_plans' => $plans->mariaDbLockPlans,
        'plan_digests' => $plans->digests,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Action plans refused: '.$error::class."\n");
    exit(1);
}
