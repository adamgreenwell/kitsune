<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\ExtensionContract;
use Symfony\Component\Process\Process;

require __DIR__.'/release/ExtensionContract.php';

$arguments = $argv ?? [];

try {
    if (count($arguments) === 4 && $arguments[1] === '--inspect-runtime') {
        require $arguments[3];
        ExtensionContract::read($arguments[2])->assertRuntimeExports();
        exit(0);
    }

    if (count($arguments) !== 6) {
        fwrite(STDERR, 'Usage: php bin/check-extension-compatibility.php PUBLISHED_JSON CANDIDATE_JSON CANDIDATE_AUTOLOAD PUBLISHED_PROBES CANDIDATE_PROBES'.PHP_EOL);
        exit(2);
    }

    require dirname(__DIR__).'/vendor/autoload.php';

    [, $publishedPath, $candidatePath, $autoloadPath, $publishedProbes, $candidateProbes] = $arguments;
    $published = ExtensionContract::read($publishedPath);
    $candidate = ExtensionContract::read($candidatePath);
    $candidate->retain($published);
    $autoload = realpath($autoloadPath);
    if ($autoload === false || ! is_file($autoload)) {
        throw new InvalidArgumentException('Expected the trusted candidate autoloader.');
    }

    $probeFiles = [];
    foreach ($candidate->exports as $key => $export) {
        $retained = $candidate->sameMajorAs($published) && isset($published->exports[$key]);
        $directory = realpath($retained ? $publishedProbes : $candidateProbes);
        $file = $directory.DIRECTORY_SEPARATOR.$export['probe'].'.php';
        if ($directory === false || realpath($file) !== $file || ! is_file($file)
            || hash_file('sha256', $file) !== $export['sha256']) {
            throw new InvalidArgumentException('Missing or changed consumer probe '.$export['probe'].'.');
        }

        $probeFiles[$file] = $export['probe'];
    }

    runExtensionCheck([PHP_BINARY, __FILE__, '--inspect-runtime', $candidatePath, $autoload], 'candidate exports', '');
    foreach ($probeFiles as $file => $name) {
        runExtensionCheck([PHP_BINARY, $file, $autoload], $name, "compatible\n");
    }

    echo json_encode([
        'published' => $published->release, 'candidate' => $candidate->release,
        'exports' => count($candidate->exports), 'probes' => count($probeFiles),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Extension compatibility refused: '.$error->getMessage().PHP_EOL);
    exit(1);
}

/** @param list<string> $command */
function runExtensionCheck(array $command, string $label, string $expected): void
{
    $process = new Process($command, timeout: 30);
    $process->run();
    if (! $process->isSuccessful() || $process->getOutput() !== $expected || $process->getErrorOutput() !== '') {
        throw new RuntimeException('Failed '.$label.'. '.trim($process->getErrorOutput()));
    }
}
