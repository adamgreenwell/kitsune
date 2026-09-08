<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;

/**
 * Plain multi-line text. Not indexable: a generated column over a long free
 * text value costs disk for a projection nobody filters on.
 */
final class TextareaType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'textarea';
    }

    public static function label(): string
    {
        return 'Text area';
    }

    public static function icon(): string
    {
        return 'heroicon-o-bars-4';
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : (string) $input;
    }

    /** @return array<int, mixed> */
    /**
     * The 65,535-character limit is PUBLISHED, not only enforced.
     *
     * It inherited `{"type": "string"}` from the base type, so a generated
     * client accepted a value this field rejects (AGENTS.md invariant 14).
     *
     * @return array<string, mixed>
     */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => 'string', 'maxLength' => $this->length($config)];
    }

    protected function scalarValidationRules(FieldConfig $config): array
    {
        return ['string', 'max:'.$this->length($config)];
    }

    /** One source for the limit, so the rule and the schema cannot drift. */
    private function length(FieldConfig $config): int
    {
        return (int) $config->setting('maxLength', 65535);
    }
}
