<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use DateTimeInterface;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * A reader account whose keys are strings — an SSO subject such as `auth0|abc123`, or anything printable — on
 * `test_ulid_readers`: `TestUlidReader` with the contract core reads an account through.
 */
#[OrgScoped]
class StringKeyedReaderAccount extends TestUlidReader implements ReaderAccount
{
    public static function findByEmail(#[\SensitiveParameter] string $email): ?static
    {
        return static::query()->where('email', $email)->first();
    }

    public static function createReader(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] ?string $passwordHash,
        ?DateTimeInterface $verifiedAt,
    ): static {
        return static::query()->forceCreate(['email' => $email]);
    }

    public function readerEmail(): string
    {
        return (string) $this->getAttribute('email');
    }

    /** @return array<string, scalar|null> */
    public function exportAccount(): array
    {
        return ['reader' => (string) $this->getKey(), 'email' => $this->readerEmail()];
    }

    public function eraseAccount(): void
    {
        $this->delete();
    }
}
