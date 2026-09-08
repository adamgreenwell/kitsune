<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

use Kitsune\Core\Models\Entry;
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
        /**
         * The entry being validated, when there is one.
         *
         * ⚠️ A uniqueness rule has to exclude the row it is checking, or
         * editing anything rejects its own unchanged value as taken. The
         * static EntryResource form had this right because Filament hands it
         * the record; a field type is handed a FieldConfig, and there was
         * nowhere in it to say which entry.
         *
         * Passed explicitly rather than read from the container. `EntryType`
         * is bound by IdentifyEntryType so there is always one to find; no
         * middleware binds the entry, so a container lookup would have
         * returned null on every path and reintroduced the bug quietly. A
         * constructor argument makes the caller supply it or visibly not.
         */
        public readonly ?Entry $record = null,
    ) {}

    /** The same field, bound to the entry being validated. */
    public function for(?Entry $record): self
    {
        return new self($this->storage, $this->field, $record);
    }

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

    /** How many values this field holds; -1 means unlimited. */
    public function cardinality(): int
    {
        return (int) $this->storage->cardinality;
    }

    public function isIndexed(): bool
    {
        return (bool) $this->storage->is_indexed;
    }

    /**
     * ⚠️ STORAGE ONLY. Presentation does not get to change what is stored.
     *
     * This used to merge presentation over storage, "the per-type view wins".
     * That let an UNLOCKED `fields.settings` override a LOCKED
     * `field_storage.settings`: a per-type `maxLength: 400` against storage
     * that had already created `idx_summary__string255` accepted 400
     * characters and truncated them in the index, and a per-type
     * `format: integer` changed the conversion under stored decimals.
     *
     * Both route around ADR-006's lock-on-data invariant, which is settled —
     * so the precedence, not the invariant, is what had to give.
     *
     * Every setting a field type declares today affects storage, validation
     * or the projection. When a genuinely display-only one exists — a
     * placeholder, a widget hint — it gets `presentationSetting()` below and
     * an explicit place in the type's schema, rather than a precedence rule
     * that quietly applies to everything.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->storage->settings ?? [], $key, $default);
    }

    /**
     * A per-type override for values that change only how a field LOOKS.
     *
     * Falls back to storage, so a type can leave it unset. Nothing calls this
     * yet: it exists so that adding a display-only setting is a deliberate
     * decision at the call site rather than a side effect of a merge rule.
     */
    public function presentationSetting(string $key, mixed $default = null): mixed
    {
        $presentation = $this->field !== null ? ($this->field->settings ?? []) : [];

        return data_get($presentation, $key) ?? $this->setting($key, $default);
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
