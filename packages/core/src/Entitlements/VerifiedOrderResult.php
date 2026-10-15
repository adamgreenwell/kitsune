<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/** A source's persisted write identity; only Revoked warrants recording a newly owned suspension. @internal */
final readonly class VerifiedOrderResult
{
    /** @param array{id:string,expires_at:?string,revoked_at:?string,changed_at:string,change_token:string}|null $witness */
    public function __construct(public VerifiedOrderOutcome $outcome, public ?array $witness) {}
}
