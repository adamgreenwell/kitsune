<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Filament\RichText\BlockDirection;
use Kitsune\Core\Filament\RichText\BlockDirectionPlugin;
use Kitsune\Core\Models\Entry;

/*
 * Issue #67: the editor drops the `dir` a stored block carries, so per-block direction is invisible
 * while authoring. The fix spans three languages — the tag list is PHP, the server-side document model
 * is PHP, and the browser-side one is JS — and the thing that breaks a fix like that is not any one of
 * them being wrong. It is the three drifting apart.
 *
 * ⚠️ SO THIS TEST IS ABOUT AGREEMENT, NOT BEHAVIOUR. Whether direction actually resolves per block in the
 * editor is measured where it can only be measured — `e2e/direction.spec.js`, with
 * `getComputedStyle().direction` on a real browser. What that test cannot see is a list that has quietly
 * stopped matching the one it was derived from.
 */

/**
 * The editor Filament ships, from wherever it is installed.
 *
 * ⚠️ THE ROOT FIRST, AND REVIEW FOUND WHY IT MATTERS. The first version read only
 * `skeleton/vendor`, which exists in the Playwright job and NOT in the jobs that run Pest — so the skip
 * was always true in CI and the compatibility assertion never ran anywhere but this machine. A guard that
 * only runs locally is a guard that ships a renamed node.
 */
