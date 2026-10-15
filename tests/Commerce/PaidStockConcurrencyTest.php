<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function paidStockCopyApplication(string $source, string $target): void
{
    File::ensureDirectoryExists($target);
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->getFilename() === 'vendor' || $item->isLink()) {
            continue;
        }
        $destination = $target.'/'.$item->getFilename();
        if ($item->isDir()) {
            if (str_ends_with($destination, '/bootstrap/cache')) {
                File::ensureDirectoryExists($destination);
            } else {
                paidStockCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function paidStockBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Paid stock child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Paid stock barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('serialises sibling-site paid allocations and stale manual changes at native engine locks', function (string $scenario): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-paid-stock-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    touch($directory.'/race.sqlite');
    foreach (['controller', 'A', 'B', 'read', 'cleanup'] as $role) {
        paidStockCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }
    $processes = [];
    $child = static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/PaidStockRaceProbe.php', $root, $directory, $mode, $role, $scenario], $root);
        $process->setTimeout(35);
        $processes[] = $process;

        return $process;
    };
    try {
        $init = $child('init', 'controller');
        $init->mustRun();
        $initialized = paidStockBarrier($directory.'/initialized.json', [$init]);
        $selected = getenv('DB_CONNECTION') ?: 'testing';
        $expectedDriver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
        expect($initialized['driver'])->toBe($expectedDriver)->and($initialized['level'])->toBe(0)->and($initialized['pdoTransaction'])->toBeFalse();
        $a = $child('worker', 'A');
        $a->start();
        $paused = paidStockBarrier($directory.'/A-paused.json', [$a]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdoTransaction'])->toBeTrue();
        $b = $child('worker', 'B');
        $b->start();
        $attempted = paidStockBarrier($directory.'/B-attempted.json', [$a, $b]);
        expect($attempted['driver'])->toBe($expectedDriver)->and($attempted['level'])->toBe(1);
        usleep(100000);
        expect($b->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $a->wait();
        $b->wait();
        expect($a->isSuccessful())->toBeTrue($a->getOutput().$a->getErrorOutput())->and($b->isSuccessful())->toBeTrue($b->getOutput().$b->getErrorOutput());
        foreach (['A', 'B'] as $role) {
            expect(paidStockBarrier($directory.'/'.$role.'-result.json'))->toBe(['refused' => $role === 'B' && $scenario === 'manual', 'level' => 0, 'pdoTransaction' => false]);
            $child('worker', $role)->mustRun();
        }
        $child('read', 'read')->mustRun();
        $expected = $scenario === 'manual'
            ? ['payments' => 1, 'allocations' => 2, 'awarded' => 2, 'unavailable' => 0, 'refunds' => 0, 'owed' => '0', 'movements' => 2, 'paidAudits' => 1]
            : ['payments' => 2, 'allocations' => 4, 'awarded' => 3, 'unavailable' => 1, 'refunds' => 1, 'owed' => '2100', 'movements' => 3, 'paidAudits' => 2];
        expect(paidStockBarrier($directory.'/readback.json'))->toBe(['driver' => $expectedDriver, 'stock' => '0', 'untracked' => null,
            ...$expected, 'grants' => 0, 'level' => 0, 'pdoTransaction' => false]);
    } finally {
        touch($directory.'/release-A');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
        try {
            $child('cleanup', 'cleanup')->mustRun();
        } finally {
            File::deleteDirectory($directory);
        }
    }
})->with(['orders', 'manual']);
