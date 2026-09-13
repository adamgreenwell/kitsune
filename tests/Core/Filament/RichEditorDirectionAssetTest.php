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

it('maps every tag the model stamps', function (): void {
    /*
     * ⚠️ BOTH DIRECTIONS, because either gap is a defect. A stamped tag missing from the map is a block
     * whose direction the editor still drops — the original bug, narrowed. A mapped tag the model does not
     * stamp is an attribute declared on a node that will never carry one, which reads as coverage and is
     * not.
     */
    $stamped = (new ReflectionClass(Entry::class))->getConstant('BLOCK_TAGS');

    expect($stamped)->toBeArray()->not->toBeEmpty()
        ->and(array_keys(BlockDirectionPlugin::EDITOR_NODES))->toBe($stamped);
});

it('declares the same node names in PHP and in the browser', function (): void {
    /*
     * ⚠️ THE JS IS READ RATHER THAN TRUSTED. The browser half cannot import a PHP constant, so the node
     * list appears in both languages and this is what keeps them the same list. The JS is parsed for its
     * `types` array rather than searched for each name, so a name left in the file and removed from the
     * array is caught too.
     */
    $javascript = (string) file_get_contents(
        dirname(__DIR__, 3).'/packages/core/resources/js/rich-editor-direction.js',
    );

    expect(preg_match('/types:\s*\[([^\]]*)\]/', $javascript, $found))->toBe(1);

    preg_match_all("/'([a-zA-Z]+)'/", $found[1], $names);

    expect($names[1])->toBe(BlockDirectionPlugin::nodes());
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
    $bundle = dirname(__DIR__, 3).'/skeleton/vendor/filament/forms/dist/components/rich-editor.js';
    $source = (string) file_get_contents($bundle);

    foreach (BlockDirectionPlugin::nodes() as $node) {
        expect(str_contains($source, 'name:"'.$node.'"'))
            ->toBeTrue("the bundled editor declares no node called [{$node}]");
    }

    /*
     * ⚠️ AND THE CONTAINERS MUST NOT BE THERE. `bulletList` and `orderedList` exist in that bundle and are
     * deliberately absent from the map: a direction on a list is inherited by every item, so an English
     * first item would drag an Arabic second item left-to-right. This is the assertion that stops somebody
     * "completing" the list.
     */
    expect(BlockDirectionPlugin::nodes())->not->toContain('bulletList')
        ->and(BlockDirectionPlugin::nodes())->not->toContain('orderedList');
})->skip(
    ! is_file(dirname(__DIR__, 3).'/skeleton/vendor/filament/forms/dist/components/rich-editor.js'),
    'the skeleton has no vendor directory, so the bundled editor cannot be read',
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
