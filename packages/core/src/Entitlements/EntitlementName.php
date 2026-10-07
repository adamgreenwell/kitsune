<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/**
 * What an entitlement is called, and the one encoding of that rule — ADR-040.
 *
 * ⚠️ `Permissions::validated()`'s DISCIPLINE, NOT ITS REGEX. The permission grammar needs `entry.{type}.{action}` and
 * refuses ADR-040's own examples, and its type segment lacks `/D`. What carries over is the design: one encoding, loud
 * on a write (`EntitlementWriter` refuses `NotAName`), quiet on a check (`EntitlementCheck::holds()` answers false
 * before any query), shape checked and existence never. There is no registry: a name nobody has declared is granted and
 * held, which is how an offer and a course arrive in either order.
 *
 * ⚠️ NOT `CredentialSlot`'s NAME either. That grammar gives the first word a module meaning, reserves `core.` and refuses
 * a digit-led second word such as `issue.2026-10`; sharing it would let a credential change re-shape entitlements.
 * Kebab only, no underscore: each extra spelling is a near-miss pair that fails closed and invisibly.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementName
{
    public const LONGEST = 100;

    /** Two lower-case words joined by one dot; letters, digits, single hyphens; the first word starts with a letter. */
    private const SHAPE = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public static function isName(string $name): bool
    {
        return strlen($name) <= self::LONGEST && preg_match(self::SHAPE, $name) === 1;
    }
}
