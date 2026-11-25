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

require_once __DIR__.'/Fixtures/BootstrapAdapters.php';

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Manifest.php';
    require_once dirname(__DIR__, 3).'/bin/release/Signature.php';
    $this->dir = sys_get_temp_dir().'/kitsune-bootstrap-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir.'/bin', 0700, true);
    File::makeDirectory($this->dir.'/tmp', 0700);
    File::makeDirectory($this->dir.'/server', 0700);
    File::makeDirectory($this->dir.'/output', 0700);
    File::put($this->dir.'/fixture.tar', 'fixture');
    $this->manifest = "kitsune-release-manifest-v1\nversion\tv1.0.0-test.213\nchannel\ttest\n"
        ."source\t".str_repeat('a', 40)."\ntree\t".str_repeat('b', 40)."\nsignature\trsa-pkcs1-sha256\n"
        ."artifact\tfixture.tar\tprovenance\tany\t7\t".hash('sha256', 'fixture')."\n"
        ."package\tkitsune/core\tdev-".str_repeat('a', 40)."\t".str_repeat('a', 40)."\t".str_repeat('c', 64)."\tsplit\n";
    $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($this->key)['key'];
    File::put($this->dir.'/release.pub', $public);
    $this->pin = Signature::fingerprint($public);
    bootstrapSignFixture($this->dir, $this->manifest, $this->key);

    // Bare-clone tests need PHP/bash, not an additional crypto executable. This strict adapter
    // performs real RSA/SHA-256 verification with the existing PHP OpenSSL extension.
    // Separate retained integration checks exercise actual OpenSSL/LibreSSL CLI binaries.
    File::put($this->dir.'/bin/openssl', '#!'.PHP_BINARY."\n".bootstrapOpenSslAdapter());
    chmod($this->dir.'/bin/openssl', 0700);
    File::put($this->dir.'/bin/php', "#!/usr/bin/env bash\nprintf called > \"\$BOOTSTRAP_PHP_CALLED\"\nexit 99\n");
    chmod($this->dir.'/bin/php', 0700);
    File::put($this->dir.'/bin/curl', '#!'.PHP_BINARY."\n".bootstrapCurlAdapter());
    chmod($this->dir.'/bin/curl', 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function bootstrapSignFixture(string $directory, string $manifest, OpenSSLAsymmetricKey $key): void
{
    openssl_sign($manifest, $signature, $key, OPENSSL_ALGO_SHA256);
    File::put($directory.'/release.manifest', $manifest);
    File::put($directory.'/release.sig', $signature);
}

function bootstrapCommand(string $directory, string $pin, array $extra = []): Process
{
    $process = new Process([
        'bash', dirname(__DIR__, 3).'/bin/verify-release.sh',
        '--manifest='.$directory.'/release.manifest', '--signature='.$directory.'/release.sig',
        '--key='.$directory.'/release.pub', '--fingerprint='.$pin,
        '--version=v1.0.0-test.213', '--channel=test', '--directory='.$directory, ...$extra,
    ], null, ['PATH' => $directory.'/bin:'.getenv('PATH'), 'TMPDIR' => $directory.'/tmp', 'BOOTSTRAP_PHP_CALLED' => $directory.'/php-called']);
    $process->run();

    return $process;
}

function bootstrapCurlAdapter(): string
{
    return <<<'PHP'
    <?php

    /*
     * This Source Code Form is subject to the terms of the Mozilla Public
     * License, v. 2.0. If a copy of the MPL was not distributed with this
     * file, You can obtain one at https://mozilla.org/MPL/2.0/.
     */

    declare(strict_types=1);

    $a = array_slice($argv, 1);
    if ($a[0] !== '--disable' || in_array('--location', $a, true) || in_array('-L', $a, true)
        || ! in_array('--fail', $a, true) || ! in_array('--tlsv1.2', $a, true)) { exit(96); }
    foreach (['--proto' => '=https', '--proto-redir' => '=https', '--max-redirs' => '0', '--write-out' => '%{http_code}'] as $name => $value) {
        $index = array_search($name, $a, true);
        if ($index === false || ($a[$index + 1] ?? null) !== $value) { exit(96); }
    }
    $url = $a[array_key_last($a)];
    $prefix = 'https://kitsunecms.org/releases/v1.0.0-test.213/';
    if (! str_starts_with($url, $prefix)) { exit(97); }
    $name = substr($url, strlen($prefix));
    if (! in_array($name, ['release.manifest', 'release.manifest.sig', 'fixture.tar'], true)) { exit(98); }
    $destination = $a[array_search('--output', $a, true) + 1];
    file_put_contents(getenv('BOOTSTRAP_REQUESTS'), $name."\n", FILE_APPEND);
    $case = getenv('BOOTSTRAP_CURL_CASE');
    if (str_starts_with($case, 'redirect')) {
        file_put_contents($destination, 'moved'); echo substr($case, 8); exit(0);
    }
    if ($case === 'missing-signature' && $name === 'release.manifest.sig') { echo '404'; exit(22); }
    if ($case === 'oversized-manifest' && $name === 'release.manifest') {
        file_put_contents($destination, str_repeat('x', 65537)); echo '200'; exit(0);
    }
    file_put_contents($destination, file_get_contents(getenv('BOOTSTRAP_SERVER').'/'.$name));
    echo '200';
    PHP;
}

function bootstrapFetchCommand(string $directory, string $pin, string $case = ''): Process
{
    File::copy($directory.'/release.manifest', $directory.'/server/release.manifest');
    File::copy($directory.'/release.sig', $directory.'/server/release.manifest.sig');
    File::put($directory.'/server/fixture.tar', $case === 'corrupt-payload' ? 'changed' : 'fixture');
    $process = new Process([
        'bash', dirname(__DIR__, 3).'/bin/verify-release.sh', 'fetch',
        '--key='.$directory.'/release.pub', '--fingerprint='.$pin,
        '--version=v1.0.0-test.213', '--channel=test', '--directory='.$directory.'/output', '--artifact=fixture.tar',
    ], null, ['PATH' => $directory.'/bin:'.getenv('PATH'), 'TMPDIR' => $directory.'/tmp',
        'BOOTSTRAP_PHP_CALLED' => $directory.'/php-called', 'BOOTSTRAP_SERVER' => $directory.'/server',
        'BOOTSTRAP_REQUESTS' => $directory.'/requests', 'BOOTSTRAP_CURL_CASE' => $case]);
    $process->run();

    return $process;
}

it('verifies signed artifact bytes without invoking PHP or executing a payload', function (): void {
    $process = bootstrapCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['verified'])->toBeTrue()->and($receipt['artifact_count'])->toBe(1)
        ->and($receipt['package_count'])->toBe(1)
        ->and(file_exists($this->dir.'/php-called'))->toBeFalse()
        ->and(File::directories($this->dir.'/tmp'))->toBe([])
        ->and(File::get($this->dir.'/fixture.tar'))->toBe('fixture');
});

