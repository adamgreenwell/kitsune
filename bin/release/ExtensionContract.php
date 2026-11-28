<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use stdClass;

/**
 * @phpstan-type Export array{name:string, kind:string, probe:string, sha256:string, status:string, since:?string, replacement:?string, migration_guide:?string}
 */
final readonly class ExtensionContract
{
    /** @param array<string, Export> $exports */
    private function __construct(public string $release, public array $exports) {}

    public static function read(string $path): self
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new InvalidArgumentException('Could not read the extension inventory.');
        }
        $document = json_decode($bytes, flags: JSON_THROW_ON_ERROR);
        if (! $document instanceof stdClass || ! self::isRelease($document->release ?? null)
            || ! is_array($document->exports ?? null) || $document->exports === []) {
            throw new InvalidArgumentException('Expected a non-empty stable extension inventory from v1.2.0 onward.');
        }

        $exports = [];
        foreach ($document->exports as $row) {
            if (! $row instanceof stdClass) {
                throw new InvalidArgumentException('An export must be an object.');
            }

            $entry = self::readExport($row, $document->release);
            $key = self::key($entry['name'], $entry['kind']);
            if (isset($exports[$key])) {
                throw new InvalidArgumentException('Duplicate extension export '.$entry['name'].'.');
            }

            $exports[$key] = $entry;
        }

        ksort($exports);

        return new self($document->release, $exports);
    }

    /** @return Export */
    private static function readExport(stdClass $row, string $release): array
    {
        $name = $row->name ?? null;
        $kind = $row->kind ?? null;
        $probe = $row->probe ?? null;
        $sha = $row->probe_sha256 ?? null;
        $status = $row->status ?? null;
        $since = $row->since ?? null;
        $replacement = $row->replacement ?? null;
        $guide = $row->migration_guide ?? null;
        if (! is_string($name) || ! is_string($kind) || ! self::validNameForKind($name, $kind)
            || ! is_string($probe) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $probe) !== 1
            || ! is_string($sha) || preg_match('/\A[0-9a-f]{64}\z/', $sha) !== 1
            || ! in_array($status, ['stable', 'deprecated'], true)) {
            throw new InvalidArgumentException('An extension export has an invalid name, kind, status, or probe identity.');
        }

        if ($status === 'deprecated') {
            if (! self::isRelease($since) || version_compare($since, $release, '>')
                || ! is_string($replacement) || trim($replacement) === ''
                || ! is_string($guide) || trim($guide) === '') {
                throw new InvalidArgumentException('A deprecation needs its first release, replacement, and migration guide.');
            }
        } elseif ($since !== null || $replacement !== null || $guide !== null) {
            throw new InvalidArgumentException('Deprecation fields belong to a deprecated extension export.');
        }

        return [
            'name' => $name, 'kind' => $kind, 'probe' => $probe, 'sha256' => $sha,
            'status' => $status, 'since' => $since, 'replacement' => $replacement, 'migration_guide' => $guide,
        ];
    }

    /**
     * @phpstan-assert-if-true string $release
     */
    private static function isRelease(mixed $release): bool
    {
        return is_string($release) && preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $release) === 1
            && version_compare($release, '1.2.0', '>=');
    }

    private static function validNameForKind(string $name, string $kind): bool
    {
        if ($kind === 'behavior') {
            return preg_match('/\A[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+\z/', $name) === 1;
        }

        return in_array($kind, ['class', 'interface', 'trait', 'enum', 'method', 'protected_method', 'enum_case'], true)
            && preg_match('~\AKitsune\\\\(?:[a-z_][a-z0-9_]*\\\\)*[a-z_][a-z0-9_]*(?:::[a-z_][a-z0-9_]*)?\z~i', $name) === 1
            && str_contains($name, '::') === in_array($kind, ['method', 'protected_method', 'enum_case'], true);
    }

    private static function key(string $name, string $kind): string
    {
        if ($kind === 'enum_case') {
            [$owner, $case] = explode('::', $name, 2);

            return 'case:'.strtolower($owner).'::'.$case;
        }

        return strtolower($name);
    }

    public function sameMajorAs(self $baseline): bool
    {
        return explode('.', $this->release, 2)[0] === explode('.', $baseline->release, 2)[0];
    }

    public function retain(self $baseline): void
    {
        if (version_compare($this->release, $baseline->release, '<')) {
            throw new InvalidArgumentException('The extension inventory cannot move to an earlier release.');
        }

        if ($this->release === $baseline->release && $this->exports !== $baseline->exports) {
            throw new InvalidArgumentException('A published release cannot identify a changed extension inventory.');
        }

        if (! $this->sameMajorAs($baseline)) {
            return;
        }

        foreach ($baseline->exports as $key => $published) {
            $candidate = $this->exports[$key] ?? null;
            $name = $published['name'];
            if ($candidate === null || $candidate['kind'] !== $published['kind']) {
                throw new InvalidArgumentException('Same-major removal or kind change of '.$name.'.');
            }
            if ($candidate['probe'] !== $published['probe'] || $candidate['sha256'] !== $published['sha256']) {
                throw new InvalidArgumentException('Same-major replacement of the retained probe for '.$name.'.');
            }
            if ($published['status'] === 'deprecated'
                && ($candidate['status'] !== 'deprecated' || $candidate['since'] !== $published['since'])) {
                throw new InvalidArgumentException('Same-major loss of the deprecation history for '.$name.'.');
            }
            if ($published['status'] === 'stable' && $candidate['status'] === 'deprecated' && $candidate['since'] !== $this->release) {
                throw new InvalidArgumentException('Newly deprecated export '.$name.' must name this release.');
            }
        }
    }

    public function assertRuntimeExports(): void
    {
        foreach ($this->exports as $export) {
            if ($export['kind'] !== 'behavior' && ! self::isExported($export['name'], $export['kind'])) {
                throw new InvalidArgumentException('Candidate runtime does not expose '.$export['name'].'.');
            }
        }
    }

    private static function isExported(string $name, string $kind): bool
    {
        [$owner, $member] = explode('::', $name, 2) + [1 => ''];
        try {
            $type = new ReflectionClass($owner);
            if (self::isInternal($type)) {
                return false;
            }

            return match ($kind) {
                'class' => ! $type->isInterface() && ! $type->isTrait() && ! $type->isEnum(),
                'interface' => $type->isInterface(),
                'trait' => $type->isTrait(),
                'enum' => $type->isEnum(),
                'enum_case' => $type->isEnum() && (new ReflectionEnum($owner))->hasCase($member),
                'method', 'protected_method' => $type->hasMethod($member)
                    && self::visibleMethod($type->getMethod($member), $kind),
                default => false,
            };
        } catch (ReflectionException) {
            return false;
        }
    }

    private static function visibleMethod(ReflectionMethod $method, string $kind): bool
    {
        return ($kind === 'method' ? $method->isPublic() : $method->isProtected()) && ! self::isInternal($method) && ! self::isInternal($method->getDeclaringClass());
    }

    /** @param ReflectionClass<object>|ReflectionMethod $symbol */
    private static function isInternal(ReflectionClass|ReflectionMethod $symbol): bool
    {
        return preg_match('~(?:^/\*\*|^\h*\*)\h*@internal(?=\s|\*/|$)~m', (string) $symbol->getDocComment()) === 1;
    }
}
