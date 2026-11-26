<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function schemaScaleReceipt(string $directory, string $profile, string $mode, string $lockDigit = 'a'): Process
{
    $runtime = json_encode([
        'php' => '8.4.28',
        'sqlite' => '3.40.1',
        'php_memory' => '128M',
        'composer_lock_sha256' => str_repeat($lockDigit, 64),
    ], JSON_THROW_ON_ERROR);
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 3).'/bin/benchmarks/write-schema-scale-pair-receipt.php',
        $profile, $mode, str_repeat('a', 40), 'sha256:'.str_repeat('b', 64), '10000',
        $profile === 'floor' ? '100000' : '200000', '100000', $profile === 'floor' ? '1073741824' : '2147483648',
        '0', '128', $runtime, $directory.'/'.$profile,
    ]);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $this->receiptDirectory = sys_get_temp_dir().'/kitsune-scale-receipt-'.bin2hex(random_bytes(8));
    mkdir($this->receiptDirectory, 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->receiptDirectory);
});

it('records pair completion only after both matching preflights have succeeded', function (): void {
    $directory = $this->receiptDirectory;
    expect(schemaScaleReceipt($directory, 'floor', 'preflight')->isSuccessful())->toBeTrue()
        ->and(is_file($directory.'/pair.receipt.json'))->toBeFalse();
    expect(schemaScaleReceipt($directory, 'control', 'preflight', 'c')->isSuccessful())->toBeFalse()
        ->and(is_file($directory.'/control.receipt.json'))->toBeFalse()
        ->and(is_file($directory.'/pair.receipt.json'))->toBeFalse();
    expect(schemaScaleReceipt($directory, 'control', 'preflight')->isSuccessful())->toBeTrue();

    $pair = json_decode(file_get_contents($directory.'/pair.receipt.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['floor', 'control'] as $profile) {
        $bytes = file_get_contents($directory.'/'.$profile.'.receipt.json');
        $receipt = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        expect($pair['profiles'][$profile]['sha256'])->toBe(hash('sha256', $bytes))
            ->and($receipt['result'])->toBeNull();
    }
});

it('refuses to certify a workload pair with a missing or changed result', function (): void {
    $directory = $this->receiptDirectory;
    $report = json_encode([
        'rows' => 10000, 'engine' => 'sqlite', 'php' => '8.4.28', 'version' => '3.40.1',
        'php_memory_limit' => '128M', 'checks_passed' => 11347,
    ], JSON_THROW_ON_ERROR);
    mkdir($directory.'/floor', 0700);
    mkdir($directory.'/control', 0700);
    file_put_contents($directory.'/floor/result.json', $report);
    expect(schemaScaleReceipt($directory, 'floor', 'run')->isSuccessful())->toBeTrue();
    expect(schemaScaleReceipt($directory, 'control', 'run')->isSuccessful())->toBeFalse()
        ->and(is_file($directory.'/pair.receipt.json'))->toBeFalse();

    file_put_contents($directory.'/control/result.json', str_replace('11347', '0', $report));
    expect(schemaScaleReceipt($directory, 'control', 'run')->isSuccessful())->toBeFalse()
        ->and(is_file($directory.'/pair.receipt.json'))->toBeFalse();

    file_put_contents($directory.'/control/result.json', $report);
    file_put_contents($directory.'/floor/result.json', $report."\n");
    expect(schemaScaleReceipt($directory, 'control', 'run')->isSuccessful())->toBeFalse()
        ->and(is_file($directory.'/pair.receipt.json'))->toBeFalse();

    file_put_contents($directory.'/floor/result.json', $report);
    expect(schemaScaleReceipt($directory, 'control', 'run')->isSuccessful())->toBeTrue();
    $control = json_decode(file_get_contents($directory.'/control.receipt.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($control['result']['sha256'])->toBe(hash('sha256', $report))
        ->and(is_file($directory.'/pair.receipt.json'))->toBeTrue();
});
