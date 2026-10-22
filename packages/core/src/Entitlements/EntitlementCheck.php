<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Tenancy\Context;

/**
 * May this reader reach this? — ADR-040's fail-closed kernel guard, beside scoping and RBAC.
 *
 * ⚠️ NO PARAMETER FOR A READER, A SITE, AN ORG OR A SOURCE. It answers for the reader signed in to this request, on the
 * site in context, now: a consumer cannot ask about somebody else or somewhere else. "Does this order give it" is
 * commerce's own question about its own order, never the gate's.
 *
 * ⚠️ ANY SOURCE. A reader holds an entitlement while any of their rows for it is live, so the query names no source: it
 * reads the unique index's three-column prefix and stops at the first live row. A `first()` and a PHP test would answer
 * for whichever source sorted first.
 *
 * ⚠️ NO MEMO. A gated request costs the reader's load, once per request inside the host's guard, plus one index-prefix
 * read per call (`holdsMany()` reads at most 100 names together), and a grant or a revoke earlier in the same request
 * is seen at once. Never cache the answer beyond the request.
 *
 * ⚠️ NO STAFF BYPASS. An owner holds nothing unless granted: a bypass here would repeat ADR-033's `Gate::before` mistake.
 *
 * A gated route runs `ResolveSiteFromRequest`, then the reader guard's `auth` middleware if it has one, then this — in
 * the priority list, which core arranges (ADR-037, as built).
 * False means refuse; `EntitlementUnavailable` means an error page, never a paywall.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementCheck
{
    public const BATCH_LIMIT = 100;

    public function __construct(private readonly Context $context, private readonly ReaderGuard $readers) {}

    /**
     * Does the reader signed in to this request hold this entitlement on the site in context, now, from any source?
     * False for every doubtful case. Never true on a database error: it throws instead.
     *
     * @throws EntitlementUnavailable
     */
    public function holds(string $entitlement): bool
    {
        if (! EntitlementName::isName($entitlement)) {
            return false;
        }

        try {
            // Includes the guard's first reader-table load in the same sanitized exception mapping.
            return (new EntitlementRead($this->context, $this->readers))->current($entitlement)?->exists() ?? false;
        } catch (QueryException $e) {
            throw EntitlementUnavailable::because($entitlement, (string) ($e->errorInfo[0] ?? $e->getCode()));
        }
    }

    /**
     * The same reader, site and instant for a bounded list of names, through one read of every live source.
     *
     * The bound counts raw items, before duplicates are coalesced. Every item must be a valid name, so output keys
     * always contain a dot and PHP cannot turn a numeric-looking malformed name into an integer array key. Doubtful
     * context still answers false; doubtful input is a caller error, refused before the reader guard or a query.
     * Distinct names retain their first occurrence's order. An empty list does no work.
     *
     * @param  array<mixed>  $entitlements
     * @return array<string, bool>
     *
     * @throws InvalidArgumentException
     * @throws EntitlementUnavailable
     */
    public function holdsMany(#[\SensitiveParameter] array $entitlements): array
    {
        if (! array_is_list($entitlements) || count($entitlements) > self::BATCH_LIMIT) {
            throw new InvalidArgumentException('An entitlement batch must be a list of at most 100 valid entitlement names.');
        }

        $answers = [];

        foreach ($entitlements as $entitlement) {
            if (! is_string($entitlement) || ! EntitlementName::isName($entitlement)) {
                throw new InvalidArgumentException('An entitlement batch must be a list of at most 100 valid entitlement names.');
            }

            $answers[$entitlement] = false;
        }

        if ($answers === []) {
            return [];
        }

        try {
            $read = (new EntitlementRead($this->context, $this->readers))->current(array_keys($answers));

            if ($read === null) {
                return $answers;
            }

            $held = $read
                // Never hydrate sources, reader identifiers or one row per source: at most 100 names leave SQL.
                ->distinct()
                ->pluck('entitlements.entitlement');

            foreach ($held as $entitlement) {
                $answers[$entitlement] = true;
            }

            return $answers;
        } catch (QueryException $e) {
            throw EntitlementUnavailable::forBatch((string) ($e->errorInfo[0] ?? $e->getCode()));
        }
    }
}
