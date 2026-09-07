<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Illuminate\Support\Carbon;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Schema\SchemaDriver;

/**
 * Date.
 */
final class DateType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'date';
    }

    public static function label(): string
    {
        return 'Date';
    }

    public static function icon(): string
    {
        return 'heroicon-o-calendar';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function generatedColumnType(SchemaDriver $driver): string
    {
        return $driver->sqlType('date');
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        $date = $input instanceof Carbon ? $input : Carbon::parse((string) $input);

        return $date->toDateString();
    }

    public function fromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return $stored === null ? null : Carbon::parse((string) $stored);
    }

    /** Serialised form is the stored string, not a Carbon instance. */
    public function toApi(mixed $stored, FieldConfig $config): mixed
    {
        return $stored;
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'string', 'format' => 'date'];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'date'];
    }
}
