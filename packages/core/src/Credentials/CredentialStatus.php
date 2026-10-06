<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * What a credential's row says, read without decrypting it — `CredentialStates`.
 *
 * ⚠️ `Set` IS NOT A VERDICT. It means the row was sealed under the current key; a row corrupted under that key still
 * reads `Set` and fails when it is opened, as `Unreadable`.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum CredentialStatus
{
    case NotSet;
    case Removed;
    case Set;
    case SetUnderPreviousKey;
    case Unreadable;
}
