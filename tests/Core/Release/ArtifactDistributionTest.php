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
    $this->dir = sys_get_temp_dir().'/kitsune-distribution-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir, 0700);
    File::copy(dirname(__DIR__, 3).'/bin/verify-release.sh', $this->dir.'/bootstrap.sh');
    File::put($this->dir.'/fixture.tar', 'fixture');
    $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($this->key)['key'];
    File::put($this->dir.'/release.pub', $public);
    $this->pin = Signature::fingerprint($public);
    distributionSignFixture($this->dir, $this->key);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function distributionSignFixture(string $directory, OpenSSLAsymmetricKey $key, string $role = 'bootstrap'): void
{
    $script = File::get($directory.'/bootstrap.sh');
    $manifest = "kitsune-release-manifest-v1\nversion\tv1.0.0-test.213\nchannel\ttest\n"
        ."source\t".str_repeat('a', 40)."\ntree\t".str_repeat('b', 40)."\nsignature\trsa-pkcs1-sha256\n"
        ."artifact\tbootstrap.sh\t".$role."\tany\t".strlen($script)."\t".hash('sha256', $script)."\n"
        ."artifact\tfixture.tar\tprovenance\tany\t7\t".hash('sha256', 'fixture')."\n"
        ."package\tkitsune/core\tdev-".str_repeat('a', 40)."\t".str_repeat('a', 40)."\t".str_repeat('c', 64)."\tsplit\n";
    openssl_sign($manifest, $signature, $key, OPENSSL_ALGO_SHA256);
    File::put($directory.'/release.manifest', $manifest);
    File::put($directory.'/release.sig', $signature);
}

function distributionCommand(string $directory, string $pin, string $artifact = 'fixture.tar'): Process
{
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 3).'/bin/release-artifacts.php', 'instructions',
        '--manifest='.$directory.'/release.manifest', '--signature='.$directory.'/release.sig',
        '--key='.$directory.'/release.pub', '--fingerprint='.$pin,
        '--version=v1.0.0-test.213', '--channel=test', '--directory='.$directory,
        '--bootstrap=bootstrap.sh', '--artifact='.$artifact,
    ]);
    $process->run();

    return $process;
}

it('generates concrete pinned distribution commands only from authenticated actual bytes', function (): void {
    $process = distributionCommand($this->dir, $this->pin);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['format'])->toBe('kitsune-release-instructions-v1')
        ->and($result['publication_verified'])->toBeFalse()
        ->and($result['fingerprint'])->toBe($this->pin)
        ->and($result['manifest_sha256'])->toBe(hash_file('sha256', $this->dir.'/release.manifest'))
        ->and($result['signature_sha256'])->toBe(hash_file('sha256', $this->dir.'/release.sig'))
        ->and($result['bootstrap']['sha256'])->toBe(hash_file('sha256', $this->dir.'/bootstrap.sh'))
        ->and($result['bootstrap']['url'])->toBe('https://kitsunecms.org/releases/v1.0.0-test.213/bootstrap.sh')
        ->and($result['artifact']['name'])->toBe('fixture.tar')
        ->and($result['packages'])->toHaveCount(1)
        ->and($result['download'])->toContain('--disable', '--max-redirs 0', '--max-filesize', '== 200', 'ln --')
        ->and($result['inspect'])->toContain('less --')
        ->and($result['run'])->toContain($this->pin, ' fetch ', '--artifact=fixture.tar')
        ->and($result['one_liner'])->not->toContain("\n", '| bash', '| sh', '--insecure', '--location')
        ->and($result['run'])->not->toContain('bash ./fixture.tar', 'tar -');
});

it('refuses commands for unauthenticated, corrupted or unsupported distribution inputs', function (string $change): void {
    $pin = $this->pin;
    $artifact = 'fixture.tar';
    match ($change) {
        'wrong pin' => $pin = str_repeat('0', 64),
        'corrupt payload' => File::put($this->dir.'/fixture.tar', 'changed'),
        'corrupt signature' => File::put($this->dir.'/release.sig', str_repeat('x', 384)),
        'missing selection' => $artifact = 'missing.tar',
        'bootstrap selection' => $artifact = 'bootstrap.sh',
        'wrong role' => distributionSignFixture($this->dir, $this->key, 'provenance'),
        'different signed bootstrap' => (function (): void {
            File::append($this->dir.'/bootstrap.sh', "\nprintf substituted\n");
            distributionSignFixture($this->dir, $this->key);
        })(),
    };
    $process = distributionCommand($this->dir, $pin, $artifact);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('');
})->with(['wrong pin', 'corrupt payload', 'corrupt signature', 'missing selection', 'bootstrap selection', 'wrong role', 'different signed bootstrap']);

