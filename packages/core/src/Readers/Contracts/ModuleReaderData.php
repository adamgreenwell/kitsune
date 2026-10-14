<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

use Illuminate\Database\Connection;

/**
 * A first-party module's contribution to reader export and erasure, independent of its runtime enablement.
 *
 * The caller has authorised the operation and canonicalised the reader's key. Every query must use this connection,
 * explicitly fence this org, and compare the reader's stored bytes. Export only the reader's personal records, never
 * credentials, bearer secrets or ownership hashes. Erase includes expired retained rows; the caller owns the enclosing
 * transaction and deletes the account last. A missing or incompatible schema refuses rather than pretends to be empty.
 *
 * @internal First-party only; no public module or extension contract before v1.2.
 */
interface ModuleReaderData
{
    /** @return array<string, mixed> an explicit portable projection, never raw models or rows */
    public function export(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): array;

    /** How many owned parent records were erased. Child rows may follow their checked foreign-key cascade. */
    public function erase(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): int;
}
