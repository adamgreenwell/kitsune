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
 * PostgreSQL: `->>` for text extraction, `::` casts, double-quote quoting.
 *
 * The engine with the strictest rule about what may appear in a stored
 * generated column: the expression must be IMMUTABLE. A text-to-DATE or
 * text-to-TIMESTAMP cast is only STABLE, so PostgreSQL refuses it outright
 * with "generation expression is not immutable" — which is why `date` and
 * `datetime` project to fixed-width ISO-8601 strings here and, for parity,
 * everywhere else.
 */
final class PostgresDriver implements SchemaDriver
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function supportsStoredGeneratedColumns(): bool
    {
        return true;
    }

    public function columnType(Projection $projection): string
    {
        return match ($projection->logical) {
            LogicalType::Decimal => "NUMERIC({$projection->precision},{$projection->scale})",
            LogicalType::Integer => 'BIGINT',
            LogicalType::Boolean => 'BOOLEAN',
            // Not DATE / TIMESTAMP: the cast that would fill them is STABLE,
            // not IMMUTABLE, and PostgreSQL rejects the column. ISO-8601 is
            // fixed width, so string ordering is exact date ordering.
            LogicalType::String, LogicalType::Date, LogicalType::DateTime => "VARCHAR({$projection->precision})",
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, Projection $projection): string
    {
        // ::jsonb, because Laravel's `json()` column is `json` on PostgreSQL
        // and `jsonb_typeof` has no overload for it. Casting rather than using
        // `json_typeof` keeps this working if `values` is ever migrated to
        // jsonb, and STORED evaluates it once per write rather than per read.
        $column = '('.$this->quote($jsonColumn).')::jsonb';
        $key = $this->literal($path);

        $guard = sprintf('jsonb_typeof(%s -> %s) = %s', $column, $key, $this->literal($this->jsonType($projection)));

        if (($range = $projection->range()) !== null) {
            // Cast to UNBOUNDED numeric and compare the ROUNDED value: the
            // final cast rounds to the projection's scale, so a value inside
            // the raw range can still overflow once rounded.
            $guard .= sprintf(
                ' AND round((%s ->> %s)::NUMERIC, %d) BETWEEN %s AND %s',
                $column,
                $key,
                $projection->scale,
                $range['min'],
                $range['max'],
            );
        }

        return sprintf(
            'CASE WHEN %s THEN (%s ->> %s)::%s END',
            $guard,
            $column,
            $key,
            $this->columnType($projection),
        );
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

    public function dropIndexSql(string $table, string $index): string
    {
        return sprintf('DROP INDEX IF EXISTS %s', $this->quote($index));
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** jsonb_typeof()'s spelling for this projection's accepted JSON type. */
    private function jsonType(Projection $projection): string
    {
        return match ($projection->jsonKind()) {
            JsonKind::Number => 'number',
            JsonKind::Boolean => 'boolean',
            JsonKind::Text => 'string',
        };
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
