<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/** One exact purchased source's adjustment, never the reader's access through other sources. @internal */
enum VerifiedOrderOutcome
{
    case Missing;
    case Unchanged;
    case Revised;
    case Revoked;
    case StillRevoked;
    case Reinstated;
    case Expired;
}
