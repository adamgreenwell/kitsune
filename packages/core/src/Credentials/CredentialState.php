<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Carbon\CarbonImmutable;

/**
 * One credential line as its row describes it, read without decrypting anything — `CredentialStates::of()`.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final readonly class CredentialState
{
    public function __construct(
        public CredentialStatus $status,
        public ?CarbonImmutable $changedAt,
        public ?int $rowId,
    ) {}
}
