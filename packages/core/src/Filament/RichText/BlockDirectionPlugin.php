<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\RichText;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Support\Facades\FilamentAsset;

/**
 * Teaches Filament's editor to keep the `dir` a stored block already carries — issue #67.
 *
 * `Entry` stamps `dir="auto"` on every text-bearing block on the way into storage, so the value is
 * right in the database and right for anything that renders it. TipTap parses that HTML into its own
 * document model and drops every attribute a node's schema does not declare, so the attribute died on
 * load: correct everywhere except the one place an author looks while writing.
 *
 * ⚠️ THE MAP IS THE CONTRACT BETWEEN TWO LANGUAGES, and it is here rather than in the JS because PHP is
 * where the stamping list lives. `Entry::BLOCK_TAGS` says which tags get a direction; this says which
 * editor node each of those tags becomes. `RichEditorDirectionAssetTest` asserts three things about it:
 * that every stamped tag appears, that the JS asset declares exactly the node names it names, and that
 * the editor Filament ships actually has those nodes. Any one of them drifting is the defect this
 * plugin exists to fix, one layer out.
 */
class BlockDirectionPlugin implements RichContentPlugin
{
    /**
     * Every tag `Entry` handles, and the editor node it becomes.
     *
     * ⚠️ THE LIST CONTAINERS ARE HERE, AND LEAVING THEM OUT LOST DATA — review found it. `Entry` does not
     * STAMP `ul` or `ol`, because a direction on a container is inherited by children that should each
     * resolve their own; but it does KEEP one an author wrote, and it then leaves the items unstamped
     * precisely because they inherit that fixed ancestor. Measured:
     *
     *   <ul dir="rtl"><li>Mow</li><li>تنظيف</li></ul>   ->  unchanged, items unstamped
     *   <ul><li>Mow</li><li>تنظيف</li></ul>             ->  <ul><li dir="auto">…</li>…</ul>
     *
     * So a list carrying `dir="rtl"` is a real stored shape, and it is the ONLY direction in it. An
     * extension that does not declare the attribute on those nodes discards it: the editor renders the
     * items in the chrome's direction, and the next save replaces the author's uniform `rtl` with per-item
     * `auto`. The first version of this file left them out and called it deliberate — which conflated
     * "never DEFAULT a direction onto a container" with "never DECLARE the attribute", and only the first
     * of those is the rule. With a null default they are separable: what the author wrote round-trips, and
     * an ordinary list still gains nothing.
     *
     * ⚠️ `figcaption` MAPS TO NOTHING ON PURPOSE, and it is listed rather than omitted so the absence is
     * a statement. Filament's editor has no figure or caption node — `image` is a leaf, and there is no
     * node whose `renderHTML` emits a `figcaption` — so a stored caption does not survive a round trip
     * through the editor at all, with or without its direction. Declaring an attribute on a node that
     * does not exist would silently do nothing; recording that here means the next reader knows the gap
     * is upstream rather than assuming this list is complete.
     *
     * ⚠️ `h2`, `h3` and `h4` ARE ONE NODE. TipTap models a heading as a single node type with a `level`
     * attribute, so all three map to `heading` and the list of NODES is shorter than the list of tags.
     *
     * @var array<string, string|null>
     */
    public const EDITOR_NODES = [
        'p' => 'paragraph',
        'li' => 'listItem',
        'h2' => 'heading',
        'h3' => 'heading',
        'h4' => 'heading',
        'blockquote' => 'blockquote',
        'pre' => 'codeBlock',
        'figcaption' => null,
        'ul' => 'bulletList',
        'ol' => 'orderedList',
    ];

    /** The asset id, shared with the service provider that registers it and the test that reads it. */
    public const ASSET = 'kitsune-rich-editor-direction';

    /** The package the asset is registered under, which is also its directory under `public/js`. */
    public const PACKAGE = 'kitsune/core';

    /**
     * The node names the JS extension declares `dir` on, in one place and deduplicated.
     *
     * @return list<string>
     */
    public static function nodes(): array
    {
        return array_values(array_unique(array_filter(self::EDITOR_NODES)));
    }

    /**
     * ⚠️ THIS WAS EMPTY, WITH A DOCBLOCK EXPLAINING WHY, AND THE EXPLANATION WAS WRONG. It said the PHP
     * half of TipTap is only a read path Kitsune does not use, so there was no server-side document model
     * to teach. Probing the editor in the browser disproved it in one line: the state Filament hands the
     * page is not the stored HTML but a ProseMirror JSON document, and its paragraph nodes carried
     * `{"textAlign":"start"}` and no `dir`. The conversion runs on the SERVER, so the attribute was
     * already gone before any JS extension could keep it — which is why the JS half alone changed
     * nothing, measured, twice.
     *
     * Both halves are load-bearing: `BlockDirection` keeps `dir` in the document the browser RECEIVES,
     * and `rich-editor-direction.js` keeps it in the document the browser SENDS BACK.
     *
     * @return array<int, mixed>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            new BlockDirection,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc(self::ASSET, self::PACKAGE),
        ];
    }

    /**
     * ⚠️ NO TOOL AND NO ACTION, because this adds no way to author anything. The direction is derived
     * from the text — `dir="auto"` is an instruction to resolve from content — so a button that sets it
     * would be a button that overrides the resolution with a guess. An author who really means `rtl` can
     * already write it, and `Entry` keeps an explicit direction rather than replacing it.
     *
     * @return array<int, mixed>
     */
    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
