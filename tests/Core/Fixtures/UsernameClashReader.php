<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use PDOException;

/**
 * The skeleton's reader on a host whose table has another unique column — a user name made from the address — that a
 * new address can collide on. Its insert is refused with a unique violation though no account has the address.
 */
#[OrgScoped]
class UsernameClashReader extends Reader
{
    public static function createReader(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] ?string $passwordHash,
        ?DateTimeInterface $verifiedAt,
    ): static {
        $refused = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: readers.username');
        $refused->errorInfo = ['23000', 19, 'UNIQUE constraint failed: readers.username'];

        throw new UniqueConstraintViolationException('testing', 'insert into "readers" ("username") values (?)', [$email], $refused);
    }
}
