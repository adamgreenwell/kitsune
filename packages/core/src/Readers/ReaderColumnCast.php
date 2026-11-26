<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Database\Eloquent\Model;

/** @internal Opens only declared Laravel encrypted casts from the host reader table. */
final class ReaderColumnCast
{
    public static function read(#[\SensitiveParameter] mixed $value, ?string $cast): mixed
    {
        if ($value === null || ! in_array($cast, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:object', 'encrypted:json'], true)) {
            return $value;
        }

        // Use Laravel's storage decoder directly. An arbitrary host accessor could read some other column.
        return Model::currentEncrypter()->decrypt($value, false);
    }
}