it('refuses authenticated but invalid bootstrap metadata before using artifact rows', function (Closure $change): void {
    bootstrapSignFixture($this->dir, $change($this->manifest), $this->key);
    $process = bootstrapCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('')
        ->and(File::directories($this->dir.'/tmp'))->toBe([])
        ->and(file_exists($this->dir.'/php-called'))->toBeFalse();
})->with([
    'unsupported format' => [fn ($s) => str_replace('manifest-v1', 'manifest-v2', $s)],
    'duplicate header' => [fn ($s) => str_replace("channel\ttest\n", "channel\ttest\nchannel\ttest\n", $s)],
    'missing final LF' => [fn ($s) => rtrim($s, "\n")],
    'CRLF' => [fn ($s) => str_replace("\n", "\r\n", $s)],
    'path traversal' => [fn ($s) => str_replace('fixture.tar', '../fixture.tar', $s)],
    'encoded path' => [fn ($s) => str_replace('fixture.tar', 'dir%2ffixture.tar', $s)],
    'duplicate artifact' => [fn ($s) => preg_replace('/(artifact[^\n]+\n)/', '$1$1', $s)],
    'unknown role' => [fn ($s) => str_replace("\tprovenance\t", "\texecute\t", $s)],
    'noncanonical size' => [fn ($s) => str_replace("\t7\t", "\t07\t", $s)],
    'oversized artifact' => [fn ($s) => str_replace("\t7\t", "\t10737418241\t", $s)],
    'unknown signature format' => [fn ($s) => str_replace('rsa-pkcs1-sha256', 'rsa-sha1', $s)],
    'development reference mismatch' => [fn ($s) => str_replace('dev-'.str_repeat('a', 40), 'dev-'.str_repeat('d', 40), $s)],
    'unknown row' => [fn ($s) => $s."unknown\t1\t2\t3\t4\t5\n"],
]);

