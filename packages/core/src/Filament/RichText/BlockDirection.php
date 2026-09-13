<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\RichText;

use DOMElement;
use Tiptap\Core\Extension;

/**
 * The PHP half of issue #67: keep `dir` through the SERVER-SIDE document model.
 *
 * ⚠️ THIS EXISTS BECAUSE A MEASUREMENT REFUTED THE OBVIOUS STORY. The defect looks like a browser
 * problem — TipTap drops attributes its schema does not declare — so the first fix was a JS extension
 * alone, and it changed nothing. Probing the page showed why: the state Filament hands the browser is
 * not the stored HTML, it is a ProseMirror JSON document, and the paragraph nodes in it carried
 * `{"textAlign":"start"}` and no `dir` at all. The conversion runs HERE, in `tiptap-php`, before the
 * browser is involved — so the attribute was already gone by the time any JS could keep it.
 *
 * Both halves are needed and neither is sufficient: this one keeps `dir` in the document the browser
 * receives, and `rich-editor-direction.js` keeps it in the document the browser sends back.
 *
 * ⚠️ `TextAlign` IN THE SAME LIBRARY IS THE TEMPLATE, deliberately followed rather than improvised: a
 * global attribute is `types` plus `attributes`, with `parseHTML` reading the DOM node and `renderHTML`
 * emitting an attribute array or null. Following the shipped shape is what makes this survive an upgrade
 * of the library.
 */
class BlockDirection extends Extension
{
    /** @var string */
    public static $name = 'kitsuneBlockDirection';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addGlobalAttributes(): array
    {
        return [
            self::declaredOn(BlockDirectionPlugin::nodes(), null),
        ];
    }

    /**
     * One group of node types, and the direction a block of those types starts with.
     *
     * ⚠️ THE DEFAULT IS NULL, AND `auto` IS A MEASURED MISTAKE rather than an untried idea. Review asked
     * for a default so a block the author has just created resolves its own direction while typing — a real
     * gap, since `Entry` stamps only on the way INTO storage. But `auto` on every text-bearing node also
     * puts it on the paragraph INSIDE a list item, and `dir="auto"` resolves from an element's text
     * EXCLUDING any descendant that has its own direction. Measured in the browser on the seeded list:
     *
     *   LI[auto]=ltr  wrapping  P[auto]=rtl
     *
     * The text flowed right-to-left while the item's own direction went left-to-right, which puts the
     * bullet on the wrong side — a visible regression in exchange for the gap it closed. No static default
     * can tell a top-level paragraph from one inside a list item, because they are the same node type; that
     * needs a handler which knows a block's parent. The gap is recorded in
     * `docs/accessibility-inventory.md` rather than traded for this.
     *
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    private static function declaredOn(array $types, ?string $default): array
    {
        return [
            'types' => $types,
            'attributes' => [
                'dir' => [
                    'default' => $default,
                    /*
                     * ⚠️ ONLY THE THREE VALUES `Entry` WILL STORE. Anything else on a stored block is
                     * somebody's stray attribute rather than a direction, and carrying it through the
                     * document model would launder it into the editor's own output — the sanitiser allows
                     * `dir`, and what it allows is `ltr`, `rtl` and `auto`.
                     */
                    'parseHTML' => function ($DOMNode) use ($default): ?string {
                        if (! $DOMNode instanceof DOMElement) {
                            return $default;
                        }

                        $direction = $DOMNode->getAttribute('dir');

                        return in_array($direction, ['ltr', 'rtl', 'auto'], true) ? $direction : $default;
                    },
                    /*
                     * ⚠️ NULL RATHER THAN AN EMPTY ARRAY when there is nothing to write, because the
                     * library reads null as "this attribute contributes no markup". An empty array would be
                     * a `dir=""`, which is a direction — the browser reads it as `ltr` — on every block
                     * that never had one.
                     */
                    'renderHTML' => function ($attributes): ?array {
                        $direction = $attributes->dir ?? null;

                        return $direction === null ? null : ['dir' => $direction];
                    },
                ],
            ],
        ];
    }
}
