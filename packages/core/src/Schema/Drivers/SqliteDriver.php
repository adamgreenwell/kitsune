<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema\Drivers;

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

    public function jsonExtractExpression(string $jsonColumn, string $path, string $sqlType): string
    {
        return sprintf('CAST(json_extract(%s, %s) AS %s)', $this->quote($jsonColumn), $this->literal('$.'.$path), $sqlType);
    }

    public function addGeneratedColumnSql(string $table, string $column, string $jsonColumn, string $path, string $sqlType): string
    {
        // VIRTUAL, not STORED. SQLite rejects STORED here.
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) VIRTUAL',
            $this->quote($table),
            $this->quote($column),
            $sqlType,
            $this->jsonExtractExpression($jsonColumn, $path, $sqlType),
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

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
