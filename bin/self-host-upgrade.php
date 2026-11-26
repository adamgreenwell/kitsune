<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Files;
use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostInstallation;
use Kitsune\Release\SelfHostUpgradePlan;
use Kitsune\Release\Signature;

require_once __DIR__.'/release/Manifest.php';
require_once __DIR__.'/release/Files.php';
require_once __DIR__.'/release/Signature.php';
require_once __DIR__.'/release/SelfHostInstallation.php';
require_once __DIR__.'/release/SelfHostUpgradePlan.php';

try {
    if (PHP_SAPI !== 'cli' || ! isset($argv)) {
        throw new InvalidArgumentException('Self-host upgrade tooling requires CLI arguments.');
    }

    $command = $argv[1] ?? null;
    $required = match ($command) {
        'inspect' => ['mode', 'state'],
        'plan' => [
            'mode', 'state', 'platform', 'key', 'fingerprint',
            'origin-manifest', 'origin-signature', 'origin-version', 'origin-channel',
            'target-manifest', 'target-signature', 'target-version', 'target-channel', 'target-archive',
        ],
        default => throw new InvalidArgumentException('Use inspect or plan with explicit inputs.'),
    };

    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new InvalidArgumentException('Explicit --name=value options are required.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ((! in_array($name, $required, true) && $name !== 'installation') || isset($options[$name]) || $value === '') {
            throw new InvalidArgumentException('Unknown, duplicate or empty option.');
        }
        $options[$name] = $value;
    }

    if (array_diff($required, array_keys($options)) !== []) {
        throw new InvalidArgumentException('All command options are required.');
    }
    if (! in_array($options['mode'], ['native', 'docker'], true)
        || (($options['mode'] === 'native') !== isset($options['installation']))) {
        throw new InvalidArgumentException('Use native with a separate installation root, or docker with volume state only.');
    }

    $installation = SelfHostInstallation::inspect($options['state'], $options['installation'] ?? null);
    if ($command === 'inspect') {
        fwrite(STDOUT, json_encode($installation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        exit($installation['state'] === 'incomplete' ? 2 : 0);
    }

    $key = Files::control($options['key'], 16384);
    $readManifest = static fn (string $side): Manifest => Signature::verify(
        Files::control($options[$side.'-manifest'], Manifest::MAX_BYTES),
        Files::control($options[$side.'-signature'], 1024),
        $key,
        $options['fingerprint'],
        $options[$side.'-version'],
        $options[$side.'-channel'],
    );
    $result = SelfHostUpgradePlan::build($installation, $readManifest('origin'), $readManifest('target'),
        $options['target-archive'], $options['platform']);

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    exit($result['decision'] === 'already_at_target' ? 0 : 2);
} catch (InvalidArgumentException|JsonException $failure) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Self-host upgrade preflight refused: '.$failure->getMessage()."\n");
    }
    exit(1);
}
