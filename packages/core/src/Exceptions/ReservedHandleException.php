<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Exceptions;

use Kitsune\Core\Models\EntryType;
use RuntimeException;

final class ReservedHandleException extends RuntimeException
{
    public function __construct(string $handle)
    {
        parent::__construct(sprintf(
            'Entry type handle [%s] is reserved because it collides with an admin route segment. '
            .'A type named "%s" would make /c/%s/%s ambiguous, and escaping cannot fix that. Reserved: %s.',
            $handle,
            $handle,
            $handle,
            $handle,
            implode(', ', EntryType::RESERVED_HANDLES),
        ));
    }
}
