<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * What a field type projects to, described rather than spelled.
 *
 * A field type says `decimal`; the driver decides that PostgreSQL wants
 * NUMERIC, that MySQL wants DECIMAL as a column and DECIMAL inside CAST while
 * rejecting NUMERIC there, and that an integer column is BIGINT filled by a
 * SIGNED cast. None of that belongs in a field type.
 *
 * Replaces `generatedColumnType(SchemaDriver)`, which handed the field type a
 * driver so it could render SQL itself. That was the wrong seam: it still
 * required the field type to know a rendered type serves two grammars, and it
 * gave the driver no way to guard the expression by JSON type — the hole
 * ADR-028's amendment closes.
 */
final readonly class Projection
{
    /**
     * The widest string an engine will accept in a composite index.
     *
     * Measured, not guessed: MySQL's InnoDB key limit is 3,072 bytes and
     * utf8mb4 costs four bytes a character, so `VARCHAR(1000)` fails with
     * `ERROR 1071: Specified key was too long` while `VARCHAR(700)` — 2,800
     * bytes plus the leading `site_id` — succeeds. Long text that needs
     * searching wants full-text search, not a scalar projection.
     */
    public const MAX_INDEXED_STRING_WIDTH = 700;

    public function __construct(
        public LogicalType $logical,
        /** Column width, or numeric precision for `decimal`. */
        public int $precision = 12,
        public int $scale = 2,
    ) {}

    public function jsonKind(): JsonKind
    {
        return $this->logical->jsonKind();
    }

    /**
     * The largest magnitude this projection can hold, or null if unbounded.
     *
     * ⚠️ The JSON-kind guard is not enough on its own. A shared column reads
     * its key from EVERY row, including rows belonging to an org that never
     * indexed the field at all — so org B's perfectly valid
     * `"price": 10000000000` overflows org A's `NUMERIC(12,2)` and stops the
     * column being created. Type-correct and still out of range.
     */
    public function magnitudeBound(): ?string
    {
        return match ($this->logical) {
            // Built as a string, not with bcmath or pow(): bcmath is not a
            // guaranteed extension (ADR-027 keeps the floor lean) and a float
            // pow() loses exactness at the magnitudes this exists to bound.
            LogicalType::Decimal => '1'.str_repeat('0', $this->precision - $this->scale),
            // BIGINT. Bounded rather than unbounded, because a JSON number
            // larger than this is not representable in the column either.
            LogicalType::Integer => '9223372036854775808',
            LogicalType::String, LogicalType::Boolean, LogicalType::Date, LogicalType::DateTime => null,
        };
    }

    /**
     * The projection's identity, as it appears in the column name.
     *
     * ADR-028 names a column for its projection, and the projection depends
     * on configuration as well as on the field type: a `number` with
     * `format: integer` projects to BIGINT while its decimal sibling projects
     * to DECIMAL(12,2), and two `text` fields can want different widths.
     * Naming after the field type HANDLE was therefore not injective — two
     * orgs configuring `number` differently would have collided on
     * `idx_count__number` with incompatible column types.
     *
     * Width is included only where it varies, so `idx_active__boolean` stays
     * readable while `idx_price__decimal12_2` stays unambiguous.
     */
    public function signature(): string
    {
        return match ($this->logical) {
            LogicalType::Decimal => "decimal{$this->precision}_{$this->scale}",
            LogicalType::String => "string{$this->precision}",
            LogicalType::Integer, LogicalType::Boolean, LogicalType::Date, LogicalType::DateTime => $this->logical->value,
        };
    }
}
