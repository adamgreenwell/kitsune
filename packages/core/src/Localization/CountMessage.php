<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Localization;

use InvalidArgumentException;

/**
 * Integer cardinal copy in Laravel's existing catalog format (ADR-018).
 *
 * @internal Not an extension API. Fractions and ordinal grammar must never
 * silently pass through an integer cardinal selector.
 */
final class CountMessage
{
    /** @param array<string, int|string> $replace */
    public static function format(string $key, mixed $count, array $replace = [], ?string $locale = null): string
    {
        if (! is_int($count) || $count < 0) {
            throw new InvalidArgumentException('A counted product message requires a nonnegative integer.');
        }

        return trans_choice($key, $count, [...$replace, 'count' => $count], $locale);
    }
}
