<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Readers\Contracts\ClassifiesReaderColumns;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\Contracts\ReaderColumnClass;
use Throwable;

/** @internal The host projection and its coverage report, without exposing undeclared columns. */
final class ReaderColumnProjection
{
    /** @return array{account: array<string, scalar|null>|null, coverage: array<string, int|string>} */
    public static function for(#[\SensitiveParameter] (Model&ReaderAccount)|null $reader): array
    {
        if ($reader === null) {
            return ['account' => null, 'coverage' => ['status' => 'account_absent']];
        }

        if (! $reader instanceof ClassifiesReaderColumns) {
            return ['account' => $reader->exportAccount(), 'coverage' => ['status' => 'legacy_host_projection']];
        }

        try {
            $columns = $reader->getConnection()->getSchemaBuilder()->getColumnListing($reader->getTable());
            $declared = $reader::readerColumnPrivacy();
            $values = $reader->getRawOriginal();
            $casts = $reader->getCasts();
        } catch (Throwable) {
            return ['account' => [], 'coverage' => ['status' => 'host_declaration_unavailable', 'scope' => 'reader_table']];
        }

        $aliases = [];
        $secretNames = array_fill_keys($reader->getHidden(), true);
        $unclassified = 0;
        $invalid = 0;
        foreach ($columns as $column) {
            $spec = self::mapping($declared[$column] ?? null);
            if ($spec === null) {
                if (array_key_exists($column, $declared)) {
                    $invalid++;
                } else {
                    $unclassified++;
                }

                continue;
            }

            [$class, $alias] = $spec;
            if ($class === ReaderColumnClass::Secret || isset($secretNames[$column])) {
                $secretNames[$column] = true;
                if ($alias !== null) {
                    $invalid++;
                    $secretNames[$alias] = true;
                }

                continue;
            }

            if ($alias === null) {
                if (in_array($class, [ReaderColumnClass::Personal, ReaderColumnClass::Sensitive], true)) {
                    $invalid++;
                }

                continue;
            }

            $aliases[$alias][] = $column;
        }

        $account = [];
        foreach ($aliases as $alias => $sources) {
            $source = $sources[0];
            if (count($sources) !== 1 || isset($secretNames[$alias]) || ! array_key_exists($source, $values)) {
                $invalid++;

                continue;
            }

            try {
                $value = ReaderColumnCast::read($values[$source], $casts[$source] ?? null);
            } catch (Throwable) {
                $invalid++;

                continue;
            }

            if (! self::exportableValue($value)) {
                $invalid++;

                continue;
            }

            $account[$alias] = $value;
        }

        $stale = count(array_diff(array_keys($declared), $columns));
        $coverage = [
            'status' => $columns !== [] && $unclassified + $invalid + $stale === 0 ? 'declared' : 'partial',
            'scope' => 'reader_table',
            'unclassified_columns' => $unclassified,
            'stale_column_declarations' => $stale,
            'invalid_export_mappings' => $invalid,
        ];

        return ['account' => $account, 'coverage' => $coverage];
    }

    /** @return array{ReaderColumnClass, ?string}|null */
    private static function mapping(mixed $spec): ?array
    {
        if (! is_array($spec) || ! ($spec['class'] ?? null) instanceof ReaderColumnClass) {
            return null;
        }

        $alias = $spec['export_as'] ?? null;
        if ($alias !== null && (! is_string($alias) || trim($alias) === '' || is_numeric($alias))) {
            return null;
        }

        return [$spec['class'], $alias];
    }

    private static function exportableValue(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }
}
