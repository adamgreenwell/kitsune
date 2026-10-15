<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use JsonSerializable;

/** An opaque, transaction-local proof. Constructing one earns no authority; only confirm() registers it. @internal */
final class CredentialAuthentication implements JsonSerializable
{
    public function assertCurrent(): void
    {
        app(FreshCredentialAuthentication::class)->assertCurrent($this);
    }

    /** @return array{proof: string} */
    public function __debugInfo(): array
    {
        return ['proof' => '[withheld]'];
    }

    public function jsonSerialize(): string
    {
        return '[withheld]';
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new CredentialAuthenticationRefused;
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new CredentialAuthenticationRefused;
    }

    public function __clone()
    {
        throw new CredentialAuthenticationRefused;
    }
}
