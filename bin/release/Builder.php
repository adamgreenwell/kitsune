<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

/** Bind explicit artifact inputs and every first-party manifest to an immutable Git source. */
final class Builder
{
    /**
     * Plans are untrusted decoded input; validate every nested value before using it.
     *
     * @param  array<mixed>  $artifacts
     * @param  array<string, mixed>  $packages
     */
    public static function build(string $root, string $commit, string $version, string $channel, array $artifacts, array $packages): Manifest
    {
        if (! preg_match('/\A[a-f0-9]{40}\z/D', $commit) || self::git($root, ['cat-file', '-t', $commit]) !== "commit\n") {
            throw new InvalidArgumentException('An exact source commit is required.');
        }
        if (! array_is_list($artifacts) || count($artifacts) > 16 || count($packages) > 32) {
            throw new InvalidArgumentException('Build input count or shape refused.');
        }
        $tree = trim(self::git($root, ['rev-parse', '--verify', $commit.'^{tree}']));
        $lines = ['kitsune-release-manifest-v1', "version\t".$version, "channel\t".$channel,
            "source\t".$commit, "tree\t".$tree, "signature\trsa-pkcs1-sha256"];
        $artifactRows = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact) || count($artifact) !== 3 || array_diff(array_keys($artifact), ['path', 'role', 'platform']) !== [] || ! is_string($artifact['path'])
                || ! is_string($artifact['role']) || ! is_string($artifact['platform'])) {
                throw new InvalidArgumentException('Explicit artifact path, role and platform are required.');
            }
            $name = basename($artifact['path']);
            if (isset($artifactRows[$name])) {
                throw new InvalidArgumentException('Artifact basenames must be distinct.');
            }
            $digest = Files::digest($artifact['path']);
            $artifactRows[$name] = implode("\t", ['artifact', $name, $artifact['role'], $artifact['platform'], $digest['size'], $digest['sha256']]);
        }
        ksort($artifactRows, SORT_STRING);
        $lines = [...$lines, ...array_values($artifactRows)];

        $inventory = [];
        foreach (explode("\0", self::git($root, ['ls-tree', '-rz', '--full-tree', $commit])) as $row) {
            if ($row === '') {
                continue;
            }
            [$identity, $path] = explode("\t", $row, 2);
            if (! preg_match('#\A(?:packages/[^/]+|skeleton)/composer\.json\z#D', $path)) {
                continue;
            }
            if (! str_starts_with($identity, '100644 blob ') && ! str_starts_with($identity, '100755 blob ')) {
                throw new InvalidArgumentException('Package manifest must be a committed regular file.');
            }
            $bytes = self::git($root, ['cat-file', 'blob', $commit.':'.$path]);
            $metadata = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $name = is_array($metadata) ? ($metadata['name'] ?? null) : null;
            if (! is_string($name) || isset($inventory[$name])) {
                throw new InvalidArgumentException('Package names must be present and distinct.');
            }
            $inventory[$name] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        }
        if (! isset($inventory['kitsune/core'], $inventory['kitsune/kitsune'])) {
            throw new InvalidArgumentException('Source requires core and skeleton package manifests.');
        }
        ksort($inventory, SORT_STRING);
        ksort($packages, SORT_STRING);
        if (array_keys($inventory) !== array_keys($packages)) {
            throw new InvalidArgumentException('Explicit versions and publication intent are required for every first-party package, including the skeleton.');
        }

        $workflow = self::git($root, ['cat-file', 'blob', $commit.':.github/workflows/split-packages.yml']);
        preg_match_all('/^\s+- package: ([a-z0-9-]+)\s*$/m', $workflow, $matches);
        foreach ($inventory as $name => $input) {
            $choice = $packages[$name];
            if (! is_array($choice) || count($choice) !== 2 || array_diff(array_keys($choice), ['version', 'publication']) !== [] || ! is_string($choice['version']) || ! is_string($choice['publication'])) {
                throw new InvalidArgumentException('Explicit package version and publication intent are required.');
            }
            $splitDirectory = str_starts_with($input['path'], 'packages/') ? explode('/', $input['path'])[1] : null;
            if ($choice['publication'] === 'split' && ! in_array($splitDirectory, $matches[1], true)) {
                throw new InvalidArgumentException('Package has no configured split publisher; no tag publication may be inferred.');
            }
            $lines[] = implode("\t", ['package', $name, $choice['version'], $commit, $input['sha256'], $choice['publication']]);
        }

        return Manifest::parse(implode("\n", $lines)."\n");
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        // Local replacement refs must not substitute another tree/blob under the requested source hash.
        $process = proc_open(['git', '--no-replace-objects', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (! is_resource($process)) {
            throw new InvalidArgumentException('Source Git inspection failed.');
        }
        fclose($pipes[0]);
        $bytes = stream_get_contents($pipes[1], 1048577);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $bytes === false || strlen($bytes) > 1048576) {
            throw new InvalidArgumentException('Source Git object is unavailable or exceeds the inspection bound.');
        }

        return $bytes;
    }
}
