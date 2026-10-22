<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Site;
use League\Flysystem\FilesystemException;
use stdClass;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The steps a private file's delivery takes once a route has decided who is asking — ADR-041, the reader's download
 * route (decisions 1–4, Adam, 2026-10-11) and the staff route (#249). Each route owns its authority checks; both share
 * the bounded stream, checked international filename and canonical key.
 *
 * ⚠️ EVERY READER REFUSAL DOES THE SAME WORK, AND THAT IS WHY THE LOOKUP IS THREE READS AND NOT ONE. A reader who is refused is
 * not told why: an id that names nothing, a file offered to nobody, one offered for a name they do not hold, one of a type
 * this site has switched off, another site's, another org's. So the route reads the same three things in the same order
 * whatever the id names — `gatedOnSite()`, `typeEnabled()` and one `holds()` — and when nothing was found it still asks
 * `holds(NOTHING)` and throws the answer away. Without that decoy an unknown id costs one query fewer than a file the
 * reader does not hold, and the count, or the time, says which files exist and which are offered (measured: 8 queries
 * for every refusal on SQLite; the decoy removed, 7 for an unknown id). It looks like dead work; it is the rule.
 *
 * ⚠️ NO BYTE IS READ UNTIL THE ANSWER IS YES. `open()` and `present()` touch the disk; a route calls them only after the
 * reader holds the file's name here, or the staff view Gate has allowed it. A reader's missing file answers a non-holder
 * exactly as an unknown id does.
 *
 * ⚠️ OPENED BEFORE ANY ANSWER EXISTS, THEN SENT IN 256 KiB CHUNKS. `FilesystemAdapter::response()` opens the stream inside
 * its callback, after the 200 and its headers have gone, so a file that vanished in between is a truncated 200 and an
 * error report; and it hands the stream to `fpassthru()`, which writes the whole file to the output layer at once —
 * measured at 66 MiB of a worker's memory for a 64 MiB file under PHP-FPM, against 2 MiB for this loop. A 256 KiB `fread`
 * and `echo` at a time, `flush()` after each, and `connection_aborted()` checked so a reader who closed the tab stops the
 * read. The handle is closed in `finally`, whatever happens.
 *
 * ⚠️ THREE LOG LINES, PINNED BY TEXT IN `ReaderSurfaceTest`, AND NONE NAMES A READER: bytes missing or a length that
 * cannot be verified (the disk, path and entry — what an operator needs), and a database refusal (its SQLSTATE alone). A database
 * exception's message interpolates its bindings, and on this route those can carry the reader's key; nothing here is
 * passed to `report()`.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class MediaStream
{
    /**
     * ⚠️ THE DECOY: a well-formed name `holds()` is asked when nothing was found, so every refusal reads the entitlements
     * index once (see the class docblock). A reader who holds a name spelled like this gains nothing by it: the answer is
     * discarded, and there is no file to give.
     */
    public const NOTHING = 'none.none';

    /** How much is read and written at a time: 256 KiB, measured at a 2 MiB peak under PHP-FPM where 1 MiB chunks took 6. */
    public const CHUNK = 262_144;

    /** The largest key every engine Kitsune supports holds in a `bigint` column, as text. */
    private const LARGEST_KEY = '9223372036854775807';

    /**
     * The id from a URL as the primary key column can hold it, or null.
     *
     * Moved from `MediaDownloadController` (ADR-041), and with one check it did not need: the staff route constrains its
     * id to digits, and the reader route has no `where()` — a malformed id there must reach this, not fall through to the
     * skeleton's catch-all and its 200 placeholder (AGENTS.md §6).
     *
     * ⚠️ DIGITS ARE NOT ENOUGH, AND THE ENGINES DISAGREE ABOUT WHAT HAPPENS NEXT — AGENTS.md invariant 5. An id of
     * twenty-four nines is digits, and reaches `whereKey()`, where PostgreSQL refuses to coerce it to the `bigint` key and
     * raises SQLSTATE 22003 — a 500 rather than the 404 every other unknown id gets — while MySQL, MariaDB and SQLite
     * return no rows and say nothing. So a suite that skipped PostgreSQL would call this fixed.
     *
     * The bound is the COLUMN's, not PHP's: `PHP_INT_MAX` happens to match on a 64-bit build and would silently narrow
     * this on a 32-bit one, which is the kind of agreement that holds until it does not. So it is compared as text.
     * Leading zeros are refused rather than trimmed: `007` and `7` would otherwise be two URLs for one file.
     */
    public static function key(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^[0-9]+$/D', $raw) !== 1) {
            return null;
        }

        if ($raw !== '0' && $raw[0] === '0') {
            return null;
        }

        return strlen($raw) < strlen(self::LARGEST_KEY)
            || (strlen($raw) === strlen(self::LARGEST_KEY) && strcmp($raw, self::LARGEST_KEY) <= 0) ? $raw : null;
    }

    /**
     * The entry behind a file a reader may be offered on this site, and the name it is offered for — or null. One read,
     * the same whatever the id names.
     *
     * ⚠️ FOUR FENCES IN ONE QUERY, EACH OF WHICH A MUTANT MEASURED A LEAK WITHOUT:
     *
     * - `SiteScope` and `SoftDeletes`, the model's own: another org's row and a trashed one are not found;
     * - `entries.site_id = {site}`: a file kept to THIS site. `SiteScope` alone admits a row shared with the org, and an
     *   offer on one would open it on every site of the org for whoever holds the name there (ADR-041 decision 2). The
     *   writer refuses to offer a shared file; this refuses one planted below it;
     * - `media_files.visibility = 'private'`: a public file is served by the web server to anyone, and an offer on one is
     *   no gate. Make public is refused while an offer stands; this refuses one planted below it;
     * - a `reader_entitlement` at all, read back through `EntitlementName::isName()`: a stored value that is not a name is
     *   no offer (`MediaFile::readerEntitlement()`'s rule).
     *
     * @return array{0: Entry, 1: string}|null the entry and the name a reader must hold
     */
    public static function gatedOnSite(Site $site, string $id): ?array
    {
        $found = Entry::query()
            ->whereKey($id)
            ->where('entries.site_id', $site->getKey())
            ->join('media_files', 'media_files.entry_id', '=', 'entries.id')
            ->where('media_files.visibility', 'private')
            ->whereNotNull('media_files.reader_entitlement')
            ->first(['entries.*', 'media_files.reader_entitlement as kitsune_reader_gate']);

        if (! $found instanceof Entry) {
            return null;
        }

        $gate = $found->getAttribute('kitsune_reader_gate');

        // Taken off the instance, so nothing can mistake it for a column of `entries`.
        $found->offsetUnset('kitsune_reader_gate');
        $found->syncOriginal();

        return is_string($gate) && EntitlementName::isName($gate) ? [$found, $gate] : null;
    }

    /**
     * Whether this site serves the type — ADR-022, as the staff route asks it — read ONCE WHATEVER WAS FOUND: for nothing,
     * it asks about a type id of 0, so an unknown id costs the same read as a file of a type switched off.
     */
    public static function typeEnabled(Site $site, int|string|null $typeId): bool
    {
        $typeId ??= 0;

        return EntryTypeAvailability::enabledMapFor([$typeId], $site->getKey(), $site->site_group_id, $site->org_id)[$typeId] ?? true;
    }

    /**
     * Bind an already-authorized file to its opened bytes while custody still owns the row — ADR-041, #253.
     *
     * The entry and file are locked in custody's existing order. No authority is granted here: a moved/retyped entry,
     * changed offer or changed byte coordinates need the route's checks again, so this attempt refuses. The handle
     * survives a later pathname replacement; keeping a database transaction open while its body is sent buys nothing.
     *
     * @return resource|null
     */
    public static function openCurrent(Entry $entry, MediaFile $file)
    {
        $handle = null;

        try {
            return MediaCustody::locked($entry->getConnection(), (int) $entry->getKey(), static function (?stdClass $locked, ?stdClass $current) use ($entry, $file, &$handle) {
                if ($locked === null || $locked->deleted_at !== null || $current === null) {
                    return null;
                }

                // Its row is already locked. Read current values, including inside a host's older repeatable-read view.
                $stored = $entry->newQuery()->useWritePdo()->whereKey($entry->getKey())->lockForUpdate()->first(['org_id', 'site_id', 'entry_type_id']);

                if ($stored === null) {
                    return null;
                }

                foreach (['org_id', 'site_id', 'entry_type_id'] as $column) {
                    if (! self::sameStoredValue($stored->getRawOriginal($column), $entry->getRawOriginal($column))) {
                        return null;
                    }
                }

                foreach (['id', 'entry_id', 'disk', 'path', 'mime', 'size_bytes', 'checksum', 'visibility', 'reader_entitlement'] as $column) {
                    if (! self::sameStoredValue($current->{$column}, $file->getRawOriginal($column))) {
                        return null;
                    }
                }

                return $handle = self::open($entry, $file);
            });
        } catch (Throwable $failure) {
            // A commit can fail after the callback opened it. Nothing owns a body yet, so nothing else will close it.
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw $failure;
        }
    }

    /** Driver number representations may differ; NULL must never become the empty string. */
    private static function sameStoredValue(mixed $current, mixed $loaded): bool
    {
        return $current === null || $loaded === null ? $current === $loaded : (string) $current === (string) $loaded;
    }

    /**
     * The file's checked bytes, opened before any answer is built — or null when absent or not the recorded length.
     *
     * ⚠️ A DISK THAT THROWS IS A DISK WITHOUT THE BYTES. Laravel answers null for a missing file only on a disk configured
     * not to throw; one with `throw` raises Flysystem's own exception, which would otherwise be a 500 and a report.
     *
     * @return resource|null
     */
    public static function open(Entry $entry, MediaFile $file)
    {
        try {
            $handle = Storage::disk((string) $file->disk)->readStream((string) $file->path);
        } catch (FilesystemException) {
            $handle = null;
        }

        if (is_resource($handle)) {
            try {
                $stat = @fstat($handle);
                $mode = stream_get_meta_data($handle)['mode'];
                $size = $file->size_bytes;
                $recorded = $file->getAttributes()['size_bytes'] ?? null;
                $readable = str_starts_with($mode, 'r') || str_contains($mode, '+');

                // A wrapper can report logical offset zero while its underlying stream has already been read.
                // Reset the actual object; do not trust that offset or reopen/stat its pathname instead.
                if ($readable && @rewind($handle) && @ftell($handle) === 0 && $size >= 0
                    && (is_int($recorded) || is_string($recorded)) && (string) $recorded === (string) $size
                    && $stat !== false
                    && ($stat['mode'] & 0170000) === 0100000 && $stat['size'] === $size) {
                    return $handle;
                }
            } catch (Throwable) {
                // A custom adapter's stat failure has the same refusal as an unsupported stat or a failed open.
            }

            fclose($handle);
            self::unmatchedSize($entry, $file);

            return null;
        }

        self::missing($entry, $file);

        return null;
    }

    /**
     * Whether the file's bytes are there — what the landing page asks after the yes, so a reader is not offered a link
     * that would answer 404. An existence check, not an open: on an object store that is a HEAD, not a GET.
     */
    public static function present(Entry $entry, MediaFile $file): bool
    {
        try {
            $present = Storage::disk((string) $file->disk)->exists((string) $file->path);
        } catch (FilesystemException) {
            $present = false;
        }

        if (! $present) {
            self::missing($entry, $file);
        }

        return $present;
    }

    /**
     * The answer for an opened file: its headers, the reader's filename, and the bytes in 256 KiB chunks.
     *
     * ⚠️ HEAD IS ANSWERED, AND ITS HANDLE CLOSED NOW. A HEAD has passed every check a GET has, so it gets the same status
     * and headers and no body; the stream is closed here rather than left to a callback a HEAD may never run.
     *
     * ⚠️ AT MOST `size_bytes`, AND NO MORE: the row says how long the file is and `Content-Length` promises it, so a file
     * that grew under its row is cut where the row says, and one that shrank ends early — which the client sees as a short
     * download, never as more bytes than it was told.
     *
     * @param  resource  $handle  from `open()`
     */
    public static function respond(Request $request, Entry $entry, MediaFile $file, $handle): Response
    {
        $headers = MediaDelivery::headersFor($file);
        $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
            MediaDelivery::dispositionFor($file),
            MediaDelivery::readerFilenameFor($entry, $file),
            MediaDelivery::filenameFor($entry, $file),
        );

        if ($request->isMethod('HEAD')) {
            fclose($handle);

            return new Response('', 200, $headers);
        }

        $size = max(0, (int) $file->size_bytes);

        return new StreamedResponse(static function () use ($handle, $size): void {
            try {
                for ($left = $size; $left > 0;) {
                    $chunk = fread($handle, min(self::CHUNK, $left));

                    if ($chunk === false || $chunk === '') {
                        return;
                    }

                    echo $chunk;
                    $left -= strlen($chunk);
                    flush();

                    // ⚠️ A READER WHO CLOSED THE TAB STOPS THE READ. Without it the worker reads the whole file into a
                    // connection nobody holds. No Pest test can see this one: the CLI is never aborted.
                    if (connection_aborted() === 1) {
                        return;
                    }
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }, 200, $headers);
    }

    /**
     * One line when the database refused a reader's download: its SQLSTATE and nothing else. Never `report()`: a report
     * carries the exception, whose message can carry the reader's key, and the handler adds a `userId` of its own.
     */
    public static function unavailable(string $state): void
    {
        // A SQLSTATE is five letters and digits; anything else a driver hands back is not repeated.
        $state = preg_match('/^[0-9A-Z]{5}$/D', $state) === 1 ? $state : 'unknown';

        Log::warning(sprintf('A reader\'s download could not be answered: the database refused (SQLSTATE %s). The reader, the file and the entitlement are not logged.', $state));
    }

    /**
     * ⚠️ A ROW WITHOUT ITS BYTES IS A 404 AND THIS LINE, NEVER A 500 — the staff route's own words, so an operator
     * searching the log for one finds both. It names the disk, the path and the entry, which identify the file and nobody
     * who asked for it.
     */
    private static function missing(Entry $entry, MediaFile $file): void
    {
        Log::warning(sprintf('Kitsune has a media_files row whose bytes are missing: [%s:%s] for entry %s. If a delete of this entry was refused and its compensation failed, the only copy is on the private disk, where kitsune:media-prune lists it as an extra copy, kept; kitsune:media-reconcile --entry=%s --force puts it back, or says what will (ADR-042 decision 5). Otherwise the file was removed outside Kitsune.', (string) $file->disk, (string) $file->path, (string) $entry->getKey(), (string) $entry->getKey()));
    }

    /** The operator can identify the bytes; the reader and the entitlement are never named. */
    private static function unmatchedSize(Entry $entry, MediaFile $file): void
    {
        Log::warning(sprintf('Kitsune could not verify an opened media file against its recorded size: [%s:%s] for entry %s. No success response was sent. Inspect the stored bytes and the media_files row before retrying.', (string) $file->disk, (string) $file->path, (string) $entry->getKey()));
    }
}
