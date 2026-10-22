<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;

/**
 * The kernel's live sources, only for the reader and Site in this request, now. Both the single/batch check and the
 * private-offer list build from this same fence; no caller supplies identity, scope or time. No memo and no hydration.
 * Callers map QueryException themselves, inside the same try as the guard's reader load.
 *
 * @internal First-party modules only; no public extension contract.
 */
final class EntitlementRead
{
    public function __construct(private readonly Context $context, private readonly ReaderGuard $readers) {}

    /**
     * @param  string|list<string>|null  $names  validated caller names, or all live names for the internal candidate query
     * @return Builder<Entitlement>|null null means doubtful context or no current reader
     */
    public function current(string|array|null $names = null): ?Builder
    {
        $siteId = $this->context->siteId();
        $orgId = $this->context->orgId();

        if ($siteId === null || $orgId === null) {
            return null;
        }

        $reader = $this->readers->current();

        if ($reader === null) {
            return null;
        }

        $query = Entitlement::query()
            ->where('entitlements.site_id', $siteId)
            ->where('entitlements.org_id', $orgId)
            ->where('entitlements.reader_id', $reader);

        if (is_string($names)) {
            $query->where('entitlements.entitlement', $names);
        } elseif (is_array($names)) {
            $query->whereIn('entitlements.entitlement', $names);
        }

        return $query->liveAt(CarbonImmutable::now('UTC')->startOfSecond());
    }
}
