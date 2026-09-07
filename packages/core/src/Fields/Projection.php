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
}
