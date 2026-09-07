<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use RuntimeException;

/**
 * The escape hatch, and escape hatches get abused.
 *
 * Documented as "for machine-readable configuration, not content", because
 * otherwise it becomes the CMS equivalent of a `misc` column. Not indexable
 * and not queryable on purpose: if you want to query it, you wanted a real
 * field type.
 */
final class JsonType extends BaseFieldType
{
    /** Server-side, because a client-side cap is a suggestion. */
    public const MAX_BYTES = 65_536;

    public static function handle(): string
    {
        return 'json';
    }

    public static function label(): string
    {
        return 'JSON';
    }

    public static function icon(): string
    {
        return 'heroicon-o-code-bracket';
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        $decoded = is_string($input) ? json_decode($input, true) : $input;

        if (is_string($input) && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Value is not valid JSON: '.json_last_error_msg());
        }

        $encoded = json_encode($decoded);

        if ($encoded !== false && strlen($encoded) > self::MAX_BYTES) {
            throw new RuntimeException(
                'JSON value exceeds '.self::MAX_BYTES.' bytes. This field is for machine-readable '
                .'configuration, not content — a value this large probably wants a real field type.'
            );
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'object'];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'json'];
    }
}
