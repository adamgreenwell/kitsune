<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * The scalar kinds a field can be projected to for indexing.
 *
 * An enum rather than a string so the three drivers must handle every case:
 * PHPStan fails an unhandled match, which means adding a logical type cannot
 * silently leave one engine behind. That is not hypothetical — `integer` and
 * `boolean` were unindexable on MySQL for a while, and nothing said so.
 */
enum LogicalType: string
{
    case Decimal = 'decimal';
    case Integer = 'integer';
    case String = 'string';
    case Boolean = 'boolean';

    /*
     * Dates are STRINGS in storage, and that is deliberate rather than lazy.
     *
     * PostgreSQL requires a stored generated column's expression to be
     * IMMUTABLE, and a text-to-DATE cast is only STABLE — it refuses the
     * column outright. `toStorage()` normalises both to fixed-width ISO-8601
     * (`YYYY-MM-DD`, and UTC `…+00:00` for datetimes), so string ordering is
     * exact chronological ordering and range queries are exact.
     *
     * They stay distinct from `String` because the width differs and because
     * an operator reading `idx_published__date` should see what it holds.
     */
    case Date = 'date';
    case DateTime = 'datetime';

    /**
     * Which JSON type a value must be for this projection to accept it.
     *
     * A shared generated column reads its JSON key from EVERY row in
     * `entries`, including rows belonging to orgs that gave the same handle a
     * different type (ADR-028). Without this guard, one org's `"contact us"`
     * makes another org's numeric column fail to create on PostgreSQL and
     * MySQL, and index as `0` on SQLite — a wrong answer rather than an
     * error.
     */
    public function jsonKind(): JsonKind
    {
        return match ($this) {
            self::Decimal, self::Integer => JsonKind::Number,
            self::Boolean => JsonKind::Boolean,
            self::String, self::Date, self::DateTime => JsonKind::Text,
        };
    }
}
