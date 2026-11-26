<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

const PARITY_FORMAT = 'kitsune-parity-v1';

function parityRead(string $path): string
{
    $source = @file_get_contents($path);
    if ($source === false) {
        throw new InvalidArgumentException("Unable to read {$path}.");
    }

    return $source;
}

/** @return array<string, string> */
function paritySources(string $suite): array
{
    $root = dirname(__DIR__, 2);
    $map = json_decode(parityRead(__DIR__.'/source-map.json'), true, 512, JSON_THROW_ON_ERROR);
    $sources = [];

    foreach ([...$map['common'], ...$map[$suite]] as $path) {
        $sources[$path] = hash('sha256', parityRead($root.'/'.$path));
    }

    return $sources;
}

/** @return array<string, mixed> */
function parityPcreEngine(): array
{
    return [
        'name' => 'pcre2',
        'php' => PHP_VERSION,
        'version' => PCRE_VERSION,
        'jit_supported' => PCRE_JIT_SUPPORT,
        'jit' => (int) ini_get('pcre.jit'),
        'backtrack_limit' => (int) ini_get('pcre.backtrack_limit'),
        'recursion_limit' => (int) ini_get('pcre.recursion_limit'),
    ];
}

/** @param array<string, mixed> $engine @param list<array<string, mixed>> $rows */
function parityDump(string $suite, string $corpus, array $engine, array $rows): array
{
    return [
        'format' => PARITY_FORMAT,
        'suite' => $suite,
        'corpus_sha256' => hash('sha256', $corpus),
        'sources' => paritySources($suite),
        'engine' => $engine,
        'rows' => $rows,
    ];
}

/** @param list<string> $keys */
function parityHasKeys(object $object, array $keys): bool
{
    $actual = array_keys(get_object_vars($object));
    sort($actual);
    sort($keys);

    return $actual === $keys;
}

/** @return array<string, array<string, mixed>> */
function parityResults(string $path, string $suite, string $corpus, string $engineName, array $ids): array
{
    $dump = json_decode(parityRead($path), false, 512, JSON_THROW_ON_ERROR);
    if (! is_object($dump)
        || ! parityHasKeys($dump, ['format', 'suite', 'corpus_sha256', 'sources', 'engine', 'rows'])
        || $dump->format !== PARITY_FORMAT || $dump->suite !== $suite || ! is_array($dump->rows)) {
        throw new InvalidArgumentException("{$engineName} result does not use the current {$suite} measurement format.");
    }

    if ($dump->corpus_sha256 !== hash('sha256', $corpus)) {
        throw new InvalidArgumentException("{$engineName} corpus digest is stale or missing.");
    }

    $expectedSources = paritySources($suite);
    $sources = is_object($dump->sources) ? get_object_vars($dump->sources) : [];
    ksort($sources);
    ksort($expectedSources);
    if ($sources !== $expectedSources) {
        throw new InvalidArgumentException("{$engineName} measurement or rule sources do not match this checkout.");
    }

    $engine = $dump->engine;
    if (! is_object($engine) || ! parityValidEngine($engine, $engineName, $suite)) {
        throw new InvalidArgumentException("Invalid or incompatible {$engineName} runtime metadata.");
    }

    $expected = array_fill_keys($ids, true);
    $results = [];
    foreach ($dump->rows as $row) {
        $id = is_object($row) ? ($row->id ?? null) : null;
        if (! is_string($id) || ! isset($expected[$id]) || isset($results[$id])) {
            throw new InvalidArgumentException("{$engineName} results contain an invalid, unknown or duplicate measurement id.");
        }
        $results[$id] = get_object_vars($row);
    }

    if (count($results) !== count($expected)) {
        throw new InvalidArgumentException("{$engineName} results do not cover the complete {$suite} corpus.");
    }

    return $results;
}

function parityValidEngine(object $engine, string $name, string $suite): bool
{
    $version = static fn (mixed $value): bool => is_string($value) && preg_match('/\A\d+\.\d+\S*\z/D', $value) === 1;
    if (($engine->name ?? null) !== $name) {
        return false;
    }

    if ($name === 'pcre2') {
        if (! parityHasKeys($engine, ['name', 'php', 'version', 'jit_supported', 'jit', 'backtrack_limit', 'recursion_limit'])
            || ! $version($engine->php) || ! is_string($engine->version)
            || preg_match('/\A\d+\.\d+(?:-[A-Za-z0-9.]+)?(?: [0-9]{4}-[0-9]{2}-[0-9]{2})?\z/D', $engine->version) !== 1
            || ! is_bool($engine->jit_supported)
            || ! in_array($engine->jit, [0, 1], true)
            || ! is_int($engine->backtrack_limit) || $engine->backtrack_limit < 0
            || ! is_int($engine->recursion_limit) || $engine->recursion_limit < 0) {
            return false;
        }

        // Pattern comparison asks today's PHP runtime to compile the cases again.
        return $suite !== 'pattern' || get_object_vars($engine) == parityPcreEngine();
    }

    $keys = ['name', 'node', 'version', 'unicode'];
    if ($suite === 'pattern') {
        $keys[] = 'deadline_ms';
    }

    return parityHasKeys($engine, $keys) && $version($engine->node) && $version($engine->version)
        && $version($engine->unicode)
        && ($suite !== 'pattern' || (is_int($engine->deadline_ms) && $engine->deadline_ms > 0
            && $engine->deadline_ms <= 2147483647));
}