it('refuses bad authentication, artifact bytes and duplicate or execution options', function (string $change): void {
    $pin = $this->pin;
    $extra = [];
    match ($change) {
        'wrong pin' => $pin = str_repeat('0', 64),
        'missing signature' => unlink($this->dir.'/release.sig'),
        'corrupt signature' => File::put($this->dir.'/release.sig', str_repeat('x', 384)),
        'corrupt payload' => File::put($this->dir.'/fixture.tar', 'changed'),
        'missing payload' => unlink($this->dir.'/fixture.tar'),
        'symbolic payload' => (function (): void {
            rename($this->dir.'/fixture.tar', $this->dir.'/other');
            symlink($this->dir.'/other', $this->dir.'/fixture.tar');
        })(),
        'multiple-link payload' => link($this->dir.'/fixture.tar', $this->dir.'/other'),
        'duplicate option' => $extra = ['--version=v1.0.1'],
        'execute request' => $extra = ['--execute'],
    };
    $process = bootstrapCommand($this->dir, $pin, $extra);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('')
        ->and(File::directories($this->dir.'/tmp'))->toBe([])
        ->and(file_exists($this->dir.'/executed'))->toBeFalse();
})->with(['wrong pin', 'missing signature', 'corrupt signature', 'corrupt payload', 'missing payload', 'symbolic payload', 'multiple-link payload', 'duplicate option', 'execute request']);

it('downloads only the selected authenticated artifact into a new exclusive file', function (): void {
    $process = bootstrapFetchCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['verified'])->toBeTrue()->and($receipt['artifact_name'])->toBe('fixture.tar')
        ->and(File::get($this->dir.'/output/fixture.tar'))->toBe('fixture')
        ->and(File::get($this->dir.'/requests'))->toBe("release.manifest\nrelease.manifest.sig\nfixture.tar\n")
        ->and(File::directories($this->dir.'/output'))->toBe([])
        ->and(file_exists($this->dir.'/php-called'))->toBeFalse();
});

it('does not promote any artifact after redirect, missing, oversized or corrupt transport', function (string $case): void {
    $process = bootstrapFetchCommand($this->dir, $this->pin, $case);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('')
        ->and(File::files($this->dir.'/output'))->toBe([])
        ->and(File::directories($this->dir.'/output'))->toBe([]);
    if (str_starts_with($case, 'redirect')) {
        expect(File::get($this->dir.'/requests'))->toBe("release.manifest\n");
    }
})->with(['redirect301', 'redirect302', 'redirect303', 'redirect307', 'redirect308', 'missing-signature', 'oversized-manifest', 'corrupt-payload']);

it('refuses invalid authentication or schema before requesting any payload', function (string $case): void {
    $pin = $this->pin;
    match ($case) {
        'wrong pin' => $pin = str_repeat('0', 64),
        'bad signature' => File::put($this->dir.'/release.sig', str_repeat('x', 384)),
        'signed unknown format' => bootstrapSignFixture($this->dir, str_replace('manifest-v1', 'manifest-v2', $this->manifest), $this->key),
    };
    $process = bootstrapFetchCommand($this->dir, $pin);
    $requests = File::exists($this->dir.'/requests') ? File::get($this->dir.'/requests') : '';
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('')
        ->and($requests)->not->toContain('fixture.tar')
        ->and(File::files($this->dir.'/output'))->toBe([])
        ->and(File::directories($this->dir.'/output'))->toBe([]);
})->with(['wrong pin', 'bad signature', 'signed unknown format']);

it('never clobbers an existing output file', function (): void {
    File::put($this->dir.'/output/fixture.tar', 'operator-owned');
    $process = bootstrapFetchCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeFalse()
        ->and(File::get($this->dir.'/output/fixture.tar'))->toBe('operator-owned')
        ->and(File::directories($this->dir.'/output'))->toBe([]);
});
