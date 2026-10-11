<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables\Columns\Column;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;

/**
 * A media entry's tile on its type's list — ADR-042 decision 6.
 *
 * ⚠️ KITSUNE'S OWN COLUMN, NEVER HANDED A DISK PATH (decision 4). Filament's `ImageColumn` treats a state that is not a
 * URL as a path on its disk and, on any disk but `public`, mints a `temporaryUrl()` for it — which on `local` skips
 * `EntryPolicy`. A tile is built from `MediaDelivery`'s own answer and nothing else, and `UploadSurfaceTest` fails the
 * build on an `ImageColumn` anywhere in the packages.
 *
 * ⚠️ A PUBLIC TILE LOADS WITH THE PAGE, FROM THE HOST SERVING THE ADMIN. Its bytes are the web server's to serve, with
 * no PHP in the path, and `MediaDelivery::adminUrlForFile()` gives its URL with no scheme or host. One that does not
 * load is an amber badge saying so (decisions 39 and 42).
 *
 * ⚠️ A PRIVATE TILE LOADS ONLY WHEN CLICKED, UNTIL IT IS MEASURED. Each one is a request through the route that
 * authorises first — a framework boot, a session, the scoped and policy queries and a stream of the original file —
 * and ADR-042 defers loading them automatically until the stage measurement exists. So the route's path sits in a
 * `data-src` attribute, which nothing fetches until the tile's button is pressed, and then once, as an Ajax request
 * whose answer is the image or the reason it was refused (decisions 39 and 40). "Private" here is how the file is
 * SERVED, not its visibility: a public file awaiting publication still names the private disk, and is served by the
 * route (`MediaDelivery::servesDirectly()`).
 *
 * ⚠️ ONLY WHAT DELIVERY SERVES INLINE IS DRAWN. `MediaDelivery::INLINE` is the list of types a browser may render; a
 * PDF, an SVG, a video or anything else is a badge naming its type, and is never fetched — the direction a mistake has
 * to fall in, as that list says.
 *
 * ⚠️ THE FILE IS THE ONE THE LIST LOADED WITH ITS PAGE (`Entry::mediaFile()`), so a page of tiles is one query for all
 * of them. A record whose relation was not loaded reads it once, as any Eloquent relation does.
 */
final class MediaTileColumn extends Column implements HasEmbeddedView
{
    /** Loaded with the page: a file the web server serves. */
    public const DIRECT = 'direct';

    /** Loaded when clicked: a file the route that authorises first serves. */
    public const DEFERRED = 'deferred';

    /** Never loaded: a type delivery does not serve inline, or a private file with no route to ask here. */
    public const BADGE = 'badge';

    /** An entry with no file recorded — the broken state ADR-042 exists to avoid, shown rather than hidden. */
    public const MISSING = 'missing';

    /** An image fills its tile, cropped to the square rather than stretched. */
    private const IMAGE_STYLE = 'width:100%;height:100%;object-fit:cover;display:block';

    /**
     * What a private tile's status line can say, by the state its script is in — decisions 39 and 41. Each is a key
     * under `kitsune::media.tile.status`, and is written onto the line as a `data-` attribute of the same name.
     *
     * ⚠️ ONE LOWER-CASE WORD EACH. The line reads its words back as `$el.dataset[state]`, and a browser camel-cases a
     * hyphenated `data-` name: `data-signed-out` is `dataset.signedOut`, and the line would say nothing. `idle` has no
     * words: an idle tile's line is empty.
     */
    private const SAID = ['loading', 'shown', 'signedout', 'refused', 'gone', 'failed'];

