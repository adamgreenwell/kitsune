<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints\Declarations;

/**
 * One field a blueprint declares — ADR-039, and ADR-006's storage/config split in one object.
 *
 * A field is TWO rows: a `field_storage` row that says what the data is and is reusable across entry types,
 * and a `fields` row that says how this type presents it. An author declares them together because that is how
 * a field is thought about, and the applier writes them separately because that is how they are stored.
 *
 * ⚠️ `pii_class` HAS NO DEFAULT HERE, and that is ADR-020 reaching the format. `FieldStorage::guardShape()`
 * refuses a null or unknown class on every save — the column is nullable in the schema and enforced in code
 * precisely so that a NOT NULL default cannot silently classify everything as `none`. A format that defaulted
 * it would put the guess back, one layer up, where the guard cannot see it.
 */
final readonly class FieldDeclaration
{
    /**
     * @param  string  $handle  the STORAGE handle — lowercase snake_case, at most 32 characters, no doubled
     *                          underscore, because it becomes the JSON key and part of a generated column name
     * @param  string  $type  a registered field type: text, textarea, rich_text, number, boolean, date,
     *                        datetime, select, multi_select, relation, slug, json
     * @param  'none'|'personal'|'sensitive'  $piiClass  declared, never guessed (ADR-020)
     * @param  int  $cardinality  1 for one value, -1 for unlimited
     * @param  array<string, mixed>  $settings  field-type settings; validated by the type itself
     * @param  bool  $isIndexed  writes the flag. It does NOT create the generated column — `SchemaManager::sync()`
     *                           does, after the rows commit, because DDL implicitly commits on MySQL
     */
    public function __construct(
        public string $handle,
        public string $type,
        public string $label,
        public string $piiClass,
        public int $cardinality = 1,
        public array $settings = [],
        public bool $isIndexed = false,
        public bool $isRequired = false,
        public ?string $helpText = null,
        public int $ordering = 0,
        public ?string $group = null,
    ) {}
}
