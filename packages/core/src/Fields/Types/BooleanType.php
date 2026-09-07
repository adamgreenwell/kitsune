<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

final class BooleanType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'boolean';
    }

    public static function label(): string
    {
        return 'Yes / no';
    }

    public static function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    /** Single-valued by nature: a list of booleans is a different field. */
    public function supportsCardinality(): bool
    {
        return false;
    }

    public function projection(): Projection
    {
        return new Projection(LogicalType::Boolean);
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : (bool) $input;
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'boolean'];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'boolean'];
    }
}
