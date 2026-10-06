<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use RuntimeException;

/**
 * Whether the reader holds an entitlement could not be read, because the database refused — ADR-040.
 *
 * ⚠️ NEVER AN ANSWER. `false` would show a paywall during an outage and invite a paying reader to pay again; `true` is
 * unthinkable. The reader sees an error page.
 *
 * ⚠️ NEVER CHAINED. A database exception interpolates its bindings, and they hold the reader's identifier: this carries
 * the SQLSTATE alone, and the name, which identifies nobody.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementUnavailable extends RuntimeException
{
    private function __construct(public readonly string $entitlement, public readonly string $state)
    {
        parent::__construct(sprintf(
            'Whether the reader holds [%s] could not be read: the database refused the query (SQLSTATE %s). Its message '
            .'is not repeated, because it carries the reader\'s identifier. Nothing was granted.',
            $entitlement,
            $state,
        ));
    }

    /** @param  string  $entitlement  well-formed: the check answers false before any query for anything else */
    public static function because(string $entitlement, string $state): self
    {
        return new self($entitlement, $state);
    }
}
