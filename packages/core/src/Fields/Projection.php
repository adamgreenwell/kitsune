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
     * The range this projection can actually hold, or null if unbounded.
     *
     * ⚠️ The JSON-kind guard is not enough on its own. A shared column reads
     * its key from EVERY row, including rows belonging to an org that never
     * indexed the field at all — so org B's perfectly valid
     * `"price": 10000000000` overflows org A's `NUMERIC(12,2)` and stops the
     * column being created. Type-correct and still out of range.
     *
     * ⚠️ And a magnitude bound alone is not enough either, in BOTH
     * directions. `abs(v) < 10^10` admits `9999999999.999`, which the scale-2
     * cast then ROUNDS to `10000000000.00` and overflows anyway; and it
     * excludes `-9223372036854775808`, a perfectly valid BIGINT whose
     * absolute value equals the bound. The range is therefore explicit,
     * inclusive, and asymmetric where the type is — and the driver compares
     * the ROUNDED value, because rounding is what the cast will do.
     *
     * @return array{min: string, max: string}|null
     */
    public function range(): ?array
    {
        return match ($this->logical) {
            // Built as strings, not with bcmath or pow(): bcmath is not a
            // guaranteed extension (ADR-027 keeps the floor lean) and a float
            // pow() loses exactness at the magnitudes this exists to bound.
            // DECIMAL(12,2) holds up to 9999999999.99, not 10^10.
            LogicalType::Decimal => [
                'min' => '-'.$this->largestDecimal(),
                'max' => $this->largestDecimal(),
            ],
            // BIGINT, and its range is not symmetric.
            LogicalType::Integer => [
                'min' => '-9223372036854775808',
                'max' => '9223372036854775807',
            ],
            LogicalType::String, LogicalType::Boolean, LogicalType::Date, LogicalType::DateTime => null,
        };
    }

    /**
     * The scale a range check should round to, or null to compare exactly.
     *
     * ⚠️ Null for integers, and that is not tidiness. SQLite's `round()`
     * returns a REAL, so `round(9223372036854775807, 2)` becomes
     * `9.2233720368547758e+18` — above the very bound it is being compared
     * against. The guard then rejected `PHP_INT_MAX`, and an indexed integer
     * field holding it disappeared from every query. Integers compare
     * exactly; only a decimal needs rounding, because only a decimal is
     * rounded by its cast.
     */
    public function comparisonScale(): ?int
    {
        return $this->logical === LogicalType::Decimal ? $this->scale : null;
    }

    private function largestDecimal(): string
    {
        $whole = str_repeat('9', max(1, $this->precision - $this->scale));

        return $this->scale > 0 ? $whole.'.'.str_repeat('9', $this->scale) : $whole;
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
