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
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

/**
 * Date and time.
 *
 * Stored UTC, displayed in the site's configured timezone. Storing local
 * time is how a scheduled post fires an hour early twice a year — the
 * conversion belongs at the edges, not in storage.
 */
final class DateTimeType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'datetime';
    }

    public static function label(): string
    {
        return 'Date and time';
    }

    public static function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function projection(FieldConfig $config): Projection
    {
        return new Projection(LogicalType::DateTime, 32);
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        $date = $input instanceof Carbon ? $input : Carbon::parse((string) $input);

        // ⚠️ NOT toIso8601String(), which emits whole seconds only. An
        // accepted `03:04:05.123456Z` was stored as `03:04:05+00:00` — the
        // published schema says `date-time`, which permits a fraction, so the
        // advertised round trip was lossy.
        //
        // Always six digits, never only when present. This projects to a
        // VARCHAR and is compared as text, so a mixed-width column would sort
        // `05+00:00` and `05.5+00:00` by their punctuation. A fixed width
        // keeps lexicographic order and chronological order the same thing,
        // and 32 characters is exactly what it needs.
        return $date->utc()->format('Y-m-d\\TH:i:s.uP');
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
        return ['type' => 'string', 'format' => 'date-time'];
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return ['date'];
    }
}
