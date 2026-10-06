<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * Which of an org's credentials are in force — ADR-040's test and live mode.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum CredentialMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function other(): self
    {
        return $this === self::Test ? self::Live : self::Test;
    }
}
