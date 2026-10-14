<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use InvalidArgumentException;

/** Bounded scalar inputs for calculator configuration; country/region grammar makes no registry claim. @internal */
final class CommerceSetupValues
{
    public const MAX_VERSION = '4294967295';

    /** @param array<array-key, mixed> $values
     * @param  list<string>  $allowed
     */
    public static function keys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException('Commerce setup accepts only its named configuration fields.');
        }
    }

    public static function integer(mixed $value, string $maximum, bool $zero = false): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > strlen($maximum)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || (! $zero && $value === '0') || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new InvalidArgumentException('A commerce configuration integer is missing, noncanonical or outside its supported range.');
        }

        return $value;
    }

    public static function taxClass(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('A tax class must be 1–64 lowercase ASCII letters, digits, underscores or hyphens, beginning with a letter.');
        }

        return $value;
    }

    public static function country(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Z]{2}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('A tax country must be two uppercase ASCII letters.');
        }

        return $value;
    }

    public static function region(mixed $value): string
    {
        if (! is_string($value) || ($value !== '*' && preg_match('/\A[A-Z0-9][A-Z0-9-]{0,31}\z/D', $value) !== 1)) {
            throw new InvalidArgumentException('A tax region must be an uppercase ASCII code of at most 32 letters, digits or hyphens, or an explicit wildcard.');
        }

        return $value;
    }
}
