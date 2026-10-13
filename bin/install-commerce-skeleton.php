<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Opt-in development setup. The published skeleton's runtime requirements stay unchanged: commerce is a package
 * the operator chooses, and its module receipt is a second, separate choice. Composer scripts stop on failure,
 * so a final restore command in an event list would never run after the very failure that most needs it.
 */
$root = dirname(__DIR__);
$manifest = $root.'/skeleton/composer.json';
$backup = $manifest.'.orig';
$composer = getenv('COMPOSER_BINARY');

if (! is_string($composer) || $composer === '' || ! is_file($composer)) {
    fwrite(STDERR, "Run this through `composer skeleton:commerce`, so Composer's binary is known.\n");
    exit(1);
}

if (file_exists($backup)) {
    fwrite(STDERR, "skeleton/composer.json.orig already exists; recover that interrupted setup before running another.\n");
    exit(1);
}

$original = file_get_contents($manifest);

if ($original === false) {
    fwrite(STDERR, "The skeleton's Composer manifest could not be read.\n");
    exit(1);
}

$definition = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
$definition['require']['kitsune/commerce'] = '@dev';
$status = 1;

try {
    if (file_put_contents($manifest, json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('The skeleton\'s Composer manifest could not be written.');
    }

    $process = proc_open([PHP_BINARY, $composer, 'skeleton:install', '--no-interaction'], [STDIN, STDOUT, STDERR], $pipes, $root);

    if (! is_resource($process)) {
        throw new RuntimeException('Composer could not be started.');
    }

    $status = proc_close($process);
} finally {
    if (file_put_contents($manifest, $original) === false) {
        throw new RuntimeException('The skeleton\'s original Composer manifest could not be restored.');
    }

    // This backup did not exist when we began; an interrupted child may have left its temporary commerce manifest.
    if (file_exists($backup) && ! unlink($backup)) {
        throw new RuntimeException('The skeleton\'s temporary Composer manifest could not be removed.');
    }
}

exit($status < 0 ? 1 : $status);
