<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function orderClaimCopyApplication(string $source, string $target): void
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
                orderClaimCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function orderClaimBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Order claim child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Order claim barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('binds a guest order once when two authenticated readers race on independent native engine connections', function (): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-order-claim-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory, 0700);
    touch($directory.'/race.sqlite');
    foreach (['controller', 'A', 'B', 'read', 'cleanup'] as $role) {
        orderClaimCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }
    $processes = [];
    $child = static function (string $mode, string $role) use ($root, $directory, &$processes): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/OrderClaimRaceProbe.php', $root, $directory, $mode, $role], $root);
        $process->setTimeout(35);
        $processes[] = $process;

        return $process;
    };
    try {
        $init = $child('init', 'controller');
        $init->mustRun();
        $initialized = orderClaimBarrier($directory.'/initialized.json', [$init]);
        $selected = getenv('DB_CONNECTION') ?: 'testing';
        $driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
        expect($initialized['driver'])->toBe($driver)->and($initialized['level'])->toBe(0)->and($initialized['pdoTransaction'])->toBeFalse();
        $a = $child('worker', 'A');
        $b = $child('worker', 'B');
        $a->start();
        $b->start();
        foreach (['A', 'B'] as $role) {
            expect(orderClaimBarrier($directory.'/'.$role.'-ready.json', [$a, $b]))->toBe(['level' => 0, 'pdoTransaction' => false]);
        }
        touch($directory.'/go-A');
        $paused = orderClaimBarrier($directory.'/A-paused.json', [$a, $b]);
        expect($paused['level'])->toBeGreaterThanOrEqual(1)->and($paused['pdoTransaction'])->toBeTrue();
        touch($directory.'/go-B');
        $attempted = orderClaimBarrier($directory.'/B-attempted.json', [$a, $b]);
        expect($attempted['driver'])->toBe($driver)->and($attempted['level'])->toBe(1);
        usleep(100000);
        expect($b->isRunning())->toBeTrue()->and(is_file($directory.'/B-result.json'))->toBeFalse();
        touch($directory.'/release-A');
        $a->wait();
        $b->wait();
        expect($a->isSuccessful())->toBeTrue($a->getOutput().$a->getErrorOutput())->and($b->isSuccessful())->toBeTrue($b->getOutput().$b->getErrorOutput());
        foreach (['A', 'B'] as $role) {
            expect(orderClaimBarrier($directory.'/'.$role.'-result.json'))->toBe(['refused' => $role === 'B', 'level' => 0, 'pdoTransaction' => false]);
            $child('worker', $role)->mustRun();
            expect(orderClaimBarrier($directory.'/'.$role.'-result.json'))->toBe(['refused' => $role === 'B', 'level' => 0, 'pdoTransaction' => false]);
        }
        $child('read', 'read')->mustRun();
        expect(orderClaimBarrier($directory.'/readback.json'))->toBe(['driver' => $driver, 'claims' => 1,
            'reader' => (string) $initialized['readers']['A'], 'audits' => 1, 'tokens' => 0, 'grants' => 0, 'level' => 0, 'pdoTransaction' => false]);
    } finally {
        touch($directory.'/release-A');
        touch($directory.'/go-A');
        touch($directory.'/go-B');
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
});
