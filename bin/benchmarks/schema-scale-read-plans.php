<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Benchmarks\SchemaScaleOwnedReadConnection;

require_once __DIR__.'/SchemaScaleOwnedReadConnection.php';

try {
    if (count($argv) !== 3 || ($application = realpath($argv[1])) === false || ($fixture = realpath($argv[2])) !== $argv[2]) {
        throw new InvalidArgumentException('Use: php bin/benchmarks/schema-scale-read-plans.php APPLICATION CANONICAL_FIXTURE');
    }
    if (file_exists($fixture.'/panel-writes.started')) {
        throw new RuntimeException('Replay read plans before starting panel writes.');
    }
    $connection = SchemaScaleOwnedReadConnection::open(dirname(__DIR__, 2), $application, $fixture);
    $pdo = $connection->pdo;
    $driver = $connection->driver;
    $rows = $connection->rows;
    $prefix = match ($driver) {
        'sqlite' => 'EXPLAIN QUERY PLAN ',
        'pgsql' => 'EXPLAIN (FORMAT JSON) ',
        'mysql', 'mariadb' => 'EXPLAIN FORMAT=JSON ',
    };

    $browser = json_decode(file_get_contents(SchemaScaleOwnedReadConnection::privateFile($fixture, 'schema-scale-panel-sql.partial.json')), true, flags: JSON_THROW_ON_ERROR);
    $observations = $browser['observations_per_case'] ?? null;
    if (($browser['complete'] ?? null) !== false || ($browser['capture_sql'] ?? null) !== true || ($browser['rows'] ?? null) !== $rows
        || ($browser['cases'] ?? null) !== 45 || $observations !== 25
        || count($browser['observations'] ?? []) !== 45
        || ($browser['fixture']['seed_result_sha256'] ?? null) !== $connection->seedDigest) {
        throw new RuntimeException('A completed panel-read receipt must match the seed.');
    }

    $timedSites = [];
    foreach ($browser['observations'] as $case) {
        if (! in_array($case['site'], [1, 2, 3, 4, 5], true) || count($case['elapsed_ms']) !== $observations || count($case['sample_tags']) !== $observations) {
            throw new RuntimeException('A completed panel-read case is missing.');
        }
        foreach ($case['sample_tags'] as $tag) {
            if (isset($timedSites[$tag])) {
                throw new RuntimeException('A timed request was reused.');
            }
            $timedSites[$tag] = $case['site'];
        }
    }

    $directory = $fixture.'/read-sql';
    if (! is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('The benchmark SQL directory must be private.');
    }
    $files = glob($directory.'/p[0-9][0-9][0-9][0-9][0-9].json');
    if ($files === false || count($files) !== 5 + 45 * ($observations + 1)) {
        throw new RuntimeException('The benchmark needs a bounded set of read profiles.');
    }

    $plans = [];
    $occurrences = 0;
    foreach ($files as $index => $file) {
        $tag = sprintf('p%05d', $index + 1);
        $profileFile = SchemaScaleOwnedReadConnection::privateFile($directory, $tag.'.json');
        if ($file !== $profileFile || filesize($profileFile) > 1024 * 1024) {
            throw new RuntimeException('The SQL profile sequence must be complete.');
        }
        $profile = json_decode(file_get_contents($profileFile), true, flags: JSON_THROW_ON_ERROR);
        if (isset($timedSites[$tag])) {
            $matched = explode('/', $profile['path'])[2] ?? '';
            if ($matched !== 'scale-site-'.$timedSites[$tag]) {
                throw new RuntimeException('A timed query profile belongs to the wrong Site.');
            }
            unset($timedSites[$tag]);
        }

        if (basename($file) !== $profile['tag'].'.json' || $profile['status'] !== 200 || $profile['overflow'] !== false
            || ! is_array($profile['selects']) || $profile['selects'] === []) {
            throw new RuntimeException('A completed read profile is missing.');
        }
        foreach ($profile['selects'] as $query) {
            if (! preg_match('/\A\s*select\b/i', $query['sql']) || ! array_is_list($query['bindings'])) {
                throw new RuntimeException('Only captured SELECT statements are planned.');
            }
            $occurrences++;
            $id = hash('sha256', json_encode([$query['sql'], $query['bindings']], JSON_THROW_ON_ERROR));
            if (isset($plans[$id])) {
                continue;
            }

            $statement = $pdo->prepare($prefix.$query['sql']);
            foreach ($query['bindings'] as $position => $binding) {
                $type = match (true) {
                    $binding === null => PDO::PARAM_NULL,
                    is_int($binding) => PDO::PARAM_INT,
                    is_float($binding), is_string($binding) => PDO::PARAM_STR,
                    default => throw new RuntimeException('Unsupported SELECT binding.'),
                };
                $statement->bindValue($position + 1, $binding, $type);
            }
            $statement->execute();
            $plan = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($plan === []) {
                throw new RuntimeException('The database did not return an estimated plan.');
            }
            $plans[$id] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        }
    }

    if ($timedSites !== []) {
        throw new RuntimeException('A timed query profile was missing.');
    }

    echo json_encode([
        'complete' => true,
        'engine' => $driver,
        'rows' => $rows,
        'profiles' => count($files),
        'select_occurrences' => $occurrences,
        'pairs_with_plans' => count($plans),
        'plan_digests' => $plans,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Connection and statement errors may include private fixture bindings.
    fwrite(STDERR, 'Benchmark plans refused: '.$error::class.'
');
    exit(1);
}
