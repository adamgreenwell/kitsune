<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use PDO;

/** Private registry evidence; constructing this value does not register or authenticate any proof. @internal */
final readonly class CredentialAuthenticationState
{
    public function __construct(
        public int $org,
        public ?int $site,
        public string $actorClass,
        public int|string $actorKey,
        public string $actorTable,
        public Connection $connection,
        public DatabaseTransactionRecord $transaction,
        public PDO $pdo,
        public string $column,
        public string $hash,
        public bool $passwordRequired,
    ) {}

    public function sameAs(self $other): bool
    {
        return (array) $this === (array) $other;
    }
}
