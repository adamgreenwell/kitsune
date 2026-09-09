<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Illuminate\Support\Carbon;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

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

    /**
     * Rendered from a normalised ISO-8601 string in the site timezone, so the glyphs
     * are the app's rather than the author's.
     */
    public function control(): Control
    {
        return Control::Date;
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function projection(FieldConfig $config): Projection
    {
        return new Projection(LogicalType::Date, 10);
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        $date = $input instanceof Carbon ? $input : Carbon::parse((string) $input);

        return $date->toDateString();
    }

    protected function castFromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return $stored === null ? null : Carbon::parse((string) $stored);
    }

    /** Serialised form is the stored string, not a Carbon instance. */
    public function toApi(mixed $stored, FieldConfig $config): mixed
    {
        return $stored;
    }

    /** @return array<string, mixed> */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => 'string', 'format' => 'date'];
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return ['date'];
    }
}
