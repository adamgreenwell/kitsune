<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

/**
 * The shape a caller wants a `field_storage` row to have, independent of where the caller got it.
 *
 * @internal
 *
 * ⚠️ IT EXISTS SO THE ADOPTION RULE CAN HAVE TWO CALLERS. The rule lived inside `FieldsRelationManager`,
 * reading Filament form-state keys — `$data['storage_handle']`, `$data['storage_pii_class']` — so a second
 * caller could only have re-implemented it. This project has already found that same rule drifting in three
 * other places; the blueprint applier is the second caller, and this is the shape they agree on.
 */
final readonly class DesiredStorage
{
    /**
     * @param  'none'|'personal'|'sensitive'|string  $piiClass
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $handle,
        public string $type,
        public string $piiClass,
        public int $cardinality = 1,
        public bool $isIndexed = false,
        public array $settings = [],
    ) {}
}
