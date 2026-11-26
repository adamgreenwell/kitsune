<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/** @param array<string, mixed> $record */
function publishSchemaScaleReceipt(string $target, array $record): string
{
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if (file_exists($target) || file_put_contents($target.'.tmp', $json) !== strlen($json) || ! rename($target.'.tmp', $target)) {
        throw new RuntimeException('The receipt could not be published.');
    }

    return hash('sha256', $json);
}

// Called inside the paired launcher's container after it has checked the cgroup files.
try {
    if (count($argv) !== 13 || ! in_array($argv[1], ['floor', 'control'], true)
        || ! in_array($argv[2], ['preflight', 'run'], true) || basename($argv[12]) !== $argv[1]) {
        throw new RuntimeException('The profile receipt inputs are invalid.');
    }

    [, $profile, $mode, $source, $image, $rows, $cpuQuota, $cpuPeriod, $memory, $swap, $pids, $runtimeJson, $directory] = $argv;
    umask(0077);
    $runtime = json_decode($runtimeJson, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($runtime) || ! is_string($runtime['php'] ?? null) || ! is_string($runtime['sqlite'] ?? null)
        || ($runtime['php_memory'] ?? null) !== '128M'
        || ! is_string($runtime['composer_lock_sha256'] ?? null)
        || ! preg_match('/\A[a-f0-9]{64}\z/', $runtime['composer_lock_sha256'])) {
        throw new RuntimeException('The profile runtime receipt is invalid.');
    }
    $result = null;
    if ($mode === 'run') {
        $filename = $directory.'/result.json';
        $bytes = is_file($filename) ? file_get_contents($filename) : false;
        $report = is_string($bytes) ? json_decode($bytes, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($report) || ($report['rows'] ?? null) !== (int) $rows || ($report['engine'] ?? null) !== 'sqlite'
            || ($report['php'] ?? null) !== $runtime['php'] || ($report['version'] ?? null) !== $runtime['sqlite']
            || ($report['php_memory_limit'] ?? null) !== $runtime['php_memory']
            || ! is_int($report['checks_passed'] ?? null) || $report['checks_passed'] < 1) {
            throw new RuntimeException('The completed workload report does not match its profile.');
        }

        $result = ['file' => $profile.'/result.json', 'sha256' => hash('sha256', $bytes)];
    }

    $identity = [
        'version' => 1,
        'mode' => $mode,
        'source_commit' => $source,
        'image_id' => $image,
        'rows' => (int) $rows,
        'runtime' => $runtime,
    ];
    $receipt = $identity + [
        'profile' => $profile,
        'cgroup' => [
            'cpu_quota_us' => (int) $cpuQuota,
            'cpu_period_us' => (int) $cpuPeriod,
            'memory_bytes' => (int) $memory,
            'swap_bytes' => (int) $swap,
            'max_pids' => (int) $pids,
        ],
        'result' => $result,
    ];

    $target = $directory.'.receipt.json';
    if ($profile === 'floor') {
        publishSchemaScaleReceipt($target, $receipt);
    } else {
        $parent = dirname($directory);
        $floorPath = $parent.'/floor.receipt.json';
        $floorBytes = is_file($floorPath) ? file_get_contents($floorPath) : false;
        $floor = is_string($floorBytes) ? json_decode($floorBytes, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($floor) || ($floor['profile'] ?? null) !== 'floor'
            || array_intersect_key($floor, $identity) !== $identity) {
            throw new RuntimeException('The floor receipt does not match the control profile.');
        }

        $floorResult = $parent.'/floor/result.json';
        if ($mode === 'run' && (! is_file($floorResult)
            || hash_file('sha256', $floorResult) !== ($floor['result']['sha256'] ?? null))) {
            throw new RuntimeException('The floor result no longer matches its receipt.');
        }

        $controlHash = publishSchemaScaleReceipt($target, $receipt);
        publishSchemaScaleReceipt($parent.'/pair.receipt.json', $identity + [
            'profiles' => [
                'floor' => ['file' => 'floor.receipt.json', 'sha256' => hash('sha256', $floorBytes)],
                'control' => ['file' => 'control.receipt.json', 'sha256' => $controlHash],
            ],
        ]);
    }
} catch (Throwable) {
    fwrite(STDERR, "The schema-scale profile receipt could not be completed.\n");
    exit(1);
}