    /**
     * A private tile's script, the same on every tile: it asks the route once a press, and says what the answer was —
     * ADR-042 decisions 39 and 40.
     *
     * ⚠️ NOTHING IS ASKED BEFORE A PRESS (decision 6). `show()` is the only code here that asks, reached only from the
     * button's click, and there is no `init()`: a tile drawn by a render, a sort or a page change asks nothing. A press
     * while a request is out, or once the image is shown, asks nothing either; a press after a refusal asks again, once.
     *
     * ⚠️ ONE REQUEST, WHOSE ANSWER IS BOTH THE REASON AND THE IMAGE. An `<img>` that fails says nothing of why: a 403, a
     * 404, a 500 and the sign-in page were one `error` event alike (measured, *Measured — decision 39*). `fetch()` reads
     * the status, and the same answer's bytes are drawn, because an `<img>` asking again would be a second pass through
     * PHP: the route sends `no-store`, and every show reached it (measured).
     *
     * ⚠️ AN AJAX REQUEST, SO A SIGNED-OUT PRESS RECORDS NOTHING. A plain request from an ended session is redirected to
     * sign in, and `redirect()->guest()` records the file as where to land: signed in again, the editor was shown the bare
     * image, outside the admin (measured). With `X-Requested-With`, and the any-type Accept `fetch()` sends of its own,
     * `expectsJson()` holds, so the answer is a 401 and nothing is recorded. ⚠️ SO NAME NO ACCEPT HERE: an image's Accept
     * is not any type, and the answer would be the redirect again (`MediaTilesTest` pins both).
     *
     * ⚠️ AND IT FOLLOWS NO REDIRECT. Nothing in Kitsune redirects an Ajax request on this route; something in front of it
     * that does, such as a gateway's own sign-in, is answered as something that went wrong, whose words say to reload,
     * in that one request. It is never followed to a page.
     *
     * ⚠️ A REASON IS GIVEN ONLY FOR THE ROUTE'S OWN REFUSAL, WHICH IS JSON. Asked as an Ajax request, every refusal the
     * route, the panel or Laravel makes is rendered as JSON (`expectsJson()`, measured); a 401, 403 or 404 that is not
     * — a gateway's challenge or block page, basic auth in front of stage, a proxy's own 404 — came from something in
     * front of the route, and naming Kitsune's reason for it would tell an editor who still holds the grant that it was
     * taken away (review, decision 39). So it is answered as something that went wrong, as a redirect is.
     *
     * ⚠️ EVERY WRITE ASKS WHETHER ITS REQUEST IS STILL THE TILE'S. When a re-render moves a card later in the list, Alpine
     * tears its tile down — `destroy()`, which drops the request and lets go of any bytes — and starts it again on the SAME
     * data, reset (`reconcileData()`, Alpine 3.17). An answer still out from before would otherwise land on a tile
     * nobody pressed, or beat the answer to a later press (measured, both).
     *
     * ⚠️ SHOWN ONCE DRAWN, AND THE BYTES LET GO THEN. The tile says it is shown when its image has loaded, not when the
     * bytes arrive: bytes that are not an image fail to draw, and the tile says it could not be loaded, never "shown"
     * first. The `blob:` URL is revoked once the image loads or fails, and the picture stays (measured), so the page
     * keeps no copy of an original that may be 64 MiB.
     *
     * ⚠️ NO CHECK OF THE TYPE THAT ARRIVES. A 200 from the route carries the row's stored type, and a tile is deferred
     * only for a type `MediaDelivery::INLINE` lists (`tileFor()`). Anything else answering 200, such as an
     * intermediary's page, is not an image, and the image's own `error` says so; its `blob:` URL lives until then.
     *
     * ⚠️ NO VALUE OF PHP'S IS WRITTEN INTO IT. The route is read from `data-src`, and the words from the status line's own
     * `data-` attributes, each escaped as an attribute; a title reaches a tile only as text.
     *
     * ⚠️ IT NEEDS ALPINE'S STANDARD BUILD AND A PAGE THAT ALLOWS `blob:` IMAGES. Livewire's CSP-safe build cannot evaluate
     * it, and a host policy of `img-src 'self'` would make every private tile one that could not be loaded. The admin
     * sends no CSP.
     */
    private const SCRIPT = <<<'JS'
        {
            state: 'idle',
            src: null,
            request: null,
            async show() {
                if (this.state === 'loading' || this.state === 'shown') return;
                const request = this.request = new AbortController();
                let state = 'failed';
                this.state = 'loading';
                try {
                    const response = await fetch(this.$root.dataset.src, { redirect: 'manual', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: request.signal });
                    if (response.ok) {
                        const blob = await response.blob();
                        if (request === this.request) this.src = URL.createObjectURL(blob);
                        return;
                    }
                    if ((response.headers.get('Content-Type') ?? '').startsWith('application/json')) {
                        state = { 401: 'signedout', 403: 'refused', 404: 'gone' }[response.status] ?? 'failed';
                    }
                } catch {
                }
                if (request === this.request) {
                    this.request = null;
                    this.state = state;
                }
            },
            drawn(event) {
                URL.revokeObjectURL(this.src);
                this.request = null;
                if (event.type === 'load') {
                    this.state = 'shown';
                } else {
                    this.src = null;
                    this.state = 'failed';
                }
            },
            destroy() {
                this.request?.abort();
                this.request = null;
                if (this.src) URL.revokeObjectURL(this.src);
            },
        }
        JS;

