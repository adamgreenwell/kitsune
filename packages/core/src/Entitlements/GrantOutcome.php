<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/**
 * What a grant or a comp did to its own source's row — ADR-040. Per source: `Unchanged` means THIS source already gives
 * as much, and whether the reader holds the entitlement at all is `EntitlementCheck::holds()`'s question.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum GrantOutcome
{
    /** This source had no row: one is written. */
    case Granted;

    /** This source's end moved later, or to no end — a lapsed row of this source included. */
    case Extended;

    /** `comp()` only: the revoked comp is live again, with the end given now, exactly. */
    case Reinstated;

    /** This source already gives as much or more: nothing written, nothing recorded. */
    case Unchanged;

    /**
     * `grant()` only: this source was revoked, and stays revoked — a refunded order replayed. Nothing written, nothing
     * recorded. Not a refusal: a webhook handler marks its event processed.
     */
    case StillRevoked;
}
