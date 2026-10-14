<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

/** JSON storage may reorder object keys (MySQL). Lists and scalar types remain significant. @internal */
final class OrderJson
{
    public static function same(#[\SensitiveParameter] string $left, #[\SensitiveParameter] string $right): bool
    {
        return self::canonical(json_decode($left, true, 16, JSON_THROW_ON_ERROR))
            === self::canonical(json_decode($right, true, 16, JSON_THROW_ON_ERROR));
    }

    private static function canonical(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$child) {
            $child = self::canonical($child);
        }
        unset($child);

        return $value;
    }
}
