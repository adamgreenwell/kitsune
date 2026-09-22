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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream a private media file to a staff user who may view its entry — ADR-041.
 *
 * ⚠️ IT AUTHORISES BEFORE IT READS A BYTE, which is the whole reason a private file goes through PHP at all.
 * ADR-041 records what that costs at the ADR-027 floor and takes it anyway, because Kitsune is a fail-closed
 * house and a leaked gated download is worse than a slow product image.
 *
 * ⚠️ THE QUESTION IT ASKS IS THE STAFF ONE, AND THAT IS ADR-041's DELIBERATE GAP MADE VISIBLE. `EntryPolicy`
 * resolves `entry.{type_handle}.view` against grants that are keyed per ORG (`Permissions::held()` keys on
 * `[$userId, $orgId]`, and even ownership is an `is_owner` role row inside one org). "This reader paid for
 * this download" is not expressible yet; ADR-040's entitlements make it so, inside v1.0.
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
    public function __invoke(Request $request): StreamedResponse
    {
        /* Constrained to digits by the route, so this is a numeric string or the router never matched. */
        $media = (string) $request->route('media');

        /*
         * ⚠️ DIGITS ARE NOT ENOUGH, AND THE ENGINES DISAGREE ABOUT WHAT HAPPENS NEXT — AGENTS.md invariant 5.
         * `/media/999999999999999999999999` satisfies `[0-9]+` and reaches `whereKey()`, where PostgreSQL
         * refuses to coerce it to the `bigint` key and raises SQLSTATE 22003, which surfaces as a 500 rather
         * than the 404 every other unknown id gets. MySQL and MariaDB return no rows and say nothing, and
         * SQLite agrees with them — so a suite that skipped PostgreSQL would call this fixed.
         *
         * The bound is the COLUMN's, not PHP's: `PHP_INT_MAX` happens to match on a 64-bit build and would
         * silently narrow this on a 32-bit one, which is the kind of agreement that holds until it does not.
         */
        if (! self::namesARepresentableKey($media)) {
            abort(404);
        }

        /*
         * ⚠️ THE SCOPED QUERY IS THE FIRST GATE AND IT RUNS BEFORE THE POLICY. `SiteScope` is populated by now,
         * so an entry belonging to another site — or to another org — is simply not found. The policy asks the
         * same question again on the instance (`EntryPolicy::storedTypeInScope()`), which is not redundant:
         * the scope constrains the query and says nothing about the object afterwards.
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
         * Without it a granted user fetches a shared media entry's bytes from a site that has switched the
         * `image` type off, while `/c/image` correctly returns 404 there — the same boundary answering two
         * ways depending on which URL you ask. 404 rather than 403, matching `IdentifyEntryType`: a site that
         * does not carry this type has nothing to say about the row.
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

        $disk = Storage::disk((string) $file->disk);

        /*
         * ⚠️ A ROW WITHOUT ITS BYTES IS A 404 AND A LOG LINE, NEVER A 500. This is the residue ADR-041 accepts
         * on purpose in the other direction: `MediaLibrary` writes bytes before rows and `MediaDisposal`
         * removes rows before bytes, so the state this branch describes — a row pointing at nothing — is the
         * one the project chose to make impossible rather than merely unlikely. If it happens anyway, it is an
         * operator's problem to find, so it is named in the log rather than raised as a stack trace at a
         * viewer.
         */
        if (! $disk->exists((string) $file->path)) {
            Log::warning(sprintf(
                'Kitsune has a media_files row whose bytes are missing: [%s:%s] for entry %s. This is the '
                .'state the write and disposal orders were chosen to avoid, so it suggests the file was '
                .'removed outside Kitsune. `kitsune:media-prune` reports the opposite case, files no row claims.',
                (string) $file->disk,
                (string) $file->path,
                (string) $entry->getKey(),
            ));

            abort(404);
        }

        return $disk->response(
            (string) $file->path,
            MediaDelivery::filenameFor($entry, $file),
            MediaDelivery::headersFor($file),
            MediaDelivery::dispositionFor($file),
        );
    }

    /**
     * Could the primary key column hold this, as a string of digits?
     *
     * Compared as text against the signed 64-bit maximum every engine Kitsune supports uses for a `bigint`
     * key, so the answer does not depend on PHP's own word size. Leading zeros are refused rather than
     * trimmed: `007` and `7` would otherwise be two URLs for one file.
     */
    private static function namesARepresentableKey(string $media): bool
    {
        $max = '9223372036854775807';

        if ($media === '' || ($media !== '0' && $media[0] === '0')) {
            return false;
        }

        return strlen($media) < strlen($max)
            || (strlen($media) === strlen($max) && strcmp($media, $max) <= 0);
    }
}
