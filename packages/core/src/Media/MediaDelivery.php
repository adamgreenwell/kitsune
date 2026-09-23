<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Site;

/**
 * Where a media file's bytes are served from, and with what headers — ADR-041.
 *
 * ⚠️ TWO PATHS, BECAUSE VISIBILITY IS TWO DIFFERENT PROMISES. A public file lives on the linked disk and gets
 * a direct URL a CDN can cache, with no PHP involved and none that should be. The link that makes it
 * resolvable is created by both documented install flows and by `deploy/release.sh`; review found that only
 * the last of those did it, so a bare install served 403 at a URL this method reported confidently. A private file lives on a disk the web server does not serve and is streamed by
 * a controller that authorises first. ADR-041 records the cost of the second one plainly: at the ADR-027 floor,
 * bytes through PHP is the expensive path, and it is the default.
 *
 * ⚠️ THE PRIVATE URL IS A PANEL URL, AND THAT IS THE HONEST SHAPE RATHER THAN A SHORTCUT. ADR-041 leaves
 * *what* is authorised deliberately open: today the only question core can answer is the entry's own
 * permissions — a STAFF question — because ADR-040's entitlements do not exist yet. `/admin/{site}/media/{id}`
 * is a place a reader can never be, so the staff path never pretends to be the reader path. When entitlements
 * land (inside v1.0, not after it), the reader path is a SECOND route with its own context source and its own
 * question, and this one does not have to change to make room for it.
 *
 * ⚠️ AND IT IS REGISTERED THROUGH `KitsunePanel`, NOT THROUGH A ROUTE FILE. `skeleton/routes/web.php` records
 * the rule — core registers no routes, because a host application's URL space is its own. The panel is the
 * exception that proves it: a host that calls `KitsunePanel::apply()` has already opted into the URL space
 * core shapes there, which is where `EntryResource` puts `/{type}/{record}/edit`. Asking the skeleton to wire
 * a media route by hand would put a security-critical path in the one file an operator is invited to edit.
 */
final class MediaDelivery
{
    /**
     * The route's name WITHIN the panel, which prefixes it with `filament.{panelId}.`.
     *
     * The panel id is read at call time rather than hard-coded, because `KitsunePanel::apply()` is handed a
     * panel the host named — the skeleton calls it `admin`, and nothing makes another host do the same.
     */
    public const ROUTE = 'media.download';

    /**
     * The MIME types a private response may serve INLINE. Images, and core owns the list.
     *
     * ⚠️ THIS IS A NAMED DEPARTURE FROM ADR-041's WORDING, not an oversight. The entry says a private file is
     * "sent as an attachment", and read literally that makes a private image impossible to render — no preview,
     * no thumbnail grid, ever. The rule's purpose is to stop the BROWSER being talked into executing a file as
     * a document, and that risk lives in the formats that can carry script: SVG, HTML, anything the sniffer
     * might promote. A PNG served with its own stored `Content-Type` and `X-Content-Type-Options: nosniff`
     * cannot become a document, so the attachment forcing buys nothing there and costs the whole media UI.
     *
     * ⚠️ VIDEO AND AUDIO ARE DELIBERATELY ABSENT, though `MediaIntake` accepts mp4, webm and mp3. Inline
     * playback means range requests, which this slice does not implement — without them a browser re-fetches
     * the whole file to seek, and at the ADR-027 floor that is a 64MB read through PHP per scrub. They join
     * this list in the change that implements ranges, and not before.
     *
     * ⚠️ EVERYTHING NOT LISTED IS AN ATTACHMENT, which is the direction a mistake has to fall in. A type added
     * to `MediaIntake::ACCEPTED` is downloaded, never rendered, until somebody decides otherwise here.
     */
    public const INLINE = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /** The `media_files` row for an entry, or null when the entry is not a media entry at all. */
    public static function fileFor(Entry $entry): ?MediaFile
    {
        /* `MediaFile` is `#[Unscoped]` and reached through its entry — which the caller has already scoped. */
        return MediaFile::query()->where('entry_id', $entry->getKey())->first();
    }

    /**
     * Where this entry's bytes can be fetched from, or null when there is no answer to give.
     *
     * ⚠️ NULL IS A REAL ANSWER HERE AND CALLERS MUST HANDLE IT. An entry with no `media_files` row is not a
     * media entry; a private file asked about outside the panel — a console command, a queue worker, a test
     * with no tenant — has no URL, because the URL needs the site the user is operating in. Throwing would
     * turn "this list contains one non-media entry" into a 500.
     */
    public static function urlFor(Entry $entry): ?string
    {
        $file = self::fileFor($entry);

        if ($file === null) {
            return null;
        }

        if ($file->isPublic()) {
            return Storage::disk($file->disk)->url($file->path);
        }

        $name = self::routeName();
        $tenant = app()->bound('filament') ? Filament::getTenant() : null;

        /*
         * ⚠️ `Route::has()` RATHER THAN A BARE `route()`, which throws. A host can call `KitsunePanel::apply()`
         * on a panel without tenancy, or resolve this in a context where the panel's routes were never
         * registered; a `RouteNotFoundException` from a thumbnail is an error a long way from its cause.
         */
        if ($name === null || ! $tenant instanceof Site || ! Route::has($name)) {
            return null;
        }

        return route($name, ['tenant' => $tenant, 'media' => $entry->getKey()]);
    }

