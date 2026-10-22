<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stream a private media file to a staff user who may view its entry — ADR-041.
 *
 * ⚠️ IT AUTHORISES BEFORE IT READS A BYTE, which is the whole reason a private file goes through PHP at all.
 * ADR-041 records what that costs at the ADR-027 floor and takes it anyway, because Kitsune is a fail-closed
 * house and a leaked gated download is worse than a slow product image.
 *
 * ⚠️ THE QUESTION IT ASKS IS THE STAFF ONE ~~, AND THAT IS ADR-041's DELIBERATE GAP MADE VISIBLE~~ — the reader's has a
 * route of its own since ADR-041 decisions 1–4. `EntryPolicy`
 * resolves `entry.{type_handle}.view` against grants that are keyed per ORG (`Permissions::held()` keys on
 * `[$userId, $orgId]`, and even ownership is an `is_owner` role row inside one org). "This reader paid for
 * this download" was not expressible when this was written; ADR-040's entitlements now make it so —
 * `EntitlementCheck::holds()` — and the reader's download route asks it (`Readers\DownloadController`, ADR-041).
 *
 * ⚠️ NO ROUTE-MODEL BINDING, AND THE ORDER OF THE PIPELINE IS WHY. `SubstituteBindings` sits in the panel's
 * OUTER middleware group and `SetKitsuneContext` in the tenant group nested inside it, so bindings resolve
 * BEFORE there is an org or a site in `Context`. A type-hinted `Entry $entry` would therefore be looked up
 * with `SiteScope` contributing `1 = 0` — every request 404s, including the operator's own file, and the
 * symptom looks like a missing file rather than a middleware ordering bug. Filament's own `{tenant:slug}`
 * works precisely because it is bound out there and only READ inside. So the id arrives as a string and the
 * entry is resolved here, where the scope is populated and means what it says.
 *
 * ⚠️ TWO REFUSALS, DELIBERATELY DIFFERENT. Out of scope or absent is a 404 — `SiteScope` already declines to
 * confirm that another site's row exists, and this must not confirm it either. In scope but ungranted is a
 * 403, because the user is inside the panel and the entry is one their listings can show them; answering
 * "not found" for a file that is plainly there sends an operator hunting a storage problem they do not have.
 */
final class MediaDownloadController
{
    /**
     * ⚠️ THE ID IS READ FROM THE ROUTE BY NAME, NOT ACCEPTED AS AN ARGUMENT, and a signed-in owner getting
     * 404 for his own file is what that is worth. Laravel resolves controller parameters that are NOT
     * type-hinted as classes **positionally** rather than by name — so on a route whose parameters are
     * `{tenant:slug}` and `{media}`, a lone `string $media` is handed position zero, which is the TENANT
     * SLUG. `whereKey('golfdom')` then matches nothing and the refusal looks exactly like a missing file.
     *
     * Adding `string $tenant` in front would also work and would break again the moment the route gains a
     * segment. Asking the route for the name is the version that cannot drift.
     */
    public function __invoke(Request $request): Response
    {
        /* Constrained to digits by the route, so this is a numeric string or the router never matched. */
        $media = (string) $request->route('media');

        /*
         * ⚠️ DIGITS ARE NOT ENOUGH, AND THE ENGINES DISAGREE ABOUT WHAT HAPPENS NEXT — AGENTS.md invariant 5. An id of
         * twenty-four nines satisfies `[0-9]+` and would reach `whereKey()`, where PostgreSQL raises SQLSTATE 22003 — a 500.
         * `MediaStream::key()` holds the bound, as text against the column's, for this route and the reader's alike.
         */
        if (MediaStream::key($media) === null) {
            abort(404);
        }

        /*
         * ⚠️ THE SCOPED QUERY IS THE FIRST GATE AND IT RUNS BEFORE THE POLICY. `SiteScope` is populated by now,
         * and the panel's tenant scope with it, so an entry kept to another site — or belonging to another org — is
         * simply not found. An org-shared file IS found, from every site of its org where its type is enabled: that
         * is ADR-042 decision 2, and `EntryResource::scopeEloquentQueryToTenant()` is where it is decided. The policy
         * asks the scope's question again on the instance (`EntryPolicy::storedTypeInScope()`), which is not
         * redundant: the scope constrains the query and says nothing about the object afterwards.
         */
        $entry = Entry::query()->whereKey($media)->first();

        if ($entry === null) {
            abort(404);
        }

        /*
         * ⚠️ ADR-022: A TYPE MAY BELONG TO THIS ORG AND STILL BE DISABLED FOR THIS SITE, and nothing above has
         * asked. `SiteScope` answers "is the row in this site", which is a different question, and
         * `EntryPolicy` resolves `entry.{handle}.view` against grants that are keyed per ORG — so neither
         * notices. The check normally arrives with `IdentifyEntryType`, which this route cannot invoke because
         * it has no `{type}` segment to identify anything from.
         *
         * Without it a granted user fetches an entry's bytes from a site that has switched its type off, while
         * `/c/image` correctly returns 404 there — the same boundary answering two ways depending on which URL you
         * ask. The panel's widened scope already leaves out a SHARED file of a type switched off here; this is what
         * still refuses a file kept to this site whose type the site has since switched off. 404 rather than 403,
         * matching `IdentifyEntryType`: a site that does not carry this type has nothing to say about the row.
         */
        $type = $entry->entryType;

        abort_unless(
            $type instanceof EntryType && EntryTypeAvailability::isEnabledFor($type, app(Context::class)->site()),
            404,
        );

        /* Throws `AuthorizationException` → 403. The entry is in this scope; the grant is what is missing. */
        Gate::authorize('view', $entry);

        $file = MediaDelivery::fileFor($entry);

        if ($file === null) {
            /* A real entry that is not a media entry. Nothing to stream and nothing to report. */
            abort(404);
        }

        /*
         * Open after the staff gates and before success headers. The reader route's shared stream keeps the
         * response bounded under PHP-FPM, closes HEAD immediately and offers the checked international filename
         * with its ASCII fallback. It reads this row's disk/path, including custody's private fallback and legacy
         * disks, and retains the missing-bytes warning before refusing a failed open (ADR-041, #249).
         */
        $handle = MediaStream::open($entry, $file);

        if ($handle === null) {
            abort(404);
        }

        return MediaStream::respond($request, $entry, $file, $handle);
    }
}
