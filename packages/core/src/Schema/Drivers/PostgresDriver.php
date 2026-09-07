<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema\Drivers;

use Kitsune\Core\Schema\SchemaDriver;

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

    public function sqlType(string $logical, int $precision = 12, int $scale = 2): string
    {
        return match ($logical) {
            'decimal' => "NUMERIC({$precision},{$scale})",
            'integer' => 'BIGINT',
            'string' => "VARCHAR({$precision})",
            'boolean' => 'BOOLEAN',
            'datetime' => 'TIMESTAMP',
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, string $sqlType): string
    {
        // Postgres uses ->> with a bare key and a cast suffix.
        return sprintf('(%s ->> %s)::%s', $this->quote($jsonColumn), $this->literal($path), $sqlType);
    }

    public function addGeneratedColumnSql(string $table, string $column, string $jsonColumn, string $path, string $sqlType): string
    {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) STORED',
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

    public function dropIndexSql(string $table, string $index): string
    {
        return sprintf('DROP INDEX IF EXISTS %s', $this->quote($index));
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
