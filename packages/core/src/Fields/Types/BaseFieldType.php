<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\BlockDirection;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\ValueDirection;

/**
 * Sensible defaults so each type carries only its differences.
 *
 * The defaults are the common case: inline JSON storage, not indexable,
 * single-valued, round-tripping unchanged, and classified `none`. A type
 * overriding nothing is a plain scalar stored in `values`.
 */
abstract class BaseFieldType implements FieldType
{
    public static function icon(): string
    {
        return 'heroicon-o-squares-2x2';
    }

    public function strategy(): StorageStrategy
    {
        return StorageStrategy::Inline;
    }

    public function isIndexable(): bool
    {
        return false;
    }

    public function supportsCardinality(): bool
    {
        return true;
    }

    public function projection(FieldConfig $config): ?Projection
    {
        return null;
    }

    /** Inline by default: the value lives in `values`, keyed by handle. */
    public function promotedColumn(): ?string
    {
        return null;
    }

    /**
     * Cardinality is handled HERE, once, rather than in every scalar type.
     *
     * ⚠️ It was not handled anywhere, and `supportsCardinality()` defaults to
     * true — so a multi-value `text` field cast its array to the literal
     * string `"Array"`, `number` cast it to `1.0`, and `date` threw an
     * unhandled Carbon exception. Every scalar type advertised a capability
     * none of them implemented.
     *
     * A type that manages its own array — `relation`, `multi_select` — either
     * declares `supportsCardinality(): false` or overrides this method.
     */
    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if (! $config->isMultiValue()) {
            return $this->withValueDirection($this->castToStorage($input, $config));
        }

        if ($input === null || $input === '') {
            return [];
        }

        return array_values(array_map(
            fn (mixed $value): mixed => $this->withValueDirection($this->castToStorage($value, $config)),
            (array) $input,
        ));
    }

    /**
     * Apply the direction the CONTROL requires inside the stored value, not the one a type remembers.
     *
     * ⚠️ THIS IS WHAT ADR-029 CLAIMS AND DID NOT HAVE, which review found. That ADR's test is *"can a
     * new field type be added without text direction, and is that expressible at all?"*, and its answer
     * is no — *"because direction is derived by the renderer from the control's kind and is not a
     * property a field type can decline to set"*. Per-block direction was a private method on
     * `RichTextType`, so a module registering another type returning `Control::RichText` got none, and
     * `FieldValueRenderer` adds none for `PerBlock` because the direction belongs in the stored bytes.
     * The claim was true of every direction except the only one that needs help.
     *
     * ⚠️ KEYED ON `ValueDirection`, NOT ON THE CONTROL CASE, because that enum is where the decision
     * already lives: `Control::direction()` is the closed mapping, `needsAutoDirection()` is the
     * renderer's half of it, and `PerBlock`'s own docblock already says direction is needed *inside* the
     * value. This is that sentence made to happen rather than restated.
     *
     * ⚠️ AND IT TOUCHES NOTHING ELSE: every other `ValueDirection` is carried by an attribute on the
     * element the renderer emits, so there is nothing to change in the bytes. A non-string value is
     * returned untouched, because a control whose value is not HTML has no blocks to stamp.
     */
    protected function withValueDirection(mixed $stored): mixed
    {
        if (! is_string($stored) || $this->control()->direction() !== ValueDirection::PerBlock) {
            return $stored;
        }

        return BlockDirection::stampedInto($stored);
    }

    public function fromStorage(mixed $stored, FieldConfig $config): mixed
    {
        if (! $config->isMultiValue()) {
            return $this->castFromStorage($stored, $config);
        }

        return array_values(array_map(
            fn (mixed $value): mixed => $this->castFromStorage($value, $config),
            (array) ($stored ?? []),
        ));
    }

    /** Convert ONE value on the way in. Scalar types override this. */
    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input;
    }

    /** Convert ONE value on the way out. */
    protected function castFromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return $stored;
    }

    public function toApi(mixed $stored, FieldConfig $config): mixed
    {
        return $this->fromStorage($stored, $config);
    }

    public function fromApi(mixed $input, FieldConfig $config): mixed
    {
        return $this->toStorage($input, $config);
    }

    /**
     * Multi-value fields publish an ARRAY schema, not their element's.
     *
     * Storage and API conversion both return an array once cardinality is not
     * one, so advertising the scalar shape would generate clients that submit
     * and expect the wrong thing — the documented contract disagreeing with
     * the implemented one.
     *
     * @return array<string, mixed>
     */
    public function apiSchema(FieldConfig $config): array
    {
        $item = $this->scalarApiSchema($config);

        if (! $config->isMultiValue()) {
            return $item;
        }

        $schema = ['type' => 'array', 'items' => $item];

        // ⚠️ The bound is PUBLISHED. Validation enforces `max:{cardinality}`,
        // so describing every multi-value field as an unbounded array meant a
        // generated client considered three elements valid on a field that
        // holds two, and the API rejected what its own schema allowed.
        // -1 is the explicit unlimited and stays unbounded.
        if ($config->cardinality() > 0) {
            $schema['maxItems'] = $config->cardinality();
        }

        return $schema;
    }

    /**
     * Cardinality decides WHERE the scalar rules apply, in one place.
     *
     * ⚠️ Handling cardinality in `toStorage()` and not here left every
     * multi-value scalar field unusable in the opposite direction: `string`
     * and `numeric` were applied to the outer array, so the correct array was
     * rejected while a bare scalar was accepted and silently wrapped. Types
     * describe their scalar constraints once, in `scalarValidationRules()`,
     * and this decides whether they land on the field or on `handle.*`.
     *
     * @return array<int, mixed>
     */
    public function validationRules(FieldConfig $config): array
    {
        $presence = $config->isRequired() ? ['required'] : ['nullable'];

        if (! $config->isMultiValue()) {
            return [...$presence, ...$this->scalarValidationRules($config)];
        }

        // ⚠️ `list`, not just `array`. A decoded API object such as
        // `{"primary": "a", "secondary": "b"}` is an associative PHP
        // array, so `array` accepted it and the element rules validated
        // its values — then `toStorage()` called `array_values()` and
        // stored `["a", "b"]`. An object accepted against a published
        // array schema, and its shape changed on the way in.
        $rules = [...$presence, 'array', 'list'];

        // -1 is the explicit "unlimited". A cardinality of 2 means TWO, and
        // accepting three silently stored a shape the configuration forbids.
        if ($config->cardinality() > 1) {
            $rules[] = 'max:'.$config->cardinality();
        }

        return $rules;
    }

    /** @return array<int, mixed> */
    public function elementValidationRules(FieldConfig $config): array
    {
        return $config->isMultiValue() ? $this->scalarValidationRules($config) : [];
    }

    /**
     * Constraints on ONE value, wherever it ends up being applied.
     *
     * @return array<int, mixed>
     */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => 'string'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }

    /**
     * Nothing to say by default: a type with no settings cannot contradict
     * itself, and one with independent settings has nothing cross-cutting to
     * check.
     *
     * @param  array<string, mixed>  $settings
     */
    /** Most conversions are casts, which lose nothing worth keeping. */
    public function retainsOriginal(): bool
    {
        return false;
    }

    public function validateSettings(array $settings): ?string
    {
        return null;
    }

    public function suggestedPiiClass(): string
    {
        return 'none';
    }
}
