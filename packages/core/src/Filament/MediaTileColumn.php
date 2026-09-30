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
 * no PHP in the path, and `MediaDelivery::adminUrlForFile()` gives its URL with no scheme or host.
 *
 * ⚠️ A PRIVATE TILE LOADS ONLY WHEN CLICKED, UNTIL IT IS MEASURED. Each one is a request through the route that
 * authorises first — a framework boot, a session, the scoped and policy queries and a stream of the original file —
 * and ADR-042 defers loading them automatically until the stage measurement exists. So the route's path sits in a
 * `data-src` attribute, which nothing fetches, and the image is made only once the tile is clicked. "Private" here is
 * how the file is SERVED, not its visibility: a public file awaiting publication still names the private disk, and is
 * served by the route (`MediaDelivery::servesDirectly()`).
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
            self::DIRECT => self::frame($tile['kind'], sprintf(
                '<img src="%s" alt="" loading="lazy" decoding="async" style="%s">',
                e((string) $tile['src']),
                self::IMAGE_STYLE,
            )),
            self::DEFERRED => self::frame($tile['kind'], sprintf(
                '<button type="button" x-show="! shown" x-on:click.prevent.stop="shown = true" aria-label="%s" class="fi-link" style="%s">%s</button>'
                .'<template x-if="shown"><img x-bind:src="$root.dataset.src" alt="" decoding="async" style="%s"></template>',
                e(__('kitsune::media.tile.show_label', ['title' => (string) $entry->title])),
                'width:100%;height:100%;cursor:pointer',
                e(__('kitsune::media.tile.show')),
                self::IMAGE_STYLE,
            ), sprintf(' x-data="{ shown: false }" data-src="%s"', e((string) $tile['src']))),
            default => self::frame($tile['kind'], sprintf(
                '<span class="fi-badge fi-size-md %s">%s</span>',
                e(implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, 'gray'))),
                e($tile['label']),
            )),
        };
    }

    /**
     * The square every tile sits in, whatever it holds.
     *
     * Styled inline, because core ships no stylesheet of its own and Filament's compiled one holds only the classes it
     * uses. The ground is the text colour thinned, so it reads in the light theme and the dark one alike.
     */
    private static function frame(string $kind, string $inner, string $attributes = ''): string
    {
        return sprintf(
            '<div class="kitsune-media-tile" data-kitsune-tile="%s"%s style="%s">%s</div>',
            e($kind),
            $attributes,
            'aspect-ratio:1/1;width:100%;overflow:hidden;border-radius:0.5rem;display:flex;align-items:center;'
            .'justify-content:center;background:color-mix(in oklab, currentColor 6%, transparent)',
            $inner,
        );
    }

    /** The stored file's extension, upper-cased — the name `MediaIntake` stored it under ends in the one it sniffed. */
    private static function typeLabel(MediaFile $file): string
    {
        $extension = pathinfo((string) $file->path, PATHINFO_EXTENSION);

        return $extension !== '' ? strtoupper($extension) : (string) $file->mime;
    }
}