    /**
     * The full route name, or null when Kitsune's panel is not registered in this host.
     *
     * ⚠️ KITSUNE'S OWN PANEL, NOT FILAMENT'S DEFAULT — and the difference is the whole answer. The media route
     * is registered by `KitsunePanel::apply()`, which also records the panel it was applied to; a host running
     * several panels may have a default that never went through it and therefore has no media route at all.
     * Naming that panel would produce a route name nothing registered. `Permissions` resolves its auth model
     * through the same binding for the neighbouring reason.
     *
     * ⚠️ `app()->bound('filament')` FIRST, because core must survive a host where the facade resolves to
     * nothing — ADR-002's headless goal, and the shape this package's own test suite has. Without the guard
     * this is a `BindingResolutionException` from asking a media entry for its URL.
     */
    public static function routeName(): ?string
    {
        if (! app()->bound(KitsunePanel::PANEL_BINDING)) {
            return null;
        }

        $panel = app(KitsunePanel::PANEL_BINDING);

        return $panel instanceof Panel ? 'filament.'.$panel->getId().'.'.self::ROUTE : null;
    }

    /** `inline` for the image allowlist above, `attachment` for everything else. */
    public static function dispositionFor(MediaFile $file): string
    {
        return in_array((string) $file->mime, self::INLINE, true) ? 'inline' : 'attachment';
    }

    /**
     * The headers a private response carries.
     *
     * ⚠️ THE STORED `mime` IS SENT, AND THE PATH IS NEVER CONSULTED. ADR-041 makes this the read-side
     * counterpart to `rich_text`'s "escape on read": a file has no escaping step, so the rule becomes that
     * delivery does not infer a type. It has to be passed EXPLICITLY, because the obvious convenience method
     * does the forbidden thing — `FilesystemAdapter::response()` fills `Content-Type` with
     * `$this->mimeType($path)`, which Flysystem resolves from the EXTENSION. It uses `??=`, so a header
     * supplied here wins; omitting it silently reintroduces exactly what the ADR ruled out.
     *
     * ⚠️ `nosniff` IS WHAT MAKES SENDING THE STORED TYPE WORTH ANYTHING. Without it a browser is free to
     * disagree with us and re-decide the type from content, which is the sniffing attack the stored `mime`
     * exists to prevent.
     *
     * ⚠️ THE CSP IS SENT ON EVERY PRIVATE RESPONSE, not only on the types that need it. ADR-041 requires an
     * SVG to be served with a restrictive policy, and applying it narrowly would make every future type
     * somebody's job to remember. `default-src 'none'; sandbox` costs a listed image nothing.
     *
     * ⚠️ AND IT IS DEFENCE IN DEPTH RATHER THAN THE DEFENCE, WHICH ADR-041 SAYS IN SO MANY WORDS. A PUBLIC
     * SVG never passes through here: it is served off the linked disk by the web server with no PHP in the
     * path, so none of these headers reach it. That is exactly why the entry rejected "serving SVG
     * unsanitised behind headers" — *"a public CDN URL is exactly where one would not"* remember them — and
     * why `kitsune/svg-sanitizer` narrows the library's allowlist rather than leaning on this.
     *
     * ⚠️ `no-store`, AND THE COST IS REAL. `private` alone would let the viewer's own browser reuse the
     * bytes, which is exactly what an admin grid of thumbnails wants — and it would also keep a file readable
     * for that window after the grant that authorised it was revoked. A media UI that finds the refetch
     * expensive should come back with a measurement (Standing Principle #9), not with an assumption.
     *
     * @return array<string, string>
     */
    public static function headersFor(MediaFile $file): array
    {
        return [
            'Content-Type' => (string) $file->mime,
            'Content-Length' => (string) $file->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];
    }

    /**
     * The name the file is offered under.
     *
     * ⚠️ THE TITLE IS USER INPUT AND THE EXTENSION IS NOT. `MediaLibrary` seeds the title from the uploader's
     * filename, so it can contain anything they typed — and Symfony's `makeDisposition()` THROWS on a filename
     * containing `/`, `\` or `%`, which would turn a title like `before/after.png` into a 500 on download.
     * `Str::slug()` removes the whole class. The extension is taken from the STORED path, which
     * `MediaIntake::storedName()` generated, so the caller's filename cannot reach the offered name that way
     * either.
     */
    public static function filenameFor(Entry $entry, MediaFile $file): string
    {
        $stem = Str::slug((string) $entry->title);

        if ($stem === '') {
            $stem = 'file';
        }

        $extension = pathinfo((string) $file->path, PATHINFO_EXTENSION);

        return $extension === '' ? $stem : $stem.'.'.$extension;
    }
}