    public static function make(?string $name = 'media_tile'): static
    {
        return parent::make($name);
    }

    /**
     * What an entry's tile shows, and where it loads from.
     *
     * @return array{kind: string, src: ?string, label: string}
     */
    public static function tileFor(Entry $entry): array
    {
        $file = $entry->mediaFile;

        if (! $file instanceof MediaFile) {
            return ['kind' => self::MISSING, 'src' => null, 'label' => __('kitsune::media.tile.missing')];
        }

        /*
         * ⚠️ A TRASHED ENTRY'S TILE FETCHES NOTHING — ADR-042 decision 31. The trash lists it, and the route that authorises
         * first resolves live entries alone, as its pages do: a preview would be a broken image. Its file is off the web
         * already, so a badge says where it is.
         */
        if ($entry->trashed()) {
            return ['kind' => self::BADGE, 'src' => null, 'label' => __('kitsune::media.tile.trashed')];
        }

        $type = self::typeLabel($file);

        if (MediaDelivery::dispositionFor($file) !== 'inline') {
            return ['kind' => self::BADGE, 'src' => null, 'label' => $type];
        }

        $src = MediaDelivery::adminUrlForFile($entry, $file);

        if ($src === null) {
            return ['kind' => self::BADGE, 'src' => null, 'label' => __('kitsune::media.tile.private')];
        }

        return ['kind' => MediaDelivery::servesDirectly($file) ? self::DIRECT : self::DEFERRED, 'src' => $src, 'label' => $type];
    }

    public function toEmbeddedHtml(): string
    {
        $entry = $this->getRecord();

        if (! $entry instanceof Entry) {
            return '';
        }

        $tile = self::tileFor($entry);

        return match ($tile['kind']) {
            self::DIRECT => self::direct((string) $tile['src']),
            self::DEFERRED => self::deferred($entry, (string) $tile['src']),
            default => self::frame($tile['kind'], self::badge($tile['label'])),
        };
    }

