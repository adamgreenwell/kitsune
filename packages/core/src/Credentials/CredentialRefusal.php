<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * Why a credential was not written — `CredentialRefused::$reason`, for a caller that branches on the reason and never
 * on the text.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum CredentialRefusal
{
    case NoOrgContext;
    case NotAnOwner;
    case NotAName;
    case UnknownSlot;
    case ModeRequired;
    case ModeNotTaken;
    case Empty;
    case Characters;
    case TooShort;
    case TooLong;
    case OtherMode;
    case Shape;
    case NoAppKey;
    case Race;
    case Database;
    case Cancelled;
}
