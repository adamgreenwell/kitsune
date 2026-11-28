<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Read CSV and JSON records into the shared migration preview.
 *
 * @internal
 */
final readonly class StructuredDraftInput
{
    private const COLUMNS = ['source_type', 'source_id', 'entry_type', 'title', 'slug'];

    public function __construct(private ImportJournal $journal) {}

    /**
     * @param  array<string, int>  $destinationTypes
     * @param  list<ImportMediaAsset>  $media
     */
    public function preview(
        string $format,
        string $sourceKey,
        string $bytes,
        array $destinationTypes,
        bool $complete,
        array $media = [],
        ?string $mediaRoot = null,
    ): ImportPlan {
        if (! in_array($format, ['csv', 'json'], true)) {
            throw new InvalidArgumentException('The structured import format is unavailable.');
        }

        $findings = [];
        $input = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        if (! mb_check_encoding($input, 'UTF-8')) {
            $findings[] = ['position' => 0, 'code' => 'structured-invalid-utf8'];
            $rows = [];
        } else {
            $rows = $format === 'csv' ? $this->csv($input) : $this->json($input);
            if ($rows === null) {
                $findings[] = [
                    'position' => 0,
                    'code' => $format === 'csv' ? 'structured-invalid-csv-header' : 'structured-invalid-json-document',
                ];
                $rows = [];
            }
        }

        $drafts = [];
        $rowBlockers = [];
        $objects = $format === 'csv' ? ['values_json' => 'values', 'links_json' => 'links'] : ['values' => 'values', 'links' => 'links'];
        foreach ($rows as $index => $row) {
            $position = $index + 1;
            if ($row === null) {
                $rowBlockers[$position] = [['code' => 'structured-invalid-'.$format.'-row']];
                $drafts[] = [];

                continue;
            }

            $rowFindings = self::rowFindings($row, $objects, $destinationTypes);
            $provided = [];
            foreach ($objects as $column => $name) {
                if (! array_key_exists($column, $row)) {
                    continue;
                }
                $object = $row[$column];
                if (is_string($object) && $format === 'csv') {
                    try {
                        $object = $object === '' ? new stdClass : json_decode($object, depth: 64, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $object = null;
                    }
                }
                if (! $object instanceof stdClass) {
                    $rowFindings[] = ['code' => 'structured-invalid-object', 'field' => $column];

                    continue;
                }
                if (get_object_vars($object) !== []) {
                    $provided[$name] = $object;
                }
            }
            if ($rowFindings !== []) {
                $rowBlockers[$position] = $rowFindings;
                $drafts[] = [];

                continue;
            }

            $drafts[] = [
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'entry_type_id' => $destinationTypes[$row['entry_type']] ?? null,
                'title' => $row['title'],
                'slug' => $row['slug'],
                ...$provided,
            ];
        }

        return $this->journal->preview($sourceKey, $format.':v1', hash('sha256', $bytes), $drafts, $complete, $media, $mediaRoot,
            ['blockers' => $findings, 'notices' => [], 'row_blockers' => $rowBlockers]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $objects
     * @param  array<string, int>  $destinationTypes
     * @return list<array{code: string, field?: string}>
     */
    private static function rowFindings(array $row, array $objects, array $destinationTypes): array
    {
        $findings = [];
        foreach (self::COLUMNS as $field) {
            if (! array_key_exists($field, $row)) {
                $findings[] = ['code' => 'structured-missing-field', 'field' => $field];
            } elseif (! is_string($row[$field])) {
                $findings[] = ['code' => 'structured-invalid-field-type', 'field' => $field];
            }
        }
        if (array_diff(array_keys($row), [...self::COLUMNS, ...array_keys($objects)]) !== []) {
            $findings[] = ['code' => 'structured-unsupported-field'];
        }
        if (is_string($row['entry_type'] ?? null) && ! isset($destinationTypes[$row['entry_type']])) {
            $findings[] = ['code' => 'structured-unmapped-entry-type', 'field' => 'entry_type'];
        }

        return $findings;
    }

    /** @return list<array<string, mixed>|null>|null */
    private function json(string $bytes): ?array
    {
        try {
            $records = json_decode($bytes, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($records) || ! array_is_list($records)) {
            return null;
        }

        return array_map(static fn (mixed $record): ?array => $record instanceof stdClass ? get_object_vars($record) : null, $records);
    }

    /** @return list<array<string, mixed>|null>|null */
    private function csv(string $bytes): ?array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open the CSV read buffer.');
        }

        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! rewind($stream)) {
                throw new RuntimeException('Could not load the CSV read buffer.');
            }

            $header = fgetcsv($stream, escape: '');
            $afterHeader = ftell($stream);
            if ($header === false || $afterHeader === false || count($header) !== count(array_unique($header))
                || array_diff(self::COLUMNS, $header) !== [] || array_diff($header, [...self::COLUMNS, 'values_json', 'links_json']) !== []
                || ! self::wellFormedCsvRecord(substr($bytes, 0, $afterHeader))) {
                return null;
            }

            $rows = [];
            $offset = $afterHeader;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $end = ftell($stream);
                if ($end === false) {
                    throw new RuntimeException('Could not locate the next CSV row.');
                }

                $record = substr($bytes, $offset, $end - $offset);
                $rows[] = count($values) === count($header) && self::wellFormedCsvRecord($record)
                    ? array_combine($header, $values) : null;
                $offset = $end;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private static function wellFormedCsvRecord(string $record): bool
    {
        // fgetcsv accepts unclosed quotes and bytes after a closing quote. Treat those rows as incomplete input.
        $cell = '(?:"(?:[^"]++|"")*+"|[^,"\r\n]*+)';

        return preg_match('~\A'.$cell.'(?:,'.$cell.')*+(?:\r?\n)?\z~D', $record) === 1;
    }
}
