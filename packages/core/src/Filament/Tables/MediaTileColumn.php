<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Tables;

use Filament\Support\Components\Contracts\HasEmbeddedView;

use function Filament\Support\generate_icon_html;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Illuminate\Support\Js;
use Illuminate\View\ComponentAttributeBag;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;

/**
 * A media entry's tile in its type's list — ADR-042 decision 6, with Adam's answers of 2026-09-29.
 *
 * @internal
 *
 * ⚠️ KITSUNE'S OWN COLUMN, AND NEVER HANDED A DISK PATH (decision 4). Filament's image column reads a state that is
 * neither a URL nor a `data:` URI as a path on its disk and, on any disk but `public` unless told otherwise, mints a
 * temporary URL for it, which on `local` skips `EntryPolicy`; this builds its markup from `MediaDelivery`'s answers
 * alone, and `UploadSurfaceTest` fails the build on the other.
 *
 * ⚠️ THREE KINDS OF TILE, and only one of them loads a file by itself.
 *
 * - A file the web server serves directly, of a type the page may render: an image at the same-origin path
 *   (`MediaDelivery::sameOriginUrlFor()`), loaded with the page — no PHP in the path, which is what public means.
 * - A file delivered through the route, of a type the page may render: a placeholder until it is clicked, and then one
 *   request through the route that authorises first. Loading them by themselves waits for the measurement decision 6
 *   owes: each is a framework boot, the scoped and policy queries and a stream of the original, under `no-store`.
 * - Everything else — a type the page may not render (Adam, decision 18: `MediaDelivery::INLINE` alone, SVG included),
 *   a directly served file with no same-origin path (decision 19), or a private file with no route to ask, in a panel
 *   without the tenancy `KitsunePanel::apply()` sets up — its type, as an icon, and no request at all.
 *
 * ⚠️ THE FILE ROW IS READ ONCE A PAGE, BY THE TABLE, NEVER HERE. `EntryResource::table()` eager-loads `mediaFile` for the
 * page's rows; this reads the relation only when it is loaded, so a tile can never add a query per row. A row without it
 * shows nothing rather than asking.
 */
