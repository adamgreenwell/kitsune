<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use Throwable;

/** A bounded locator, not an authorization capability or a promise of a cross-page snapshot. @internal */
final class ExportCursor
{
    public const MAX_BYTES = 1024;

    public static function encode(int $org, ?int $site, bool $billing, int $after, int $upper): string
    {
        if ($org < 1 || ($site !== null && $site < 1) || $after < 0 || $upper < $after) {
            throw new ExportRefused;
        }

        return rtrim(strtr(base64_encode(json_encode(['version' => 1, 'org' => $org, 'site' => $site,
            'billing' => $billing, 'after' => $after, 'upper' => $upper], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{after:int,upper:int} */
    public static function decode(string $cursor, int $org, ?int $site, bool $billing): array
    {
        try {
            if ($cursor === '' || strlen($cursor) > self::MAX_BYTES || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
                throw new ExportRefused;
            }
            $json = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($json === false) {
                throw new ExportRefused;
            }
            $values = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            if (! is_array($values) || array_keys($values) !== ['version', 'org', 'site', 'billing', 'after', 'upper']
                || $values['version'] !== 1 || $values['org'] !== $org || $values['site'] !== $site || $values['billing'] !== $billing
                || ! is_int($values['after']) || ! is_int($values['upper'])
                || self::encode($org, $site, $billing, $values['after'], $values['upper']) !== $cursor) {
                throw new ExportRefused;
            }

            return ['after' => $values['after'], 'upper' => $values['upper']];
        } catch (Throwable) {
            throw new ExportRefused;
        }
    }
}