    /**
     * A file the web server serves: an image loaded with the page, and an amber badge in its place when it does not load
     * — decisions 39 and 42.
     *
     * ⚠️ ITS ERROR CAN COME BEFORE ALPINE IS THERE TO HEAR IT, and did on 13 of 20 loads (measured): Livewire's script is
     * a classic one that starts Alpine at `DOMContentLoaded`, and an image that fails while the page still parses fires
     * `error` to nobody. So `x-init` asks the image itself — finished, yet with no width, is broken — and `x-on:error`
     * hears every later failure. Alpine runs `x-init` and attaches `x-on` in the one pass that starts the tile, so no
     * `error` falls between them.
     *
     * ⚠️ HIDDEN, NOT TAKEN OUT OF THE LAYOUT, AND ITS `load` UNDOES IT. A lazy image not yet asked for is not `complete`
     * in Chromium (measured), but the suite runs one browser, and an engine that answered otherwise would take every
     * image waiting below the fold for broken. Taken out of the layout (`x-show`), a lazy image is never asked for, so it
     * would stay a badge for good; hidden, it is still asked for when scrolled to, and its `load` puts it back (measured).
     *
     * ⚠️ THE BADGE STARTS HIDDEN BY ITS OWN STYLE, which no re-render puts back once Alpine has shown it, and needs no
     * `x-cloak`. It sits over the hidden image, centred as the frame centres its one child. `alt` stays empty (decision
     * 28): the title under the tile names it, and the badge's words say what became of it.
     */
    private static function direct(string $src): string
    {
        return self::frame(self::DIRECT, sprintf(
            '<img src="%s" alt="" loading="lazy" decoding="async" style="%s" x-init="%s" x-on:error="failed = true"'
            .' x-on:load="failed = false" x-bind:style="%s">%s',
            e($src),
            self::IMAGE_STYLE,
            e('failed = $el.complete && $el.naturalWidth === 0'),
            e("{ visibility: failed ? 'hidden' : 'visible' }"),
            self::badge(__('kitsune::media.tile.unloaded'), 'warning', ' x-show="failed" style="display:none;position:absolute"'),
        ), ' x-data="{ failed: false }"');
    }

    /**
     * A file the route that authorises first serves: a button that asks for it once pressed (decision 6), and a line
     * that says what the route answered (decisions 39 and 41).
     *
     * ⚠️ THE BUTTON IS NEVER HIDDEN, SO KEYBOARD FOCUS NEVER LEAVES IT. Main hid it with `x-show` once clicked, and focus
     * fell to `<body>` (measured). The image is drawn inside it; once drawn, it is `aria-disabled` — still focusable,
     * still in the tab order, its name unchanged — and described by the status line, which then says "Preview shown." to
     * a screen reader alone. A refused tile stays as it was, and asks again when pressed.
     *
     * ⚠️ ITS FOCUS RING IS DRAWN OUTSIDE THE SQUARE. Filament draws a focused link's ring 2px outside it, and a frame that
     * clipped cut it off: main's idle tile showed only the label's underline, and a shown one nothing at all (measured).
     * So a deferred tile's frame does not clip, and the button clips its own image to the frame's corners. Drawn inside
     * instead, over a photograph of its own tone, the ring all but disappeared (measured).
     *
     * ⚠️ THE STATUS LINE IS BESIDE THE BUTTON, NOT IN IT. ARIA makes a button's children presentational, so a live region
     * inside one need not be announced. It is in the page, empty, from the start, because a live region added with its
     * words is not reliably read, and the button names it as its description, so a screen reader that comes back to the
     * tile hears what it last said. `dir="auto"`, because core's words are English under an RTL admin, and the full stop
     * went to the wrong end (measured).
     *
     * ⚠️ ITS NAME BEGINS WITH THE WORDS IT SHOWS (WCAG 2.5.3), and never changes, so a voice user says what they see.
     *
     * ⚠️ THE BUTTON NEVER SHRINKS BELOW ITS WORDS, AND ITS IMAGE TAKES NO ROOM. At 200 % text a refusal's words filled
     * the square and a button that could shrink was squeezed to nothing, its *Show preview* clipped away (measured). So
     * it grows from its own height and never shrinks, and the frame, which does not clip, grows to hold it and the
     * line. The image is laid over the button rather than in its flow, so a tall original cannot make the square tall.
     */
    private static function deferred(Entry $entry, string $src): string
    {
        $status = 'kitsune-media-tile-'.$entry->getKey();
        $words = '';

        foreach (self::SAID as $state) {
            $words .= sprintf(' data-%s="%s"', $state, e(__('kitsune::media.tile.status.'.$state)));
        }

        return self::frame(self::DEFERRED, sprintf(
            '<button type="button" class="fi-link" aria-label="%s" aria-describedby="%s" x-on:click.prevent.stop="show()"'
            .' x-bind:aria-disabled="%s" x-bind:style="%s" style="%s">'
            .'<span x-show="%s">%s</span>'
            .'<template x-if="src"><img x-bind:src="src" alt="" decoding="async" x-show="%s"'
            .' x-on:load="drawn($event)" x-on:error="drawn($event)" style="%s"></template>'
            .'</button>'
            .'<p id="%s" role="status" dir="auto" x-bind:class="%s" x-bind:style="%s" style="%s"><span%s x-text="%s"></span>'
            .'<template x-if="%s"><span> <a href="" class="fi-link" style="text-decoration-line:underline">%s</a></span></template></p>',
            e(__('kitsune::media.tile.show_label', ['title' => (string) $entry->title])),
            e($status),
            e("state === 'shown'"),
            e("{ cursor: state === 'shown' ? 'default' : 'pointer' }"),
            'flex:1 0 auto;width:100%;overflow:hidden;border-radius:inherit;cursor:pointer',
            e("state !== 'shown'"),
            e(__('kitsune::media.tile.show')),
            e("state === 'shown'"),
            'position:absolute;inset:0;'.self::IMAGE_STYLE,
            e($status),
            e("{ 'fi-sr-only': state === 'shown' }"),
            e("{ paddingBlockEnd: state === 'idle' || state === 'shown' ? '0' : '0.5rem' }"),
            'margin:0;max-width:100%;padding-inline:0.5rem;font-size:0.875rem;line-height:1.25rem;text-align:center;overflow-wrap:anywhere',
            $words,
            e("\$el.dataset[state] ?? ''"),
            e("state === 'signedout'"),
            e(__('kitsune::media.tile.sign_in')),
        ), sprintf(' x-data="%s" data-src="%s"', e(self::SCRIPT), e($src)), clips: false);
    }

