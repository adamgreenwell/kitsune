<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

/**
 * A value as `CredentialCipher` sealed it: the ciphertext, and the id of the derived key that sealed it.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final readonly class SealedCredential
{
    public function __construct(
        public string $ciphertext,
        public string $keyId,
    ) {}
}
