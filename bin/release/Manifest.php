<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

/**
 * An ASCII, tab-separated signed record, also readable by the pre-PHP bootstrap.
 * No escaping, comments, optional headers, URL values or executable expressions.
 * A signature authenticates bytes; this parser separately admits their meaning.
 */
final readonly class Manifest
{
    public const MAX_BYTES = 65536;

    public const MAX_ARTIFACT_BYTES = 10737418240;

    /**
     * @param  list<array{name:string, role:string, platform:string, size:int, sha256:string}>  $artifacts
     * @param  list<array{name:string, version:string, reference:string, sha256:string, publication:string}>  $packages
     */
    private function __construct(
        public string $version,
        public string $channel,
        public string $source,
        public string $tree,
        public array $artifacts,
        public array $packages,
        private string $raw,
    ) {}

    public function bytes(): string
    {
        return $this->raw;
    }

    public static function parse(string $raw): self
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES || ! str_ends_with($raw, "\n")
            || preg_match('/[^\x09\x0a\x20-\x7e]/', $raw)) {
            throw new InvalidArgumentException('Manifest must be bounded ASCII with a final LF.');
        }

        $lines = explode("\n", substr($raw, 0, -1));
        if (count($lines) < 8 || $lines[0] !== 'kitsune-release-manifest-v1') {
            throw new InvalidArgumentException('Unsupported release manifest format.');
        }

        $headers = [];
        foreach (['version', 'channel', 'source', 'tree', 'signature'] as $offset => $name) {
            $parts = explode("\t", $lines[$offset + 1]);
            if (count($parts) !== 2 || $parts[0] !== $name) {
                throw new InvalidArgumentException('Missing, duplicate or unordered manifest header.');
            }
            $headers[$name] = $parts[1];
        }

        if (! self::validVersion($headers['version']) || ! in_array($headers['channel'], ['test', 'stable'], true)
            || ! self::hex($headers['source'], 40) || ! self::hex($headers['tree'], 40)
            || $headers['signature'] !== 'rsa-pkcs1-sha256'
            || ($headers['channel'] === 'stable' && str_contains(explode('+', $headers['version'])[0], '-'))) {
            throw new InvalidArgumentException('Unsupported release identity or signature algorithm.');
        }

        $artifacts = $packages = [];
        $lastArtifact = $lastPackage = '';
        foreach (array_slice($lines, 6) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 6) {
                throw new InvalidArgumentException('Invalid manifest row.');
            }
            [$kind, $name, $first, $second, $third, $fourth] = $fields;
            if ($kind === 'artifact') {
                if ($packages !== [] || count($artifacts) >= 16 || strcmp($name, $lastArtifact) <= 0
                    || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', $name)
                    || str_contains($name, '..')
                    || ! in_array($first, ['bootstrap', 'native', 'docker', 'provenance'], true)
                    || ! in_array($second, ['any', 'linux-amd64', 'linux-arm64'], true)
                    || ! preg_match('/\A[1-9][0-9]{0,10}\z/D', $third)
                    || (int) $third > self::MAX_ARTIFACT_BYTES || ! self::hex($fourth, 64)) {
                    throw new InvalidArgumentException('Invalid, duplicate or unordered artifact.');
                }
                $artifacts[] = ['name' => $name, 'role' => $first, 'platform' => $second, 'size' => (int) $third, 'sha256' => $fourth];
                $lastArtifact = $name;
            } elseif ($kind === 'package') {
                $development = preg_match('/\Adev-[a-f0-9]{40}\z/D', $first) === 1;
                if ($artifacts === [] || count($packages) >= 32 || strcmp($name, $lastPackage) <= 0
                    || ! preg_match('/\Akitsune\/[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name)
                    || strlen($name) > 128
                    || (! $development && ! self::validVersion('v'.$first))
                    || ($development && $first !== 'dev-'.$second)
                    || ($headers['channel'] === 'stable' && ($development || str_contains(explode('+', $first)[0], '-')))
                    || ! self::hex($second, 40) || ! self::hex($third, 64)
                    || ! in_array($fourth, ['split', 'bundled', 'excluded'], true)) {
                    throw new InvalidArgumentException('Invalid, duplicate or unordered package.');
                }
                $packages[] = ['name' => $name, 'version' => $first, 'reference' => $second, 'sha256' => $third, 'publication' => $fourth];
                $lastPackage = $name;
            } else {
                throw new InvalidArgumentException('Unsupported manifest row type.');
            }
        }

        if ($packages === []) {
            throw new InvalidArgumentException('Manifest requires artifact and package provenance.');
        }

        return new self($headers['version'], $headers['channel'], $headers['source'], $headers['tree'], $artifacts, $packages, $raw);
    }

    public static function validVersion(string $version): bool
    {
        if (strlen($version) > 128 || preg_match('/\Av(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D', $version, $matches) !== 1) {
            return false;
        }
        foreach (isset($matches[4]) ? explode('.', $matches[4]) : [] as $part) {
            if (ctype_digit($part) && strlen($part) > 1 && $part[0] === '0') {
                return false;
            }
        }

        return true;
    }

    private static function hex(string $value, int $length): bool
    {
        return strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }
}
