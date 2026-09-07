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
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'string', 'max:'.$config->setting('maxLength', 65535)];
    }
}
