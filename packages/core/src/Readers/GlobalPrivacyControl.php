<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Http\Request;

/** @internal W3C Global Privacy Control draft §3.3. A valid value takes precedence over invalid duplicates. */
final class GlobalPrivacyControl
{
    public static function requested(Request $request): bool
    {
        if ($request->attributes->has(self::class)) {
            return $request->attributes->get(self::class) === true;
        }

        foreach ($request->headers->all('sec-gpc') as $field) {
            // Proxies can join separate field lines into a comma-separated value.
            foreach (explode(',', $field) as $value) {
                if (trim($value, " \t") === '1') {
                    $request->attributes->set(self::class, true);

                    return true;
                }
            }
        }

        $request->attributes->set(self::class, false);

        return false;
    }
}