/** Execute the generated commands against strict local adapters; no network or extra executable dependency. */
function distributionRunRecipe(string $directory, string $recipe, string $case = ''): Process
{
    File::ensureDirectoryExists($directory.'/tools', 0700);
    File::ensureDirectoryExists($directory.'/operator', 0700);
    File::copy($directory.'/release.pub', $directory.'/operator/release-public.pem');
    File::put($directory.'/tools/openssl', '#!'.PHP_BINARY."\n".bootstrapOpenSslAdapter());
    chmod($directory.'/tools/openssl', 0700);
    File::put($directory.'/tools/curl', '#!'.PHP_BINARY."\n".distributionCurlAdapter());
    chmod($directory.'/tools/curl', 0700);
    // Exercise the system Bash in nested generated commands too; PATH may name a newer development shell.
    $process = new Process(['/bin/bash', '-c', str_replace('bash -c ', '/bin/bash -c ', $recipe)], $directory.'/operator', [
        'PATH' => $directory.'/tools:'.getenv('PATH'),
        'DISTRIBUTION_SERVER' => $directory, 'DISTRIBUTION_CASE' => $case,
        'DISTRIBUTION_REQUESTS' => $directory.'/requests',
    ]);
    $process->run();

    return $process;
}

function distributionCurlAdapter(): string
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
    if ($a[0] !== '--disable' || in_array('--insecure', $a, true) || in_array('-L', $a, true)
        || in_array('--location', $a, true) || ! in_array('--tlsv1.2', $a, true)) { exit(96); }
    foreach (['--proto' => '=https', '--proto-redir' => '=https', '--max-redirs' => '0', '--write-out' => '%{http_code}'] as $name => $value) {
        $index = array_search($name, $a, true);
        if ($index === false || ($a[$index + 1] ?? null) !== $value) { exit(96); }
    }
    $url = $a[array_key_last($a)];
    $prefix = 'https://kitsunecms.org/releases/v1.0.0-test.213/';
    if (! str_starts_with($url, $prefix)) { exit(97); }
    $name = substr($url, strlen($prefix));
    if (! in_array($name, ['bootstrap.sh', 'release.manifest', 'release.manifest.sig', 'fixture.tar'], true)) { exit(98); }
    file_put_contents(getenv('DISTRIBUTION_REQUESTS'), $name."\n", FILE_APPEND);
    $path = $a[array_search('--output', $a, true) + 1];
    $case = getenv('DISTRIBUTION_CASE');
    if ($name === 'bootstrap.sh' && str_starts_with($case, 'redirect')) {
        file_put_contents($path, 'redirect'); echo substr($case, 8); exit(0);
    }
    $bytes = file_get_contents(getenv('DISTRIBUTION_SERVER').'/'.($name === 'release.manifest.sig' ? 'release.sig' : $name));
    if ($name === 'bootstrap.sh' && $case === 'corrupt') { $bytes[0] = '!'; }
    if ($name === 'bootstrap.sh' && $case === 'corrupt-tail') { $bytes[strlen($bytes) - 1] = "\v"; }
    if ($name === 'bootstrap.sh' && $case === 'oversize') { $bytes .= '!'; }
    file_put_contents($path, $bytes);
    echo '200';
    PHP;
}

it('executes the generated download, inspect, run sequence and companion command with the same exact pins', function (string $variant): void {
    $result = json_decode(distributionCommand($this->dir, $this->pin)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $recipe = $variant === 'primary' ? $result['download']."\n".$result['run'] : $result['one_liner'];
    $process = distributionRunRecipe($this->dir, $recipe);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(File::get($this->dir.'/operator/fixture.tar'))->toBe('fixture')
        ->and(File::get($this->dir.'/requests'))->toBe("bootstrap.sh\nrelease.manifest\nrelease.manifest.sig\nfixture.tar\n")
        ->and(glob($this->dir.'/operator/.kitsune-*'))->toBe([]);
    $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['verified'])->toBeTrue()->and($receipt['artifact_name'])->toBe('fixture.tar');
})->with(['primary', 'companion']);

it('refuses bootstrap substitution, size changes and successful redirect responses before executing downloaded bytes', function (string $case): void {
    $result = json_decode(distributionCommand($this->dir, $this->pin)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $process = distributionRunRecipe($this->dir, $result['one_liner'], $case);
    expect($process->isSuccessful())->toBeFalse()
        ->and(File::get($this->dir.'/requests'))->toBe("bootstrap.sh\n")
        ->and(File::exists($this->dir.'/operator/fixture.tar'))->toBeFalse()
        ->and(File::exists($this->dir.'/operator/'.$result['bootstrap']['local_name']))->toBeFalse()
        ->and(glob($this->dir.'/operator/.kitsune-*'))->toBe([]);
})->with(['corrupt', 'corrupt-tail', 'oversize', 'redirect301', 'redirect302', 'redirect303', 'redirect307', 'redirect308']);

it('rechecks the inspected local bootstrap before run and preserves an existing download destination', function (): void {
    $result = json_decode(distributionCommand($this->dir, $this->pin)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $process = distributionRunRecipe($this->dir, $result['download']);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $path = $this->dir.'/operator/'.$result['bootstrap']['local_name'];
    File::append($path, "\nprintf changed\n");
    $before = File::get($path);
    $refused = distributionRunRecipe($this->dir, $result['run']);
    $repeated = distributionRunRecipe($this->dir, $result['download']);
    expect($refused->isSuccessful())->toBeFalse()->and($repeated->isSuccessful())->toBeFalse()
        ->and(File::get($path))->toBe($before)
        ->and(File::get($this->dir.'/requests'))->toBe("bootstrap.sh\n");
});
