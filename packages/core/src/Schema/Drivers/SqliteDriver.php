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
 * The engine that diverges structurally rather than syntactically.
 *
 * SQLite cannot ALTER TABLE ADD COLUMN a STORED generated column - it is a
 * documented restriction, not a version gap. It accepts a VIRTUAL one, which
 * is indexable, so index-on-demand still works. The cost model inverts:
 * nothing is materialised, so there is no write amplification and no table
 * rewrite, but the expression is evaluated per row scanned.
 *
 * It is also the engine that fails SILENTLY without the JSON type guard. A
 * text value cast to NUMERIC becomes 0 rather than an error, so another org's
 * `"contact us"` would have indexed as a price of zero and matched queries
 * for it. The other two engines at least refuse.
 *
 * Serves pillar three. A small site needs no database server (ADR-027).
 */
final class SqliteDriver implements SchemaDriver
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function supportsStoredGeneratedColumns(): bool
    {
        return false;
    }

    public function columnType(Projection $projection): string
    {
        // SQLite's affinity system is loose, but the spellings still have to
        // be ones it parses inside CAST.
        return match ($projection->logical) {
            LogicalType::Decimal => "NUMERIC({$projection->precision},{$projection->scale})",
            LogicalType::Integer, LogicalType::Boolean => 'INTEGER',
            LogicalType::String, LogicalType::Date, LogicalType::DateTime => "VARCHAR({$projection->precision})",
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, Projection $projection): string
    {
        $column = $this->quote($jsonColumn);
        $key = $this->literal('$.'.$path);

        $guard = sprintf(
            'json_type(%s, %s) IN (%s)',
            $column,
            $key,
            implode(', ', array_map($this->literal(...), $this->jsonTypes($projection))),
        );

        // ⚠️ An integer projection also checks the extracted value's STORAGE
        // CLASS, because `json_type()` answers a different question.
        //
        // For `-9223372036854775809` — one below the BIGINT bound —
        // `json_type()` reports 'integer' (it reads the text shape: no point,
        // no exponent) while `typeof(json_extract(...))` reports 'real',
        // because SQLite promoted a value that does not fit an int64. Rounded
        // to a double it then compares EQUAL to the bound literal, so the range
        // guard passed and the cast CLAMPED it to the bound — meaning imported
        // out-of-range JSON matched a legitimate minimum-value lookup, on
        // SQLite only. Verified by probe: json_type 'integer', typeof 'real',
        // BETWEEN 1, cast -9223372036854775808.
        if ($projection->logical === LogicalType::Integer) {
            $guard .= sprintf(
                ' AND typeof(json_extract(%s, %s)) = %s',
                $column,
                $key,
                $this->literal('integer'),
            );
        }

        if (($range = $projection->range()) !== null) {
            $value = sprintf('json_extract(%s, %s)', $column, $key);

            if (($scale = $projection->comparisonScale()) !== null) {
                $value = sprintf('round(%s, %d)', $value, $scale);
            }

            $guard .= sprintf(' AND %s BETWEEN %s AND %s', $value, $range['min'], $range['max']);
        }

        return sprintf(
            'CASE WHEN %s THEN CAST(json_extract(%s, %s) AS %s) END',
            $guard,
            $column,
            $key,
            $this->columnType($projection),
        );
    }

    public function addGeneratedColumnSql(string $table, string $column, string $jsonColumn, string $path, Projection $projection): string
    {
        // VIRTUAL, not STORED. SQLite rejects STORED here.
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) VIRTUAL',
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

    /** @return list<string> json_type() spellings this projection accepts. */
    private function jsonTypes(Projection $projection): array
    {
        return match ($projection->jsonKind()) {
            JsonKind::Number => ['integer', 'real'],
            // SQLite reports JSON booleans as the literals themselves.
            JsonKind::Boolean => ['true', 'false'],
            JsonKind::Text => ['text'],
        };
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
