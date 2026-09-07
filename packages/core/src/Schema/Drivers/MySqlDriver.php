<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema\Drivers;

use Kitsune\Core\Schema\SchemaDriver;

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

    public function sqlType(string $logical, int $precision = 12, int $scale = 2): string
    {
        // DECIMAL, not NUMERIC: MySQL accepts NUMERIC as a column type but
        // rejects it inside CAST, which is where generated columns use it.
        return match ($logical) {
            'decimal' => "DECIMAL({$precision},{$scale})",
            'integer' => 'SIGNED',
            'string' => "CHAR({$precision})",
            'boolean' => 'UNSIGNED',
            'datetime' => 'DATETIME',
        };
    }

    public function jsonExtractExpression(string $jsonColumn, string $path, string $sqlType): string
    {
        // MySQL uses a $-prefixed path and a CAST wrapper rather than a suffix.
        return sprintf('CAST(%s->>%s AS %s)', $this->quote($jsonColumn), $this->literal('$.'.$path), $sqlType);
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

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
