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
 * Read structured draft metadata into the shared migration preview.
 *
 * @internal
 */
final readonly class StructuredDraftInput
{
    private const COLUMNS = ['source_type', 'source_id', 'entry_type', 'title', 'slug'];

    public function __construct(private ImportJournal $journal) {}

    /** @param array<string, int> $destinationTypes Operator-approved source type to destination type IDs. */
    public function preview(
        string $format,
        string $sourceKey,
        string $bytes,
        array $destinationTypes,
        bool $complete,
    ): ImportPlan {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidArgumentException('The structured import must use UTF-8.');
        }

        $input = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        $rows = match ($format) {
            'csv' => $this->csv($input),
            'json' => $this->json($input),
            default => throw new InvalidArgumentException('The structured import format is unavailable.'),
        };

        $drafts = [];
        foreach ($rows as $row) {
            if (count($row) !== count(self::COLUMNS) || array_diff(self::COLUMNS, array_keys($row)) !== []
                || ! is_string($row['entry_type']) || ! isset($destinationTypes[$row['entry_type']])) {
                $drafts[] = [];

                continue;
            }

            $drafts[] = [
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'entry_type_id' => $destinationTypes[$row['entry_type']],
                'title' => $row['title'],
                'slug' => $row['slug'],
            ];
        }

        return $this->journal->preview($sourceKey, $format.':v1', hash('sha256', $bytes), $drafts, $complete);
    }

    /** @return list<array<string, mixed>> */
    private function json(string $bytes): array
    {
        try {
            $records = json_decode($bytes, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('The structured import contains invalid JSON.', previous: $failure);
        }

        if (! is_array($records) || ! array_is_list($records)) {
            throw new InvalidArgumentException('The structured JSON import must be a list of objects.');
        }

        return array_map(static fn (mixed $record): array => $record instanceof stdClass ? get_object_vars($record) : [], $records);
    }

    /** @return list<array<string, mixed>> */
    private function csv(string $bytes): array
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
            if ($header === false || $afterHeader === false || count($header) !== count(self::COLUMNS)
                || array_diff(self::COLUMNS, $header) !== [] || ! self::wellFormedCsvRecord(substr($bytes, 0, $afterHeader))) {
                throw new InvalidArgumentException('The CSV header must contain each supported column once.');
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
                    ? array_combine($header, $values) : [];
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
