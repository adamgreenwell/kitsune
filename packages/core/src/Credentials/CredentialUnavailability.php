<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * Why a credential could not be read — `CredentialUnavailable::$reason`. A consumer fails closed on every one of them.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum CredentialUnavailability
{
    case NoOrgContext;
    case UnknownSlot;
    case NotSet;
    case Unreadable;
    case Misfiled;
    case WrongMode;
    case WrongShape;
}
