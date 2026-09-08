<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

use Kitsune\Core\Fields\Types\BooleanType;
use Kitsune\Core\Fields\Types\DateTimeType;
use Kitsune\Core\Fields\Types\DateType;
use Kitsune\Core\Fields\Types\JsonType;
use Kitsune\Core\Fields\Types\MultiSelectType;
use Kitsune\Core\Fields\Types\NumberType;
use Kitsune\Core\Fields\Types\RelationType;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Fields\Types\SelectType;
use Kitsune\Core\Fields\Types\SlugType;
use Kitsune\Core\Fields\Types\TextareaType;
use Kitsune\Core\Fields\Types\TextType;
use RuntimeException;

/**
 * The field types available to the schema engine.
 *
 * Twelve in v1.0, deliberately small: every type added before the API freeze
 * is a permanent maintenance obligation (Standing Principle #2 — deprecate,
 * never remove). Modules register more through this registry.
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            TextType::class,
            TextareaType::class,
            RichTextType::class,
            NumberType::class,
            BooleanType::class,
            DateType::class,
            DateTimeType::class,
            SelectType::class,
            MultiSelectType::class,
            RelationType::class,
            SlugType::class,
            JsonType::class,
        ] as $class) {
            $this->register(new $class);
        }
    }

    public function register(FieldType $type): self
    {
        $handle = $type::handle();

        // ⚠️ A duplicate handle is REFUSED, not last-one-wins.
        //
        // `field_storage.type` identifies a type by handle alone, so a module
        // that reuses one silently changes the validation, conversion and
        // projection of every existing row — reinterpreting stored content,
        // or disagreeing with a generated column that was built from the
        // other implementation's signature. Which behaviour you got would
        // depend on module registration order.
        //
        // Replacing an implementation deliberately is a different operation
        // and wants its own name, so it can be reviewed as the override it is.
        if (isset($this->types[$handle]) && $type::class !== $this->types[$handle]::class) {
            throw new RuntimeException(sprintf(
                'Field type handle [%s] is already registered by [%s], so [%s] cannot take it. '
                .'`field_storage.type` records the handle alone, so two implementations behind one '
                .'handle would reinterpret existing rows according to load order.',
                $handle,
                $this->types[$handle]::class,
                $type::class,
            ));
        }

        $this->types[$handle] = $type;

        return $this;
    }

    /**
     * Fail closed on an unknown handle.
     *
     * Returning a text field as a fallback would silently reinterpret stored
     * data — a number field becoming text is how content gets destroyed
     * quietly rather than loudly.
     */
    public function get(string $handle): FieldType
    {
        return $this->types[$handle] ?? throw new RuntimeException(
            "No field type registered for [{$handle}]. Available: ".implode(', ', array_keys($this->types)).'.'
        );
    }

    public function has(string $handle): bool
    {
        return isset($this->types[$handle]);
    }

    /** @return array<string, FieldType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, string> handle => label, for a select */
    public function options(): array
    {
        return array_map(static fn (FieldType $t): string => $t::label(), $this->types);
    }

    /** @return array<string, FieldType> */
    public function indexable(): array
    {
        return array_filter($this->types, static fn (FieldType $t): bool => $t->isIndexable());
    }
}
