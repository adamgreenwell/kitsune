<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;

/**
 * One field as a type implementation sees it: storage plus presentation.
 *
 * The two are separate records for a reason (ADR-006, from Drupal): storage
 * is defined once and reusable across entity types, presentation is per-type.
 * A field type needs both and should not have to know they are two tables.
 */
final class FieldConfig
{
    public function __construct(
        public readonly FieldStorage $storage,
        public readonly ?Field $field = null,
    ) {}

    public function handle(): string
    {
        return $this->storage->handle;
    }

    public function label(): string
    {
        return $this->field !== null
            ? $this->field->label
            : str($this->storage->handle)->headline()->value();
    }

    public function isRequired(): bool
    {
        return $this->field !== null && $this->field->is_required;
    }

    public function isMultiValue(): bool
    {
        return $this->storage->cardinality !== 1;
    }

    public function isIndexed(): bool
    {
        return (bool) $this->storage->is_indexed;
    }

    /** Settings merge presentation over storage: the per-type view wins. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $presentation = $this->field !== null ? ($this->field->settings ?? []) : [];

        return data_get($presentation, $key)
            ?? data_get($this->storage->settings ?? [], $key, $default);
    }

    public function helpText(): ?string
    {
        return $this->field !== null ? $this->field->help_text : null;
    }

    /** @return array<string, mixed>|null */
    public function defaultValue(): mixed
    {
        return $this->field !== null ? $this->field->default_value : null;
    }
}