final class MediaTileColumn extends Column implements HasEmbeddedView
{
    /** The tile's edge, in the table's own unit — Filament's image column is this tall by default. */
    private const SIZE = '2.5rem';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('kitsune::media.tile.column'));

        // No state of its own: the markup is built from the loaded row, and a state read from the record would ask it for
        // an attribute it does not have.
        $this->state(static fn (): ?string => null);

        /*
         * ⚠️ NO TILE IS A LINK; THE ROW'S TITLE IS. Wrapped in the row's link, a placeholder's button is a control inside a
         * link, which navigates when clicked, and an image with no words of its own is a link with no name — axe's
         * `link-name`, measured on this list. The image is decorative beside the title that names it.
         */
        $this->disabledClick();
    }

    public function toEmbeddedHtml(): string
    {
        $record = $this->getRecord();
        $file = $record instanceof Entry && $record->relationLoaded('mediaFile') ? $record->getRelation('mediaFile') : null;

        if (! $record instanceof Entry || ! $file instanceof MediaFile) {
            return '<div class="fi-ta-image"></div>';
        }

        if (MediaDelivery::rendersInline($file) && ($path = MediaDelivery::sameOriginUrlFor($file)) !== null) {
            return self::direct($file, $path);
        }

        if (MediaDelivery::rendersInline($file) && ! MediaDelivery::servesDirectly($file) && ($url = MediaDelivery::urlForFile($record, $file)) !== null) {
            return self::onClick($record, $url);
        }

        return self::typeOnly($file);
    }

    /**
     * A directly served image, at its path on the admin's own host, with its type in its place if it will not load. A file
     * trashed after the page rendered and one whose bytes are not where its row says (decision 5's residue) are the same
     * request: a trash has already deleted every copy on a disk the web serves (`MediaWithdrawal`), so both ask for a file
     * that is not at this path. The web server answers both alike, and the tile shows its type either way.
     */
    private static function direct(MediaFile $file, string $path): string
    {
        $size = self::sizeStyle();
        $dimensions = $file->width !== null && $file->height !== null
            ? ' width="'.(int) $file->width.'" height="'.(int) $file->height.'"'
            : '';

        return '<div class="fi-ta-image" x-data="{ failed: false }">'
            .'<img src="'.e($path).'" alt="" loading="lazy" decoding="async"'.$dimensions
            // An image that failed before Alpine started fired its `error` with nobody listening: asked once it has.
            .' x-show="! failed" x-on:error="failed = true" x-init="'.e('if ($el.complete && $el.naturalWidth === 0) failed = true').'"'
            .' style="'.$size.' object-fit: cover; border-radius: 0.25rem;">'
            .'<span x-show="failed" x-cloak>'.self::icon($file).self::srOnly(__('kitsune::media.tile.unavailable')).'</span>'
            .'</div>';
    }

    /**
     * A placeholder that fetches the file through the route when clicked (Adam, decision 17): as an Ajax request, so an
     * expired session answers 401 rather than recording the file as where to land after signing in, and so each refusal
     * can say what it was — signed out, no longer allowed, or gone.
     *
     * ⚠️ WHAT THE PAGE HAS ASKED FOR, IT KEEPS UNTIL IT UNLOADS — the request, and once it has answered the file, keyed by
     * the route's URL, so a tile made again shows it, or waits on the same request, without asking again. Every sort,
     * search, upload and page change re-renders the list, and Livewire makes a row whose place changed anew rather than
     * moving it (measured in the browser suite): the tile is a new element, its state gone, and it finds the file by a
     * route URL that is the same on every render. The file was already shown in this page, so showing it again reveals
     * nothing; it is never kept past the page, which is what `no-store` asks of the browser; and a refusal is not kept, so
     * the next click asks again. `wire:ignore` keeps a tile whose row did not move from being touched at all.
     */
    private static function onClick(Entry $record, string $url): string
    {
        $labels = Js::from([
            'loading' => __('kitsune::media.tile.loading'),
            'signed_out' => __('kitsune::media.tile.signed_out'),
            'refused' => __('kitsune::media.tile.refused'),
            'missing' => __('kitsune::media.tile.missing'),
            'failed' => __('kitsune::media.tile.failed'),
            'shown' => __('kitsune::media.tile.shown'),
        ]);
        $target = Js::from($url);
        // The types a tile may show (Adam, decision 18): what arrives must be one, whatever answered.
        $inline = Js::from(MediaDelivery::INLINE);

        $data = <<<JS
            {
                state: 'idle',
                src: null,
                message: '',
                labels: {$labels},
                init() {
                    const kept = window.kitsuneMediaTiles?.get({$target});

                    if (kept?.src) {
                        this.src = kept.src;
                        this.state = 'loaded';
                    } else if (kept) {
                        this.show(kept.request);
                    }
                },
                load() {
                    if (this.state === 'loading' || this.state === 'loaded') {
                        return;
                    }

                    const tiles = (window.kitsuneMediaTiles ??= new Map());
                    let kept = tiles.get({$target});

                    if (! kept) {
                        kept = { src: null, request: null };
                        kept.request = this.ask().then(
                            (src) => (kept.src = src),
                            (reason) => {
                                // A refusal is not kept: the next click asks again.
                                if (tiles.get({$target}) === kept) {
                                    tiles.delete({$target});
                                }

                                throw reason;
                            },
                        );
                        tiles.set({$target}, kept);
                    }

                    this.show(kept.request);
                },
                async ask() {
                    let response;
                    let blob;

                    try {
                        response = await fetch({$target}, {
                            credentials: 'same-origin',
                            redirect: 'manual',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                    } catch (error) {
                        throw 'failed';
                    }

                    if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 419) {
                        throw 'signed_out';
                    }

                    if (! response.ok) {
                        throw response.status === 403 ? 'refused' : (response.status === 404 ? 'missing' : 'failed');
                    }

                    try {
                        blob = await response.blob();
                    } catch (error) {
                        throw 'failed';
                    }

                    if (! {$inline}.includes(blob.type)) {
                        throw 'failed';
                    }

                    return URL.createObjectURL(blob);
                },
                show(request) {
                    this.state = 'loading';
                    this.message = this.labels.loading;

                    request.then(
                        (src) => {
                            this.src = src;
                            this.state = 'loaded';
                            // Said to a screen reader, and not shown: the image says it to everybody else.
                            this.message = this.labels.shown;
                        },
                        (reason) => this.fail(reason),
                    );
                },
                fail(reason) {
                    if (this.src) {
                        window.kitsuneMediaTiles?.delete({$target});
                        URL.revokeObjectURL(this.src);
                    }

                    this.src = null;
                    this.state = 'failed';
                    this.message = this.labels[reason] ?? this.labels.failed;
                },
            }
            JS;

        $size = self::sizeStyle();
        $attributes = (new ComponentAttributeBag)->merge([
            'class' => 'fi-ta-image',
            'wire:ignore' => true,
            'x-data' => $data,
            'style' => 'display: flex; align-items: center; gap: 0.5rem;',
        ], escape: true);

        return '<div '.$attributes->toHtml().'>'
            .'<button type="button" class="fi-icon-btn" x-on:click="load()" x-bind:aria-busy="state === \'loading\'"'
            // Once shown there is nothing left to do: a second press is announced as unavailable, not met with silence.
            .' x-bind:aria-disabled="state === \'loaded\'"'
            .' style="'.$size.' padding: 0; overflow: hidden; border-radius: 0.25rem;">'
            .'<span class="fi-sr-only">'.e(__('kitsune::media.tile.show')).' <bdi dir="auto">'.e((string) $record->title).'</bdi></span>'
            .'<template x-if="src"><img x-bind:src="src" alt="" x-on:error="fail(\'failed\')" style="'.$size.' object-fit: cover;"></template>'
            .'<span x-show="! src">'.self::icon(null).'</span>'
            .'</button>'
            .'<span role="status" x-text="message" x-bind:class="{ \'fi-sr-only\': state === \'loaded\' }" style="font-size: 0.75rem;"></span>'
            .'</div>';
    }

    /** A file this list shows as its type alone, and requests nothing for. */
    private static function typeOnly(MediaFile $file): string
    {
        return '<div class="fi-ta-image">'.self::icon($file).self::srOnly(__('kitsune::media.tile.no_preview', ['type' => (string) $file->mime])).'</div>';
    }

    /** The icon for a file's type, or for an image not yet shown. Decorative: the words beside it say what it is. */
    private static function icon(?MediaFile $file): string
    {
        $mime = $file === null ? 'image/' : (string) $file->mime;
        $icon = match (true) {
            str_starts_with($mime, 'image/') => Heroicon::OutlinedPhoto,
            str_starts_with($mime, 'video/') => Heroicon::OutlinedFilm,
            str_starts_with($mime, 'audio/') => Heroicon::OutlinedMusicalNote,
            str_starts_with($mime, 'text/'), $mime === 'application/pdf' => Heroicon::OutlinedDocumentText,
            default => Heroicon::OutlinedDocument,
        };

        return (string) generate_icon_html($icon, attributes: new ComponentAttributeBag(['aria-hidden' => 'true']))?->toHtml();
    }

    private static function srOnly(string $text): string
    {
        return '<span class="fi-sr-only">'.e($text).'</span>';
    }

    private static function sizeStyle(): string
    {
        return 'width: '.self::SIZE.'; height: '.self::SIZE.';';
    }
}
