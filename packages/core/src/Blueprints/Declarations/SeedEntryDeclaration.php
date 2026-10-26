<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints\Declarations;

use Kitsune\Core\Blueprints\OnCollision;

/**
 * One optional content row, addressed by a stable author key — ADR-039, #205.
 *
 * No org/site/author IDs or arbitrary columns. The separately requested seed action
 * chooses the existing site and locale. Relations name seed keys, never entry IDs.
 * This declares row content; it cannot manufacture MediaLibrary file custody.
 * Validation and receipt ownership belong to the kernel, not this value object.
 */
final readonly class SeedEntryDeclaration
{
    /**
     * @param  string  $key  unique ASCII lowercase snake-case author key
     * @param  string  $entryType  a type handle in the applied schema receipt
     * @param  array<string, mixed>  $values  values for that type's declared scalar fields
     * @param  array<string, list<string>>  $relations  field-storage handles to ordered seed keys
     * @param  'draft'|'published'|'archived'  $status  explicitly supported Entry status
     */
    public function __construct(
        public string $key,
        public string $entryType,
        public string $title,
        public ?string $slug = null,
        public array $values = [],
        public array $relations = [],
        public string $status = 'draft',
        public OnCollision $onCollision = OnCollision::Fail,
    ) {}
}
