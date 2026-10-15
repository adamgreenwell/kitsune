<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use RuntimeException;

/** One payload-free refusal for missing, unsupported, incorrect or stale fresh authentication. @internal */
final class CredentialAuthenticationRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Fresh owner authentication is required for this credential change.');
    }
}
