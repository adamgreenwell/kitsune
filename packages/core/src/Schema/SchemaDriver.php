<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

/**
 * The seam ADR-006 depends on.
 *
 * Indexing a JSON-backed field means adding a generated column over the JSON
 * and indexing that. All three supported engines diverge on how, and SQLite
 * diverges structurally rather than just syntactically: it cannot add a STORED
 * generated column through ALTER TABLE at all.
 *
 * No field type ever writes SQL. It describes what it wants and asks the
 * driver, which is why generatedColumnType() takes one.
 */
interface SchemaDriver
{
    /** Matches the Laravel connection driver name: pgsql, mysql, sqlite. */
    public function name(): string;

    /**
     * STORED materialises the value on write; VIRTUAL computes it on read.
     *
     * This is not a detail. SQLite only supports VIRTUAL via ALTER TABLE, which
     * inverts the cost model: no write amplification and no table rewrite, but
     * the expression runs per row scanned.
     */
    public function supportsStoredGeneratedColumns(): bool;

    /** An expression projecting a JSON path to a scalar of the given SQL type. */
    public function jsonExtractExpression(string $jsonColumn, string $path, string $sqlType): string;

    /** SQL adding a generated column over a JSON path. */
    public function addGeneratedColumnSql(string $table, string $column, string $jsonColumn, string $path, string $sqlType): string;

    /** SQL dropping it again. */
    public function dropGeneratedColumnSql(string $table, string $column): string;

    /** SQL creating a composite index. Callers lead with the scope key (ADR-021). */
    public function createIndexSql(string $table, string $index, string ...$columns): string;

    /** Identifier quoting. `values` is reserved on more than one engine. */
    public function quote(string $identifier): string;
}
