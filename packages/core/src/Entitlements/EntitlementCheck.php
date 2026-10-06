<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Entitlement;
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
 * read per call, and a grant or a revoke earlier in the same request is seen at once. Never cache the answer beyond
 * the request.
 *
 * ⚠️ NO STAFF BYPASS. An owner holds nothing unless granted: a bypass here would repeat ADR-033's `Gate::before` mistake.
 *
 * A gated route runs `ResolveSiteFromRequest`, then the reader guard's `auth` middleware if it has one, then this.
 * False means refuse; `EntitlementUnavailable` means an error page, never a paywall.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementCheck
{
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

        $siteId = $this->context->siteId();
        $orgId = $this->context->orgId();

        if ($siteId === null || $orgId === null) {
            return false;
        }

        try {
            // ⚠️ INSIDE THE `try`: on a route with no auth middleware this is the request's first query on the host's
            // reader table, and a database error there carries the session's reader id.
            $reader = $this->readers->current();

            if ($reader === null) {
                return false;
            }

            return Entitlement::query()
                // The equality the planner leads with: `SiteScope`'s OR alone plans as a scan of every site's prefix.
                ->where('entitlements.site_id', $siteId)
                // A row filed under another org never answers: `SiteScope`'s site branch compares no org.
                ->where('entitlements.org_id', $orgId)
                ->where('entitlements.reader_id', $reader)
                ->where('entitlements.entitlement', $entitlement)
                ->liveAt(CarbonImmutable::now('UTC')->startOfSecond())
                ->exists();
        } catch (QueryException $e) {
            throw EntitlementUnavailable::because($entitlement, (string) ($e->errorInfo[0] ?? $e->getCode()));
        }
    }
}