function bundledEditor(): ?string
{
    foreach (['/vendor', '/skeleton/vendor'] as $root) {
        $path = dirname(__DIR__, 3).$root.'/filament/forms/dist/components/rich-editor.js';

        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

it('maps every tag the model gives a direction to', function (): void {
    /*
     * ⚠️ BOTH DIRECTIONS, because either gap is a defect. A handled tag missing from the map is a block
     * whose direction the editor still drops — the original bug, narrowed. A mapped tag the model never
     * touches is an attribute declared on a node that will never carry one, which reads as coverage and is
     * not.
     *
     * ⚠️ `STAMPED_TAGS`, NOT `BLOCK_TAGS`, and review found the first version comparing the narrower one.
     * `Entry` STAMPS the block tags, and it also KEEPS an author's direction on the list containers while
     * leaving their items unstamped — so `ul` and `ol` carry directions this editor must not discard, even
     * though nothing ever puts one there automatically. Comparing against the stamping list alone hid a
     * path that loses data.
     */
    $handled = (new ReflectionClass(Entry::class))->getConstant('STAMPED_TAGS');

    expect($handled)->toBeArray()->not->toBeEmpty()
        ->and(array_keys(BlockDirectionPlugin::EDITOR_NODES))->toBe($handled);
});

it('preserves a direction on a list without inventing one', function (): void {
    /*
     * ⚠️ THE DATA-LOSS PATH REVIEW FOUND, asserted at both ends. `Entry` keeps `<ul dir="rtl">` and leaves
     * its items unstamped, because they inherit that fixed ancestor — measured:
     *
     *   <ul dir="rtl"><li>Mow</li><li>تنظيف</li></ul>  ->  unchanged, items unstamped
     *   <ul><li>Mow</li><li>تنظيف</li></ul>            ->  <ul><li dir="auto">…</li>…</ul>
     *
     * So the list is sometimes the ONLY direction in the value. An extension that does not declare the
     * attribute on those nodes discards it, and the next save replaces a uniform `rtl` with per-item
     * `auto` — the author's choice quietly rewritten.
     */
    $entry = new Entry;
    $stamp = new ReflectionMethod(Entry::class, 'stampedInto');
    $stamp->setAccessible(true);

    expect($stamp->invoke($entry, '<ul dir="rtl"><li>Mow</li><li>تنظيف</li></ul>'))
        ->toBe('<ul dir="rtl"><li>Mow</li><li>تنظيف</li></ul>')
        ->and(BlockDirectionPlugin::nodes())->toContain('bulletList')
        ->and(BlockDirectionPlugin::nodes())->toContain('orderedList');

    /*
     * ⚠️ AND THE CONTAINER GROUP'S DEFAULT IS NULL ON BOTH SIDES, which is the property that keeps
     * "preserve" from becoming "set". Filament's own `textDirection` extension is not used precisely
     * because its option defaults a direction onto every node type at once, containers included — the two
     * groups here exist so a text-bearing block can start at `auto` while a list starts at nothing.
     */
    $group = (new BlockDirection)->addGlobalAttributes()[0];

    expect($group['attributes']['dir']['default'])->toBeNull();

    $javascript = (string) file_get_contents(
        dirname(__DIR__, 3).'/packages/core/resources/js/rich-editor-direction.js',
    );

    expect(str_contains($javascript, 'default: null'))
        ->toBeTrue('the browser half must not default a direction either');

    /*
     * ⚠️ AND IT MUST NOT SURVIVE A SPLIT. TipTap's `keepOnSplit` defaults to TRUE, so pressing Enter at the
     * end of a stored `<li dir="rtl">` copied `dir="rtl"` onto the new item — which then renders English
     * right-to-left, and which `Entry` stores as an explicit choice rather than replacing with `auto`. A
     * direction is a property of a block's content, and a block with no content yet has none to inherit.
     */
    expect(str_contains($javascript, 'keepOnSplit: false'))
        ->toBeTrue('a new block must not inherit the direction of the one it was split from');
});

it('declares the same node names in PHP and in the browser', function (): void {
    /*
     * ⚠️ THE JS IS READ RATHER THAN TRUSTED. The browser half cannot import a PHP constant, so the node
     * lists appear in both languages and this is what keeps them the same lists. Both groups are checked,
     * because the groups are the decision: which nodes START at `auto` and which only PRESERVE what an
     * author wrote.
     */
    $javascript = (string) file_get_contents(
        dirname(__DIR__, 3).'/packages/core/resources/js/rich-editor-direction.js',
    );

    expect(preg_match('/types:\s*\[([^\]]*)\]/', $javascript, $found))->toBe(1);

    preg_match_all("/'([a-zA-Z]+)'/", $found[1], $names);

    expect($names[1])->toBe(BlockDirectionPlugin::nodes());
});

it('starts every block at nothing, which a measurement decided', function (): void {
    /*
     * ⚠️ A DEFAULT OF `auto` WOULD CLOSE A REAL GAP AND OPEN A WORSE ONE, and the measurement is the whole
     * argument. Review asked for one: a block the author has just created carries no `dir`, so Arabic typed
     * into it renders in the chrome's direction until the value is saved and `Entry` stamps it.
     *
     * But `auto` on every text-bearing node also lands on the paragraph INSIDE a list item, and `dir="auto"`
     * resolves from an element's text EXCLUDING any descendant that has its own direction. Measured in the
     * browser on the seeded list, with the default in place:
     *
     *   LI[auto]=ltr   wrapping   P[auto]=rtl
     *
     * The text flowed right-to-left while the item's own direction went left-to-right — the bullet on the
     * wrong side, in content that was rendering correctly before. A top-level paragraph and one inside a
     * list item are the same node type, so no static default separates them; the gap is recorded in
     * `docs/accessibility-inventory.md` instead.
     */
    $group = (new BlockDirection)->addGlobalAttributes()[0];

    expect($group['types'])->toBe(BlockDirectionPlugin::nodes())
        ->and($group['attributes']['dir']['default'])->toBeNull();

    // A block with no `dir` in the stored HTML gains nothing, which is what keeps the list rendering right.
    $document = new DOMDocument;

    expect($group['attributes']['dir']['parseHTML']($document->createElement('p')))->toBeNull();
});

it('keeps the same three directions on both sides', function (): void {
    /*
     * ⚠️ `ltr`, `rtl` and `auto` — the three the sanitiser admits and the model writes. A fourth value
     * accepted on one side and dropped on the other is a value that survives the editor and dies on save,
     * or the reverse, and either way the author sees their choice discarded.
     */
    $javascript = (string) file_get_contents(
        dirname(__DIR__, 3).'/packages/core/resources/js/rich-editor-direction.js',
    );

    expect(preg_match('/KEPT\s*=\s*\[([^\]]*)\]/', $javascript, $found))->toBe(1);

    preg_match_all("/'([a-z]+)'/", $found[1], $kept);

    expect($kept[1])->toBe(['ltr', 'rtl', 'auto']);

    // And the PHP half accepts exactly those, asserted through the parser rather than by reading it.
    $attributes = (new BlockDirection)->addGlobalAttributes()[0]['attributes']['dir'];
    $document = new DOMDocument;

    foreach (['ltr', 'rtl', 'auto'] as $direction) {
        $element = $document->createElement('p');
        $element->setAttribute('dir', $direction);

        expect($attributes['parseHTML']($element))->toBe($direction);
    }

    /*
     * ⚠️ AND A VALUE THAT IS NOT A DIRECTION FALLS BACK TO THE GROUP'S DEFAULT rather than to null, which
     * is the same thing a missing attribute does: `dir="sideways"` says nothing about direction, so the
     * block is treated as having said nothing.
     */
    foreach (['', 'sideways', 'LTR', 'auto '] as $rejected) {
        $element = $document->createElement('p');
        $element->setAttribute('dir', $rejected);

        expect($attributes['parseHTML']($element))->toBeNull("[{$rejected}] is not a direction");
    }
});

it('writes nothing when there is no direction to write', function (): void {
    /*
     * ⚠️ NULL, NOT AN EMPTY ARRAY, and the difference is visible to a reader: the library reads null as
     * "this attribute contributes no markup", while an empty array renders `dir=""` — which the browser
     * resolves as `ltr`. That would put a direction on every block that never had one, which is the
     * per-field failure this issue exists to undo.
     */
    $attributes = (new BlockDirection)->addGlobalAttributes()[0]['attributes']['dir'];

    expect($attributes['renderHTML']((object) ['dir' => null]))->toBeNull()
        ->and($attributes['renderHTML']((object) []))->toBeNull()
        ->and($attributes['renderHTML']((object) ['dir' => 'auto']))->toBe(['dir' => 'auto']);
});

it('names nodes the editor Filament ships actually has', function (): void {
    /*
     * ⚠️ AGAINST THE BUNDLE, because a node name that is merely plausible declares an attribute on nothing
     * and fails silently — which is exactly how this defect behaved before it was found. `listItem` and
     * `codeBlock` are the two that would be easy to guess wrong (`list_item`, `pre`), so they are read out
     * of the editor Filament actually ships rather than out of TipTap's documentation.
     */
    $source = (string) file_get_contents(bundledEditor());

    foreach (BlockDirectionPlugin::nodes() as $node) {
        expect(str_contains($source, 'name:"'.$node.'"'))
            ->toBeTrue("the bundled editor declares no node called [{$node}]");
    }

    /*
     * ⚠️ THE CONTAINERS ARE IN THE BUNDLE TOO, and they are in the map — to PRESERVE rather than to set.
     * The property that stops a list acquiring a direction it never had is the null default, asserted
     * above on both sides, rather than the node's absence here. The first version of this file asserted
     * the absence and lost an author's `<ul dir="rtl">`.
     */
    expect(BlockDirectionPlugin::nodes())->toContain('bulletList')
        ->and(BlockDirectionPlugin::nodes())->toContain('orderedList');
})->skip(
    bundledEditor() === null,
    'filament/forms is not installed, so the bundled editor cannot be read',
);

it('fails when the editor gains a node for the tag it has none for', function (): void {
    /*
     * ⚠️ THE ASSERTION BELOW CANNOT FAIL ON ITS OWN, which review pointed out: it reads Kitsune's own
     * constant, so it keeps passing while the world changes around it. `figcaption` maps to null because
     * Filament's editor has no caption node — and the moment it gains one, a stored caption's direction
     * starts being discarded precisely when preserving it becomes possible. So the absence is asserted
     * against the BUNDLE rather than against our own map.
     *
     * ⚠️ A NODE NAME, NOT THE WORD. The bundle contains the string `figcaption` today inside ProseMirror's
     * table of block-level HTML tags — `{address:!0,article:!0,…,figcaption:!0,figure:!0,…}` — which is a
     * list of tag names rather than a node definition. Searching for the word would fail today and teach
     * the next reader to delete the test; searching for a declared node name is the question.
     */
    $source = (string) file_get_contents(bundledEditor());

    expect(preg_match_all('/name:"([A-Za-z]*[Cc]aption[A-Za-z]*)"/', $source, $found))
        ->toBe(0, 'the bundled editor declares a caption node now: '.implode(', ', $found[1] ?? []).
            ' — map it in EDITOR_NODES so a stored caption keeps its direction');
})->skip(
    bundledEditor() === null,
    'filament/forms is not installed, so the bundled editor cannot be read',
);

it('records the tag the editor has no node for', function (): void {
    /*
     * ⚠️ AN ABSENCE STATED RATHER THAN OMITTED. `figcaption` is stamped by the model and maps to null,
     * because Filament's editor has no figure or caption node at all — a stored caption does not survive a
     * round trip through the editor with or without its direction. Recording it as null keeps the map
     * complete against `BLOCK_TAGS` while saying the gap is upstream; asserting it here means the day
     * Filament adds such a node, somebody has to decide rather than discover.
     */
    expect(BlockDirectionPlugin::EDITOR_NODES['figcaption'])->toBeNull()
        ->and(BlockDirectionPlugin::nodes())->not->toContain('figcaption');
});
