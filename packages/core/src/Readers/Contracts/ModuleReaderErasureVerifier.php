<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

use Closure;
use Illuminate\Database\Connection;

/**
 * Optional first-party final proof after the host account's deletion callbacks, inside the erasure transaction.
 * Capture exact erased/minimized identities now; the returned closure checks that witness using raw primary reads.
 * It must not rediscover only by a now-detached reader key, mutate authority, or invoke host/model callbacks.
 * Adapters without this capability retain their existing erasure behavior; core does not certify arbitrary modules.
 *
 * @internal First-party privacy integrity only; no public extension contract before v1.2.
 */
interface ModuleReaderErasureVerifier
{
    /** @return Closure():void */
    public function erasureVerification(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): Closure;
}
