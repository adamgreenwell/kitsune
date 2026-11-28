<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/** @return array<array-key, mixed> */
function readPluginReceiptJson(string $path, ?string $expectedHash = null): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException('Expected a readable JSON file.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('Expected readable package metadata.');
    }
    if ($expectedHash !== null && ! hash_equals($expectedHash, hash('sha256', $bytes))) {
        throw new RuntimeException('The package manifest changed while it was being read.');
    }
    $json = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($json)) {
        throw new RuntimeException('Expected JSON metadata.');
    }

    return $json;
}

/** @return array<string, string> */
function reviewedPluginFiles(string $root): array
{
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $files = [];
    $observedNames = [];
    foreach ($tree as $node) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($node->getPathname(), strlen($root) + 1));
        $portableName = preg_match('/[^\x20-\x7e]/', $relative) === 1
            ? (class_exists(Normalizer::class) ? Normalizer::normalize($relative) : false)
            : $relative;
        if ($portableName === false) {
            throw new RuntimeException('The package has a filename this host cannot normalize.');
        }
        $portableName = mb_strtolower($portableName);
        if ($node->isLink() || str_contains($relative, '\\') || isset($observedNames[$portableName])) {
            throw new RuntimeException('Package contents have an ambiguous path.');
        }
        $observedNames[$portableName] = true;
        if ($node->isDir()) {
            continue;
        }
        if (! $node->isFile() || ! is_readable($node->getPathname())) {
            throw new RuntimeException('Package contents are not ordinary readable files.');
        }
        $digest = hash_file('sha256', $node->getPathname());
        if ($digest === false) {
            throw new RuntimeException('Could not hash a package file.');
        }
        $files[$relative] = $digest;
    }
    ksort($files, SORT_STRING);

    return $files;
}

function canonicalPluginMetadata(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(canonicalPluginMetadata(...), $value);
}

/**
 * @param  array<array-key, mixed>  $package
 * @return array<string, mixed>
 */
function reviewedPluginComposerMetadata(array $package): array
{
    return array_map(canonicalPluginMetadata(...), [
        'name' => $package['name'] ?? null,
        'version' => $package['version'] ?? null,
        'type' => $package['type'] ?? 'library',
        'autoload' => $package['autoload'] ?? [],
        'include-path' => $package['include-path'] ?? [],
        'target-dir' => $package['target-dir'] ?? null,
        'require' => $package['require'] ?? [],
        'provide' => $package['provide'] ?? [],
        'replace' => $package['replace'] ?? [],
        'conflict' => $package['conflict'] ?? [],
        'extra' => $package['extra'] ?? [],
        'bin' => $package['bin'] ?? [],
    ]);
}

try {
    $arguments = $argv ?? [];
    $mode = $arguments[1] ?? null;
    if (($mode === 'record' && ! in_array(count($arguments), [3, 4], true)) || ($mode === 'verify' && count($arguments) !== 5)
        || ! in_array($mode, ['record', 'verify'], true)) {
        throw new RuntimeException('Use record PACKAGE [REVIEWED_VERSION] or verify PACKAGE REVIEWED_RECEIPT COMPOSER_INSTALLED_JSON.');
    }
    $given = rtrim($arguments[2], '/\\');
    $root = realpath($given);
    if ($given === '' || $root === false || ! is_dir($root) || is_link($given)) {
        throw new RuntimeException('Expected a physical package directory.');
    }

    $files = reviewedPluginFiles($root);
    if (! isset($files['composer.json']) || is_link($root.'/composer.json')) {
        throw new RuntimeException('Expected a physical Composer manifest in the package.');
    }
    $metadata = reviewedPluginComposerMetadata(readPluginReceiptJson($root.'/composer.json', $files['composer.json']));
    $receipt = $mode === 'verify' ? readPluginReceiptJson($arguments[3]) : null;
    $version = $mode === 'record' ? ($arguments[3] ?? null) : ($receipt['package']['version'] ?? null);
    if ($metadata['version'] !== null && $version !== null && $version !== $metadata['version']) {
        throw new RuntimeException('The reviewed version disagrees with the package manifest.');
    }
    $metadata['version'] ??= $version;
    if (! is_string($metadata['name']) || ! is_string($metadata['version']) || $metadata['version'] === '' || $metadata['type'] !== 'library') {
        throw new RuntimeException('Expected a library package and an explicit reviewed version.');
    }
    $actual = ['version' => 2, 'package' => $metadata, 'files' => $files];
    if ($mode === 'record') {
        echo json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    if ($actual !== $receipt) {
        fwrite(STDERR, "Installed package files or metadata differ from the reviewed receipt.\n");
        exit(1);
    }
    $entries = readPluginReceiptJson($arguments[4])['packages'] ?? [];
    if (! is_array($entries) || ! array_is_list($entries)) {
        throw new RuntimeException('Expected Composer installed package metadata.');
    }
    $candidates = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && ($entry['name'] ?? null) === $metadata['name']));
    $resolved = count($candidates) === 1 ? $candidates[0] : null;
    $location = is_array($resolved) ? ($resolved['install-path'] ?? null) : null;
    $installPath = is_string($location) ? rtrim(dirname($arguments[4]).'/'.$location, '/\\') : '';
    if (! is_array($resolved) || reviewedPluginComposerMetadata($resolved) !== $metadata
        || $installPath === '' || is_link($installPath) || realpath($installPath) !== $root) {
        fwrite(STDERR, "Composer's resolved package metadata or installation path differs from the review.\n");
        exit(1);
    }

    echo json_encode([
        'package' => $metadata['name'],
        'version' => $metadata['version'],
        'verified_files' => count($actual['files']),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(2);
}
