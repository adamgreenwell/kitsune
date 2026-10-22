<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Tenancy\Context;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file offered to readers, to a reader who holds its name on this site now — ADR-041, the reader's download route
 * (decisions 1–4, Adam, 2026-10-11). `GET {prefix}/account/downloads/{id}`, a page that names the file and links to it,
 * and `…/{id}/file`, the bytes; both behind `ReaderArea:download`, `IsolateDownload` and `RequireReader`, so a guest has
 * already been sent to sign in, the same way for every id, before anything here runs.
 *
 * Both, in this order: the site → the id as the key column can hold it → `MediaStream::gatedOnSite()` →
 * `typeEnabled()` → `holds()` the file's name, or the decoy → the file's row, only if all of that said yes. Then the
 * page checks the bytes are there; the file opens them, and streams.
 *
 * ⚠️ ONE ANSWER FOR EVERY REFUSAL: the same 404 notice, after the same reads (`MediaStream`'s docblock says why the decoy
 * is not dead work). An unknown id, a file offered to nobody or for a name the reader does not hold, a lapsed or revoked
 * grant, a trashed entry, a type this site has switched off, another site's file, another org's — the reader is told
 * this is not theirs to open, and links to their account. The copy fits every cause and invites nobody to pay.
 *
 * ⚠️ A DATABASE REFUSAL IS A 503 NOTICE, never a 404 that would read as "not yours" during an outage, and never a paywall
 * (ADR-040: `EntitlementUnavailable` is an error page). `MediaStream::unavailable()` writes its SQLSTATE and nothing
 * else; nothing is passed to `report()`, whose report would carry the exception and the handler's `userId`.
 *
 * ⚠️ NO STAFF PREVIEW AND NO BYPASS. An owner signed in to the admin is not a reader here, and holds nothing unless
 * granted (ADR-040). Nothing is recorded and nothing is audited: readers stay out of audit rows.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class DownloadController
{
    /** The route's parameter: the entry's id, untrusted until `MediaStream::key()` has read it (AGENTS.md §6). */
    public const PARAMETER = 'id';

    public function __construct(
        private readonly Context $context,
        private readonly EntitlementCheck $check,
    ) {}

    /**
     * The landing page: the file's title — or "Untitled file" when it has none to show — its format and size, and one
     * link: Open or Download, as the file is sent.
     */
    public function show(Request $request): Response
    {
        $site = $this->site();
        $found = $this->offered($request, $site);

        if ($found instanceof Response) {
            return $found;
        }

        [$entry, $file] = $found;

        // ⚠️ AFTER THE YES: a non-holder's answer never depends on the disk. A holder is not offered a link that would 404.
        if (! MediaStream::present($entry, $file)) {
            return $this->refused($site);
        }

        $extension = pathinfo((string) $file->path, PATHINFO_EXTENSION);
        $title = is_string($entry->title) ? $entry->title : '';

        return ReaderPage::render($site, 'download', 'download.title', [
            // None for a title that is empty or only space — Unicode's too — so the page says "Untitled file" instead.
            'fileTitle' => Str::trim($title) !== '' ? $title : null,
            'format' => $extension !== '' ? strtoupper($extension) : (string) $file->mime,
            'size' => Number::withLocale(ReaderPage::copyLocale(), static fn (): string => (string) Number::fileSize(max(0, (int) $file->size_bytes), maxPrecision: 1)),
            'href' => ReaderLinks::downloadFile($site, (int) $entry->getKey()),
            // The word matches what the browser will do with it: an image listed `INLINE` opens, anything else downloads.
            'action' => MediaDelivery::dispositionFor($file) === 'inline' ? 'download.open' : 'download.download',
        ]);
    }

    /** The bytes, opened before any answer is built, then streamed. */
    public function file(Request $request): Response
    {
        $site = $this->site();
        $found = $this->offered($request, $site);

        if ($found instanceof Response) {
            return $found;
        }

        [$entry, $file] = $found;

        // ⚠️ AFTER THE YES, as the page's check is: a non-holder causes no disk read.
        try {
            $handle = MediaStream::openCurrent($entry, $file);
        } catch (PDOException $refused) {
            return $this->unavailable($site, ReaderAccounts::sqlstate($refused));
        }

        if ($handle === null) {
            return $this->refused($site);
        }

        return MediaStream::respond($request, $entry, $file, $handle);
    }

    /**
     * The entry and its file when the reader may have them; otherwise the answer to give instead.
     *
     * ⚠️ THE SAME READS IN THE SAME ORDER, WHATEVER THE ID NAMES. `typeEnabled()` is asked whether or not an entry was
     * found, and `holds()` is asked the decoy when none was; only the file's own row waits for a yes. Reordering these, or
     * returning early from one of them, is how the count of queries starts saying which files exist.
     *
     * @return array{0: Entry, 1: MediaFile}|Response
     */
    private function offered(Request $request, Site $site): array|Response
    {
        $id = MediaStream::key($request->route(self::PARAMETER));

        if ($id === null) {
            return $this->refused($site);
        }

        try {
            $found = MediaStream::gatedOnSite($site, $id);
            $enabled = MediaStream::typeEnabled($site, $found !== null ? $found[0]->entry_type_id : null);
            $held = $this->check->holds($found !== null ? $found[1] : MediaStream::NOTHING);

            if ($found === null || ! $enabled || ! $held) {
                return $this->refused($site);
            }

            [$entry, $gate] = $found;
            $file = MediaDelivery::fileFor($entry);
        } catch (EntitlementUnavailable $unavailable) {
            return $this->unavailable($site, $unavailable->state);
        } catch (QueryException $refused) {
            return $this->unavailable($site, ReaderAccounts::sqlstate($refused));
        }

        // The row the lookup joined, read again by its entry: still private, and still offered for the name just held.
        if ($file === null || $file->isPublic() || $file->readerEntitlement() !== $gate) {
            return $this->refused($site);
        }

        return [$entry, $file];
    }

    /** The one refusal: a notice that fits every cause, and a way back to the reader's account. */
    private function refused(Site $site): Response
    {
        return ReaderPage::render($site, 'notice', 'download.title', [
            'message' => 'download.missing',
            'replace' => ['site' => (string) $site->name],
            'next' => [ReaderLinks::path($site), 'home.title'],
        ], 404);
    }

    /** The database refused: a 503 notice, the same for every id, and the SQLSTATE in one log line. */
    private function unavailable(Site $site, string $state): Response
    {
        MediaStream::unavailable($state);

        return ReaderPage::render($site, 'notice', 'download.title', [
            'message' => 'download.unavailable',
            'next' => [ReaderLinks::path($site), 'home.title'],
        ], 503);
    }

    private function site(): Site
    {
        return $this->context->site() ?? abort(404);
    }
}
