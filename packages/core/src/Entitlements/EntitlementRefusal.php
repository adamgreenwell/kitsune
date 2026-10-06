<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/**
 * Why a reader's access was not changed, exported or erased — `EntitlementRefused::$reason`, for a caller that
 * branches on the reason and never on the text.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum EntitlementRefusal
{
    case NoSiteContext;
    case SiteGone;
    case NoOrgContext;
    case NotAnOwner;
    case ReaderActing;
    case NoReaderGuard;
    case NotAName;
    case NotASource;
    case ReservedSource;
    case NotAReader;
    case UnknownReader;
    case AlreadyEnded;
    case TooFar;
    case Race;
    case Database;
    case Cancelled;
}
