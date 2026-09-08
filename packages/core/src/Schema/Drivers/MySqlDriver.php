<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema\Drivers;

use Kitsune\Core\Fields\JsonKind;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Schema\SchemaDriver;

/**
 * MySQL: `->>` with a `$`-prefixed path, CAST wrapper, backtick quoting.
 *
 * The engine that needs TWO type spellings, which is why the driver exposes a
 * column type and keeps the cast type to itself:
 *
 * | logical | column      | inside CAST |
 * |---------|-------------|-------------|
 * | decimal | DECIMAL     | DECIMAL — NUMERIC is rejected here |
 * | integer | BIGINT      | SIGNED — BIGINT is rejected here |
 * | string  | VARCHAR(n)  | CHAR(n) — VARCHAR is rejected here |
 *
 * Returning one string for both left `integer` fields failing at ADD COLUMN
 * and `boolean` fields failing at CAST, because `->>` renders a JSON boolean
 * as the text `'true'` and `CAST('true' AS UNSIGNED)` is an error. Booleans
 * therefore compare the JSON value directly.
 */
final class MySqlDriver implements SchemaDriver
{
    public function name(): string
    {
        return 'mysql';
    }

    public function supportsStoredGeneratedColumns(): bool
    {
        return true;
    }

    public function columnType(Projection $projection): string
    {
        return match ($projection->logical) {
            LogicalType::Decimal => "DECIMAL({$projection->precision},{$projection->scale})",
            LogicalType::Integer => 'BIGINT',
            LogicalType::Boolean => 'TINYINT(1)',
            // VARCHAR for dates too, matching PostgreSQL, which cannot use a
            // real DATE in a generated column at all.
            LogicalType::String, LogicalType::Date, LogicalType::DateTime => "VARCHAR({$projection->precision})",
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, Projection $projection): string
    {
        $column = $this->quote($jsonColumn);
        $extract = sprintf('JSON_EXTRACT(%s, %s)', $column, $this->literal('$.'.$path));

        // A JSON boolean renders as the text 'true'/'false' through ->>, and
        // casting that to a number is an error. Compare the JSON instead.
        $value = $projection->logical === LogicalType::Boolean
            ? sprintf('(%s = CAST(%s AS JSON))', $extract, $this->literal('true'))
            : sprintf('CAST(%s->>%s AS %s)', $column, $this->literal('$.'.$path), $this->castType($projection));

        $guard = sprintf(
            'JSON_TYPE(%s) IN (%s)',
            $extract,
            implode(', ', array_map($this->literal(...), $this->jsonTypes($projection))),
        );

        if (($range = $projection->range()) !== null) {
            // DECIMAL(65,10) is MySQL's widest, so the comparison cannot
            // overflow the type it is protecting. Rounded, because the final
            // cast rounds and a value inside the raw range can overflow once
            // it has.
            $value = sprintf('CAST(%s->>%s AS DECIMAL(65,10))', $column, $this->literal('$.'.$path));

            if (($scale = $projection->comparisonScale()) !== null) {
                $value = sprintf('ROUND(%s, %d)', $value, $scale);
            }

            $guard .= sprintf(' AND %s BETWEEN %s AND %s', $value, $range['min'], $range['max']);
        }

        return sprintf('CASE WHEN %s THEN %s END', $guard, $value);
    }

    public function addGeneratedColumnSql(string $table, string $column, string $jsonColumn, string $path, Projection $projection): string
    {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) STORED',
            $this->quote($table),
            $this->quote($column),
            $this->columnType($projection),
            $this->jsonExtractExpression($jsonColumn, $path, $projection),
        );
    }

    public function dropGeneratedColumnSql(string $table, string $column): string
    {
        return sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quote($table), $this->quote($column));
    }

    public function createIndexSql(string $table, string $index, string ...$columns): string
    {
        return sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $this->quote($index),
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
        );
    }

    /** MySQL scopes DROP INDEX to a table; the other two engines do not. */
    public function dropIndexSql(string $table, string $index): string
    {
        return sprintf('DROP INDEX %s ON %s', $this->quote($index), $this->quote($table));
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** The spelling CAST accepts, which is not the column spelling. */
    private function castType(Projection $projection): string
    {
        return match ($projection->logical) {
            LogicalType::Decimal => "DECIMAL({$projection->precision},{$projection->scale})",
            LogicalType::Integer => 'SIGNED',
            LogicalType::Boolean => 'UNSIGNED',
            LogicalType::String, LogicalType::Date, LogicalType::DateTime => "CHAR({$projection->precision})",
        };
    }

    /** @return list<string> JSON_TYPE() spellings this projection accepts. */
    private function jsonTypes(Projection $projection): array
    {
        return match ($projection->jsonKind()) {
            // MySQL reports integers, unsigned integers, doubles and decimals
            // separately; all four are numbers.
            JsonKind::Number => ['INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL'],
            JsonKind::Boolean => ['BOOLEAN'],
            JsonKind::Text => ['STRING'],
        };
    }

    private function literal(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
