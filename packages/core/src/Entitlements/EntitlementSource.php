<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

/**
 * Why a reader holds an entitlement — the producer's stable name for the cause, and the one encoding of its rule:
 * `commerce.order:4821`, `import.legacy:batch-7`, an owner's `core.comp` — ADR-040, as amended by Adam on 2026-10-06.
 *
 * ⚠️ PART OF THE KEY, so every source holds its own row: a refund revokes exactly the row its order created, and an
 * owner's comp beside it is untouched.
 *
 * ⚠️ THE CONTRACT A PRODUCER KEEPS:
 *
 * 1. **It names the cause, never the event** — the same string on every replay and on every event about the same cause.
 *    Commerce's is `commerce.order:{order id}`, never a payment provider's event id: one order under two sources is two
 *    rows, and a refund would revoke one of them, which fails OPEN.
 * 2. **It never names the reader.** `import.legacy:user-812` would put a reader id where none may go.
 * 3. **It is not secret, but it is personal data**: joined to commerce's orders, a reference names the buyer. No message
 *    repeats it and no audit row holds it.
 *
 * Core never resolves a source: it does not join commerce's tables, check that order 4821 exists, or parse the
 * reference. It is shape-checked and compared by bytes, as a name is. `core.` is reserved for Kitsune's own sources;
 * in this slice there is exactly one, `COMP`, written only by `EntitlementWriter::comp()`.
 *
 * Separate from `EntitlementName` and from `CredentialSlot`'s name although the producer half resembles both: three
 * populations with different reservations, any of which may change without the others.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementSource
{
    public const LONGEST = 100;

    /** An owner's grant by hand. The only core source in this slice, written only by `EntitlementWriter::comp()`. */
    public const COMP = 'core.comp';

    /** Kitsune's own sources begin with this; `EntitlementWriter::grant()` refuses them. */
    public const RESERVED = 'core.';

    /** The producer (two lower-case kebab words, the second letter-led), then optionally ':' and a 1–64 character reference. */
    private const SHAPE = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?::[A-Za-z0-9_-]{1,64})?$/D';

    public static function isSource(string $source): bool
    {
        return strlen($source) <= self::LONGEST && preg_match(self::SHAPE, $source) === 1;
    }

    public static function isReserved(string $source): bool
    {
        return str_starts_with($source, self::RESERVED);
    }
}
