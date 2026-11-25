<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\Signature;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/kitsune-artifact-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir, 0700);
    File::put($this->dir.'/fixture.tar', 'fixture');
    $this->manifest = "kitsune-release-manifest-v1\nversion\tv1.0.0-test.213\nchannel\ttest\n"
        ."source\t".str_repeat('a', 40)."\ntree\t".str_repeat('b', 40)."\nsignature\trsa-pkcs1-sha256\n"
        ."artifact\tfixture.tar\tprovenance\tany\t7\t".hash('sha256', 'fixture')."\n"
        ."package\tkitsune/core\tdev-".str_repeat('a', 40)."\t".str_repeat('a', 40)."\t".str_repeat('c', 64)."\tsplit\n";
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($key)['key'];
    openssl_sign($this->manifest, $signature, $key, OPENSSL_ALGO_SHA256);
    $this->passphrase = bin2hex(random_bytes(24));
    openssl_pkey_export($key, $this->encryptedPrivateKey, $this->passphrase);
    File::put($this->dir.'/release.manifest', $this->manifest);
    File::put($this->dir.'/release.sig', $signature);
    File::put($this->dir.'/release.pub', $public);
    require_once dirname(__DIR__, 3).'/bin/release/Manifest.php';
    require_once dirname(__DIR__, 3).'/bin/release/Signature.php';
    $this->pin = Signature::fingerprint($public);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** Execute the shipped command; no network, extraction, secret argument or service. */
function artifactVerifyCommand(string $directory, string $pin, array $extra = []): Process
{
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 3).'/bin/release-artifacts.php', 'verify',
        '--manifest='.$directory.'/release.manifest', '--signature='.$directory.'/release.sig',
        '--key='.$directory.'/release.pub', '--fingerprint='.$pin,
        '--version=v1.0.0-test.213', '--channel=test', '--directory='.$directory, ...$extra,
    ]);
    $process->run();

    return $process;
}

it('authenticates all artifact bytes through the shipped standalone command', function (): void {
    $process = artifactVerifyCommand($this->dir, $this->pin);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['verified'])->toBeTrue()
        ->and($receipt['version'])->toBe('v1.0.0-test.213')
        ->and($receipt['artifact_count'])->toBe(1)
        ->and(File::get($this->dir.'/fixture.tar'))->toBe('fixture');
});

it('does not mistake its own input access-time updates for changed content', function (): void {
    foreach (File::files($this->dir) as $file) {
        touch($file->getPathname(), $file->getMTime(), 1);
    }
    $process = artifactVerifyCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('refuses tampered, missing or indirect artifact bytes without writing or executing', function (string $change): void {
    match ($change) {
        'same-size corruption' => File::put($this->dir.'/fixture.tar', 'changed'),
        'truncated' => File::put($this->dir.'/fixture.tar', 'short'),
        'missing' => unlink($this->dir.'/fixture.tar'),
        'symlink' => (function (): void {
            rename($this->dir.'/fixture.tar', $this->dir.'/other');
            symlink($this->dir.'/other', $this->dir.'/fixture.tar');
        })(),
        'hardlink' => link($this->dir.'/fixture.tar', $this->dir.'/other'),
        'directory' => (function (): void {
            unlink($this->dir.'/fixture.tar');
            mkdir($this->dir.'/fixture.tar');
        })(),
    };

    $before = File::get($this->dir.'/release.manifest');
    $process = artifactVerifyCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('refused')
        ->and(File::get($this->dir.'/release.manifest'))->toBe($before)
        ->and(file_exists($this->dir.'/executed'))->toBeFalse();
})->with(['same-size corruption', 'truncated', 'missing', 'symlink', 'hardlink', 'directory']);

it('does not accept missing or oversized control files, duplicate options or execution requests', function (string $change): void {
    $extra = [];
    match ($change) {
        'missing signature' => unlink($this->dir.'/release.sig'),
        'missing key' => unlink($this->dir.'/release.pub'),
        'oversized manifest' => File::put($this->dir.'/release.manifest', str_repeat('x', 65537)),
        'duplicate option' => $extra = ['--channel=stable'],
        'execute request' => $extra = ['--execute'],
    };

    $process = artifactVerifyCommand($this->dir, $this->pin, $extra);
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toBe('')
        ->and(file_exists($this->dir.'/executed'))->toBeFalse();
})->with(['missing signature', 'missing key', 'oversized manifest', 'duplicate option', 'execute request']);

it('signs through stdin and fd3 without a secret file or secret argument', function (): void {
    $process = proc_open([
        PHP_BINARY, dirname(__DIR__, 3).'/bin/release-artifacts.php', 'sign',
        '--manifest='.$this->dir.'/release.manifest', '--key='.$this->dir.'/release.pub',
        '--fingerprint='.$this->pin, '--version=v1.0.0-test.213', '--channel=test',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']], $pipes);
    fwrite($pipes[0], $this->encryptedPrivateKey);
    fclose($pipes[0]);
    fwrite($pipes[3], $this->passphrase);
    fclose($pipes[3]);
    $signature = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0)->and($error)->toBe('')->and(strlen($signature))->toBe(384)
        ->and(openssl_verify($this->manifest, $signature, File::get($this->dir.'/release.pub'), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(File::files($this->dir))->toHaveCount(4);
});
