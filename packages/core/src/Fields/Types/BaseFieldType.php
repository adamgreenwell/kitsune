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

    /**
     * Rules for EACH element of a multi-value field, applied at `handle.*`.
     *
     * A separate method because Laravel needs a separate attribute for them:
     * a rule placed in the field's own list receives the whole array, so a
     * per-element check silently never runs. RelationType's cross-org check
     * was doing exactly that.
     *
     * @return array<int, mixed>
     */
    public function elementValidationRules(FieldConfig $config): array
    {
        return [];
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

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'string'];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return $config->isRequired() ? ['required'] : ['nullable'];
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
