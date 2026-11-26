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
        throw new InvalidArgumentException('Use: php schema-scale-picker-plans.php APPLICATION CANONICAL_FIXTURE');
    }
    if (file_exists($fixture.'/panel-writes.started') || ! is_file($fixture.'/browser-stage.active')) {
        throw new RuntimeException('Replay picker plans during its read stage.');
    }
    $connection = SchemaScaleOwnedReadConnection::open(dirname(__DIR__, 2), $application, $fixture);
    $receiptFile = SchemaScaleOwnedReadConnection::privateFile($fixture, 'schema-scale-panel-control-sql.partial.json');
    $receipt = json_decode(file_get_contents($receiptFile), true, flags: JSON_THROW_ON_ERROR);
    if (($receipt['complete'] ?? null) !== false || ($receipt['capture_sql'] ?? null) !== true
        || ($receipt['engine'] ?? null) !== $connection->driver || ($receipt['rows'] ?? null) !== $connection->rows
        || ($receipt['observations_per_case'] ?? null) !== 25 || count($receipt['observations'] ?? []) !== 4
        || count($receipt['permissions'] ?? []) !== 8 || count($receipt['membership'] ?? []) !== 4
        || ($receipt['fixture']['seed_result_sha256'] ?? null) !== $connection->seedDigest) {
        throw new RuntimeException('The picker browser receipt must match the seed.');
    }

    $expected = [];
    $sites = [2, 1, 1, 3];
    foreach ($receipt['observations'] as $index => $case) {
        if (count($case['tags']) !== 25 || count($case['instrumented_elapsed_ms']) !== 25
            || ! preg_match('~\A/admin/scale-site-'.$sites[$index].'/c/article/[1-9][0-9]*/edit\z~D', $case['route'])) {
            throw new RuntimeException('The picker browser receipt has an incomplete Site case.');
        }
        foreach ($case['tags'] as $tag) {
            if ($tag !== sprintf('k%05d', count($expected) + 1)) {
                throw new RuntimeException('The picker browser receipt must name each profile in order.');
            }
            $expected[$tag] = $case['route'];
        }
    }

    $directory = $fixture.'/picker-sql';
    $files = glob($directory.'/k[0-9][0-9][0-9][0-9][0-9].json');
    if ($files === false || count($files) !== count($expected) || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('The picker profiles must be present and private.');
    }

    $plans = new SchemaScaleEstimatedPlans($connection->pdo, $connection->driver);
    foreach ($expected as $tag => $route) {
        $file = SchemaScaleOwnedReadConnection::privateFile($directory, $tag.'.json');
        if (filesize($file) > 1024 * 1024) {
            throw new RuntimeException('The picker profile exceeds the expected size.');
        }
        $profile = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($profile['version'] ?? null) !== 1 || ($profile['component']['method'] ?? null) !== 'callSchemaComponentMethod'
            || ! is_string($profile['component']['name'] ?? null) || $profile['component']['name'] === ''
            || $profile['tag'] !== $tag || $profile['path'] !== $route || $profile['status'] !== 200 || $profile['overflow'] !== false
            || ! is_array($profile['selects']) || $profile['selects'] === []) {
            throw new RuntimeException('The picker SQL profile is invalid.');
        }
        $plans->record($profile['selects']);
    }

    echo json_encode([
        'complete' => true,
        'engine' => $connection->driver,
        'rows' => $connection->rows,
        'profiles' => count($files),
        'select_occurrences' => $plans->occurrences,
        'pairs_with_plans' => count($plans->digests),
        'plan_digests' => $plans->digests,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Picker plans refused: '.$error::class."\n");
    exit(1);
}
