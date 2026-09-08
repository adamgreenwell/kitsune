<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;

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
            return $this->castToStorage($input, $config);
        }

        if ($input === null || $input === '') {
            return [];
        }

        return array_values(array_map(
            fn (mixed $value): mixed => $this->castToStorage($value, $config),
            (array) $input,
        ));
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

        $rules = [...$presence, 'array'];

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

    public function suggestedPiiClass(): string
    {
        return 'none';
    }
}
