<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

function nativeInstallPlan(string $release, string $architecture): Process
{
    $file = tmpfile();
    if ($file === false) {
        throw new RuntimeException('Could not create an OS-release fixture.');
    }

    try {
        fwrite($file, $release);
        fflush($file);
        $process = new Process([
            'bash', '-c', 'source "$1"; kitsune_native_platform_plan "$2" "$3"', 'native-preflight',
            dirname(__DIR__, 3).'/bin/native-install-preflight.sh', stream_get_meta_data($file)['uri'], $architecture,
        ]);
        $process->run();

        return $process;
    } finally {
        fclose($file);
    }
}

it('selects a native package plan for supported Ubuntu LTS suites and architectures', function (string $version, string $suite, string $php, string $source, string $repository, string $architecture): void {
    $result = nativeInstallPlan("NAME=\"Ubuntu\"\r\nID='ubuntu'\r\nVERSION_ID=\"{$version}\"\r\nVERSION_CODENAME={$suite}", $architecture);
    $packages = ['nginx', ...array_map(static fn (string $extension): string => "php{$php}-{$extension}", [
        'cli', 'fpm', 'curl', 'intl', 'mbstring', 'sqlite3', 'xml', 'zip',
    ])];

    expect($result->getExitCode())->toBe(0)
        ->and($result->getErrorOutput())->toBe('')
        ->and($result->getOutput())->toBe("suite={$suite}\narchitecture={$architecture}\nphp={$php}\nphp_source={$source}\n"
            .($repository !== '' ? "php_repository={$repository}\n" : '')
            .'packages='.implode(' ', $packages)."\n");
})->with([
    ['22.04', 'jammy', '8.4', 'sury', 'https://packages.sury.org/php/'],
    ['24.04', 'noble', '8.4', 'sury', 'https://packages.sury.org/php/'],
    ['26.04', 'resolute', '8.5', 'ubuntu', ''],
])->with(['amd64', 'arm64']);

it('refuses unsupported or inconsistent OS metadata and architectures', function (string $release, string $architecture, string $message): void {
    $result = nativeInstallPlan($release, $architecture);

    expect($result->getExitCode())->toBe(1)
        ->and($result->getOutput())->toBe('')
        ->and($result->getErrorOutput())->toContain($message);
})->with([
    ["ID=debian\nVERSION_ID=26.04\nVERSION_CODENAME=resolute\n", 'amd64', 'requires Ubuntu'],
    ["ID=ubuntu\nVERSION_ID=24.04\nVERSION_CODENAME=jammy\n", 'amd64', 'requires Ubuntu'],
    ["ID=ubuntu\nVERSION_ID=26.04\n", 'amd64', 'requires Ubuntu'],
    ["ID=ubuntu\nVERSION_ID=26.04\nVERSION_CODENAME=resolute\n", 'riscv64', 'requires amd64 or arm64'],
]);

it('refuses unexpected arguments before reading the host platform', function (): void {
    $process = new Process(['bash', dirname(__DIR__, 3).'/bin/native-install-preflight.sh', '--install']);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:');
});