    /**
     * The square every tile sits in, whatever it holds.
     *
     * Styled inline, because core ships no stylesheet of its own and Filament's compiled one holds only the classes it
     * uses. The ground is the text colour thinned, so it reads in the light theme and the dark one alike. A column, so
     * a deferred tile's status line sits under its button; one child is centred alike either way.
     *
     * ⚠️ A FRAME THAT HOLDS A BUTTON DOES NOT CLIP (decision 39), so the button's focus ring is drawn; it stays square,
     * and grows only when its words need the room, as at 200 % text (measured).
     */
    private static function frame(string $kind, string $inner, string $attributes = '', bool $clips = true): string
    {
        return sprintf(
            '<div class="kitsune-media-tile" data-kitsune-tile="%s"%s style="%s">%s</div>',
            e($kind),
            $attributes,
            'aspect-ratio:1/1;width:100%;position:relative;'.($clips ? 'overflow:hidden;' : '').'border-radius:0.5rem;'
            .'display:flex;flex-direction:column;align-items:center;justify-content:center;'
            .'background:color-mix(in oklab, currentColor 6%, transparent)',
            $inner,
        );
    }

    /**
     * One of Filament's badges, which its compiled stylesheet holds: gray for what a file is, amber for a public image that
     * did not load (Adam, decision 42), whose words carry the meaning as well, so the colour is never the only sign.
     */
    private static function badge(string $label, string $color = 'gray', string $attributes = ''): string
    {
        return sprintf(
            '<span class="fi-badge fi-size-md %s"%s>%s</span>',
            e(implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $color))),
            $attributes,
            e($label),
        );
    }

    /** The stored file's extension, upper-cased — the name `MediaIntake` stored it under ends in the one it sniffed. */
    private static function typeLabel(MediaFile $file): string
    {
        $extension = pathinfo((string) $file->path, PATHINFO_EXTENSION);

        return $extension !== '' ? strtoupper($extension) : (string) $file->mime;
    }
}
