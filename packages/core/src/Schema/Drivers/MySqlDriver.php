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
 * MySQL and MariaDB: JSON_UNQUOTE(JSON_EXTRACT(...)) with a `$`-prefixed
 * path, CAST wrapper, backtick quoting.
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
 * and `boolean` fields failing at CAST, because unquoting renders a JSON boolean
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

    /**
     * utf8mb4's maximum encoded width, because the column is sized in bytes
     * and the projection is specified in characters.
     */
    private const BYTES_PER_CHARACTER = 4;

    public function columnType(Projection $projection): string
    {
        return match ($projection->logical) {
            LogicalType::Decimal => "DECIMAL({$projection->precision},{$projection->scale})",
            LogicalType::Integer => 'BIGINT',
            LogicalType::Boolean => 'TINYINT(1)',
            // ⚠️ VARBINARY, not VARCHAR with a collation.
            //
            // MySQL's default collation is case AND accent insensitive, so an
            // indexed exact filter matched `abc` for `ABC` while PostgreSQL
            // and SQLite distinguished them — the same query returning
            // different rows on different engines, which is the one thing the
            // driver abstraction exists to prevent.
            //
            // `utf8mb4_bin` fixed the case half and left another: it is a
            // PAD SPACE collation, so `'ABC '` and `'ABC'` still compare
            // equal and either lookup returned both rows. The NO PAD
            // alternative, `utf8mb4_0900_bin`, does not exist on MariaDB —
            // which is documented as supported and routed to this driver.
            //
            // A binary type is both, on both engines: byte comparison is
            // case-sensitive and does not pad. The column exists for exact
            // lookup on a JSON value that is already byte-exact, and is never
            // selected for display, so binary is what it should always have
            // been.
            //
            // Applied to dates too, matching PostgreSQL, which cannot use a
            // real DATE in a generated column at all.
            // ⚠️ Sized in BYTES, and the projection's precision counts
            // CHARACTERS. A width of 64 validates 64 characters, and 64 `é`
            // are 128 UTF-8 bytes — so the column either refused the ALTER
            // over existing data or truncated the indexed value, while
            // PostgreSQL and SQLite kept all 64. Four bytes per character is
            // utf8mb4's maximum, which is what the connection uses.
            LogicalType::String, LogicalType::Date, LogicalType::DateTime => sprintf(
                'VARBINARY(%d)',
                $projection->precision * self::BYTES_PER_CHARACTER,
            ),
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, Projection $projection): string
    {
        $column = $this->quote($jsonColumn);
        $extract = sprintf('JSON_EXTRACT(%s, %s)', $column, $this->literal('$.'.$path));

        // ⚠️ JSON_UNQUOTE(JSON_EXTRACT(...)), never the `->>` shorthand.
        //
        // MariaDB 10.6 has no `->>` operator, and it is documented as
        // supported and routed to this driver — so every generated column
        // failed to create there with a syntax error. The long form means the
        // same thing and both engines have it.
        $unquoted = sprintf('JSON_UNQUOTE(%s)', $extract);

        // A JSON boolean unquotes to the text 'true'/'false', and casting
        // that to a number is an error. So compare the text.
        //
        // ⚠️ NOT `CAST('true' AS JSON)`. MariaDB has no JSON cast, and it is
        // documented as supported and routed to this driver — so every indexed
        // boolean field failed to create its column there with a syntax
        // error. The JSON_TYPE guard above has already established this is a
        // boolean, so string equality is exact rather than lenient.
        $value = $projection->logical === LogicalType::Boolean
            ? sprintf('(%s = %s)', $unquoted, $this->literal('true'))
            : sprintf('CAST(%s AS %s)', $unquoted, $this->castType($projection));

        $guard = sprintf(
            'JSON_TYPE(%s) IN (%s)',
            $extract,
            implode(', ', array_map($this->literal(...), $this->jsonTypes($projection))),
        );

        if (($range = $projection->range()) !== null) {
            // ⚠️ The magnitude gate comes FIRST, and it casts to DOUBLE
            // rather than DECIMAL.
            //
            // DECIMAL(65,10) is MySQL's widest, but it is still bounded: a
            // perfectly valid JSON number like 1e100 overflows the cast under
            // strict mode, so the statement ERRORS before BETWEEN can return
            // false — when adding the column over existing rows, and again on
            // any later write. A guard that throws instead of excluding is
            // not a total expression (ADR-028).
            //
            // DOUBLE cannot overflow here by construction: MySQL parses every
            // JSON number as a double already, so anything in the document
            // fits one. 1e30 is far inside DECIMAL(65,10)'s 55 integer
            // digits, so passing this gate makes the cast below safe.
            $magnitude = sprintf('ABS(CAST(%s AS DOUBLE)) <= 1e30', $unquoted);

            $bounded = sprintf('CAST(%s AS DECIMAL(65,10))', $unquoted);

            if (($scale = $projection->comparisonScale()) !== null) {
                // Rounded, because the final cast rounds and a value inside
                // the raw range can overflow once it has.
                $bounded = sprintf('ROUND(%s, %d)', $bounded, $scale);
            }

            // ⚠️ NESTED, not another AND. MySQL does not promise to evaluate
            // AND operands left to right, so a gate that sits beside the
            // expression it protects does not protect it. CASE does
            // short-circuit, so the bounded cast is only ever reached for a
            // value already known to fit.
            return sprintf(
                'CASE WHEN %s AND %s THEN CASE WHEN %s BETWEEN %s AND %s THEN %s END END',
                $guard,
                $magnitude,
                $bounded,
                $range['min'],
                $range['max'],
                $value,
            );
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
