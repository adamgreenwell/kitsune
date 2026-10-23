<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use RuntimeException;

/**
 * Disposable measurement code for #262, never a production revision reader or extension contract.
 *
 * Diffs replace whole strings and ordered lists. Only object keys are diffed: this deliberately does not assume
 * a text-diff algorithm makes rich text cheap. Fifty retained versions and independent checkpoints bound replay.
 * Checksums detect accidental corruption; they do not authenticate a maliciously rewritten database.
 *
 * @internal
 */
final class RevisionDiffPrototype
{
    private array $records = [];

    public function __construct(private readonly string $entryKey, private readonly int $interval)
    {
        if ($entryKey === '' || $interval < 1 || $interval > 50) {
            throw new RuntimeException('An entry identity and checkpoint interval from 1 to 50 are required.');
        }
    }

    public static function fromRows(string $entryKey, int $interval, array $rows): self
    {
        if (count($rows) > 50) {
            throw new RuntimeException('More than fifty retained rows.');
        }

        $history = new self($entryKey, $interval);
        $previous = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['sequence'] ?? null) || $row['sequence'] <= $previous) {
                throw new RuntimeException('Invalid revision order.');
            }

            $previous = $row['sequence'];
        }

        $history->records = array_values($rows);

        return $history;
    }

    public function rows(): array
    {
        return $this->records;
    }

    public function append(array $state): void
    {
        $last = $this->records === [] ? null : $this->records[array_key_last($this->records)]['sequence'];
        $previous = $last === null ? null : $this->read($last);
        $next = $this->records;
        $next[] = $this->record(($last ?? 0) + 1, $state, $previous);

        if (count($next) > 50) {
            // Read BEFORE removing its predecessor. Rebase the new first retained row in the same persisted commit.
            $firstState = $this->read($next[1]['sequence']);
            array_shift($next);
            $next[0] = $this->record($next[0]['sequence'], $firstState, null);
        }

        // A failed decode or encoding above leaves the old history intact.
        $this->records = $next;
    }

    public function read(int $sequence): array
    {
        $target = null;
        $checkpoint = null;

        foreach ($this->records as $index => $row) {
            if ($row['sequence'] <= $sequence && ($row['kind'] ?? null) === 'checkpoint') {
                $checkpoint = $index;
            }

            if ($row['sequence'] === $sequence) {
                $target = $index;
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException('Revision is not retained.');
        }

        if ($checkpoint === null) {
            throw new RuntimeException('Missing independent checkpoint.');
        }

        $state = [];
        $previousSequence = null;

        for ($index = $checkpoint; $index <= $target; $index++) {
            $row = $this->records[$index];
            $this->validate($row);

            if ($previousSequence !== null && $row['sequence'] !== $previousSequence + 1) {
                throw new RuntimeException('Missing revision sequence.');
            }

            if ($index === $checkpoint) {
                $state = $row['payload'];
            } else {
                if ($row['before_hash'] !== self::digest($state)) {
                    throw new RuntimeException('Revision predecessor checksum mismatch.');
                }

                if ($row['kind'] === 'checkpoint') {
                    $state = $row['payload'];
                } else {
                    foreach ($row['payload'] as $operation) {
                        self::apply($state, $operation);
                    }
                }
            }

            if (! is_array($state) || $row['after_hash'] !== self::digest($state)) {
                throw new RuntimeException('Reconstructed state checksum mismatch.');
            }

            $previousSequence = $row['sequence'];
        }

        return $state;
    }

    /** Rewrite every retained state, including originals and ordered relation references. No erased payload log. */
    public function redact(array $inlineKeys, array $columns = [], array $relationKeys = []): void
    {
        if (array_diff(array_keys($columns), ['title', 'slug', 'status', 'published_at']) !== []) {
            throw new RuntimeException('Erasure cannot rewrite a schema discriminator or unknown column.');
        }

        $rewritten = [];
        $previous = null;

        foreach ($this->records as $row) {
            $state = $this->read($row['sequence']);

            foreach ($inlineKeys as $key) {
                if (array_key_exists($key, $state['values'] ?? [])) {
                    $state['values'][$key] = null;
                }

                unset($state['unsanitized_values'][$key]);
            }

            if (($state['unsanitized_values'] ?? []) === []) {
                $state['unsanitized_values'] = null;
            }

            foreach ($columns as $column => $replacement) {
                $state[$column] = $replacement;
            }

            foreach ($relationKeys as $key) {
                if (array_key_exists($key, $state['relation_state'] ?? [])) {
                    // Match current erasure: unknown, so restoring this history preserves later replacement links.
                    $state['relation_state'][$key] = null;
                }
            }

            $rewritten[] = $this->record($row['sequence'], $state, $previous);
            $previous = $state;
        }

        $this->records = $rewritten;
    }

    /** Deterministic object ordering for checksums; list order is content and is never sorted. */
    public static function json(mixed $value): string
    {
        $normalize = static function (mixed $one) use (&$normalize): mixed {
            if (! is_array($one)) {
                return $one;
            }

            if (! array_is_list($one)) {
                ksort($one, SORT_STRING);
            }

            return array_map($normalize, $one);
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function digest(array $state): string
    {
        return hash('sha256', self::json($state));
    }

    private function record(int $sequence, array $state, ?array $previous): array
    {
        $checkpoint = $previous === null || ($sequence - 1) % $this->interval === 0;
        $row = [
            'format' => 1,
            'entry_key' => $this->entryKey,
            'sequence' => $sequence,
            'kind' => $checkpoint ? 'checkpoint' : 'diff',
            'before_hash' => $previous === null ? null : self::digest($previous),
            'after_hash' => self::digest($state),
            'payload' => $checkpoint ? $state : self::diff($previous, $state),
        ];
        $row['checksum'] = self::digest($row);

        return $row;
    }

    private function validate(array $row): void
    {
        if (($row['entry_key'] ?? null) !== $this->entryKey) {
            throw new RuntimeException('Foreign revision identity.');
        }

        $checksum = $row['checksum'] ?? null;
        unset($row['checksum']);

        if (! is_string($checksum) || ! hash_equals($checksum, self::digest($row))) {
            throw new RuntimeException('Revision checksum mismatch.');
        }

        if (($row['format'] ?? null) !== 1 || ! in_array($row['kind'] ?? null, ['checkpoint', 'diff'], true)
            || ! is_array($row['payload'] ?? null)) {
            throw new RuntimeException('Unknown revision envelope.');
        }
    }

    private static function diff(mixed $before, mixed $after, array $path = []): array
    {
        if ($before === $after) {
            return [];
        }

        if (! is_array($before) || ! is_array($after) || array_is_list($before) || array_is_list($after)) {
            return [['op' => 'set', 'path' => $path, 'value' => $after]];
        }

        $operations = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $next = [...$path, $key];

            if (! array_key_exists($key, $after)) {
                $operations[] = ['op' => 'remove', 'path' => $next];
            } elseif (! array_key_exists($key, $before)) {
                $operations[] = ['op' => 'set', 'path' => $next, 'value' => $after[$key]];
            } else {
                array_push($operations, ...self::diff($before[$key], $after[$key], $next));
            }
        }

        return $operations;
    }

    private static function apply(array &$state, array $operation): void
    {
        $path = $operation['path'] ?? null;
        $op = $operation['op'] ?? null;

        if (! is_array($path) || $path === [] || ! in_array($op, ['set', 'remove'], true)) {
            throw new RuntimeException('Invalid diff operation.');
        }

        $key = array_pop($path);
        $target = &$state;

        foreach ($path as $part) {
            if (! array_key_exists($part, $target) || ! is_array($target[$part])) {
                throw new RuntimeException('Invalid diff path.');
            }

            $target = &$target[$part];
        }

        if ($op === 'remove') {
            if (! array_key_exists($key, $target)) {
                throw new RuntimeException('Removing a missing key.');
            }

            unset($target[$key]);
        } else {
            $target[$key] = $operation['value'];
        }
    }
}
