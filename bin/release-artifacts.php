<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Builder;
use Kitsune\Release\Distribution;
use Kitsune\Release\Files;
use Kitsune\Release\Manifest;
use Kitsune\Release\Signature;

require_once __DIR__.'/release/Manifest.php';
require_once __DIR__.'/release/Signature.php';
require_once __DIR__.'/release/Files.php';
require_once __DIR__.'/release/Builder.php';
require_once __DIR__.'/release/Distribution.php';

try {
    if (PHP_SAPI !== 'cli' || ! isset($argv)) {
        throw new InvalidArgumentException('Release tooling requires CLI arguments.');
    }
    $command = $argv[1] ?? null;
    if (! in_array($command, ['build', 'sign', 'verify', 'instructions'], true)) {
        throw new InvalidArgumentException('Use build, sign, verify or instructions with explicit inputs.');
    }
    $required = match ($command) {
        'build' => ['source', 'commit', 'version', 'channel', 'plan'],
        'sign' => ['manifest', 'key', 'fingerprint', 'version', 'channel'],
        'verify' => ['manifest', 'signature', 'key', 'fingerprint', 'version', 'channel', 'directory'],
        'instructions' => ['manifest', 'signature', 'key', 'fingerprint', 'version', 'channel', 'directory', 'bootstrap', 'artifact'],
    };
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new InvalidArgumentException('Explicit --name=value options are required.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (! in_array($name, $required, true) || array_key_exists($name, $options) || $value === '') {
            throw new InvalidArgumentException('Unknown, duplicate or empty option.');
        }
        $options[$name] = $value;
    }
    if (count($options) !== count($required)) {
        throw new InvalidArgumentException('All command options are required.');
    }
    if ($command === 'build') {
        $plan = json_decode(Files::control($options['plan'], Manifest::MAX_BYTES), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($plan) || count($plan) !== 2 || ! isset($plan['artifacts'], $plan['packages'])
            || ! is_array($plan['artifacts']) || ! array_is_list($plan['artifacts']) || ! is_array($plan['packages'])) {
            throw new InvalidArgumentException('Build plan requires explicit artifact and package inputs.');
        }
        $manifest = Builder::build($options['source'], $options['commit'], $options['version'], $options['channel'], $plan['artifacts'], $plan['packages']);
        fwrite(STDOUT, $manifest->bytes());
        exit(0);
    }
    if ($command === 'sign') {
        // Only encrypted PEM arrives on stdin. Passphrase bytes arrive on fd3, never argv or env.
        $passphraseInput = @fopen('php://fd/3', 'rb');
        if ($passphraseInput === false) {
            throw new InvalidArgumentException('Signing requires a passphrase input on file descriptor 3.');
        }
        $passphrase = stream_get_contents($passphraseInput, 4097);
        fclose($passphraseInput);
        $privatePem = stream_get_contents(STDIN, 65537);
        if ($passphrase === false || $privatePem === false || strlen($passphrase) > 4096 || strlen($privatePem) > 65536) {
            throw new InvalidArgumentException('Signing secret input size refused.');
        }
        // fd3 is an exact byte channel: do not silently trim a legitimate passphrase.
        $signature = Signature::sign(Files::control($options['manifest'], Manifest::MAX_BYTES), $privatePem, $passphrase,
            Files::control($options['key'], 16384), $options['fingerprint'], $options['version'], $options['channel']);
        unset($privatePem, $passphrase);
        fwrite(STDOUT, $signature);
        exit(0);
    }
    $signature = Files::control($options['signature'], 1024);
    $manifest = Signature::verify(
        Files::control($options['manifest'], Manifest::MAX_BYTES),
        $signature,
        Files::control($options['key'], 16384),
        $options['fingerprint'], $options['version'], $options['channel'],
    );
    Files::verify($manifest, $options['directory']);
    if ($command === 'instructions') {
        fwrite(STDOUT, json_encode(Distribution::instructions($manifest, $signature, $options['fingerprint'],
            $options['bootstrap'], $options['artifact']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
        exit(0);
    }
    fwrite(STDOUT, json_encode([
        'verified' => true,
        'version' => $manifest->version,
        'channel' => $manifest->channel,
        'source' => $manifest->source,
        'tree' => $manifest->tree,
        'artifact_count' => count($manifest->artifacts),
        'package_count' => count($manifest->packages),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Release artifact verification refused: '.$error->getMessage()."\n");
    exit(1);
}
