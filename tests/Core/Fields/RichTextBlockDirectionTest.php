<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\Types\RichTextType;

/**
 * Rich text resolves direction per BLOCK, which is issue #39's last gap.
 *
 * ⚠️ `dir` WAS ALREADY ALLOWED AND THAT WAS NOT ENOUGH. `RichTextType::control()` said *"`dir` is
 * already in ALLOWED_ATTRIBUTES, so per-block direction survives sanitising"* — true, and it reads
 * as though the feature exists. Surviving is not the same as existing: nothing PRODUCED a `dir`, so
 * an author writing an Arabic paragraph beside an English one got neither, and every block inherited
 * the direction of the chrome.
 *
 * ⚠️ A SINGLE `dir` ON THE FIELD IS WORSE THAN NONE, which is why this is per block rather than on
 * the container. `dir="auto"` on the editor resolves from the FIRST strong directional character in
 * the whole document, so a body that opens in English and continues in Arabic renders every Arabic
 * paragraph left-to-right — and looks handled.
 */
/**
 * What `castToStorage()` does: sanitise, then give each block its direction.
 *
 * ⚠️ TWO CALLS, BECAUSE THEY ARE TWO STEPS. The first version of this feature stamped the direction
 * inside `sanitize()` and broke nineteen tests, five of them asserting the sanitiser's exact output.
 * That breakage was the design objecting: `sanitize()` is a security boundary (field-types.md §6)
 * and its output is asserted byte-for-byte because it is one. Direction is not safety, and threading
 * it through means every future direction change edits security expectations.
 */
function storedRichText(string $html): string
{
    $type = new RichTextType;

    return $type->withBlockDirection($type->sanitize($html));
}

it('gives each text-bearing block its own direction', function (): void {
    expect(storedRichText('<p>Hello world</p><p>مرحبا بالعالم</p>'))
        ->toBe('<p dir="auto">Hello world</p><p dir="auto">مرحبا بالعالم</p>');
});

it('stamps every block tag that holds a run of text', function (string $tag): void {
    expect(storedRichText("<{$tag}>text</{$tag}>"))->toContain('dir="auto"');
})->with(['p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figcaption']);

it('leaves containers alone, because their children each resolve their own', function (): void {
    /*
     * ⚠️ `ul` AND `figure` HOLD BLOCKS, NOT TEXT. A direction on them would be inherited by
     * children that should each resolve from their own content — so a list whose first item is
     * English would drag an Arabic second item left-to-right, which is the per-field failure
     * reproduced one level down.
     */
    $list = storedRichText('<ul><li>English item</li><li>عنصر عربي</li></ul>');

    expect($list)->toBe('<ul><li dir="auto">English item</li><li dir="auto">عنصر عربي</li></ul>')
        ->and($list)->not->toContain('<ul dir');

    $figure = storedRichText('<figure><img src="/a.png" alt="x"><figcaption>عربي</figcaption></figure>');

    expect($figure)->toContain('<figcaption dir="auto">')
        ->and($figure)->not->toContain('<figure dir')
        ->and($figure)->not->toContain('<img dir');
});

it('leaves inline tags alone, because half a sentence has no direction', function (): void {
    // ⚠️ `dir` on a fragment resolves FROM that fragment, which is how one clause of a paragraph
    // ends up pointing the wrong way.
    expect(storedRichText('<p>Mixed <strong>bold</strong> and <em>italic</em></p>'))
        ->toBe('<p dir="auto">Mixed <strong>bold</strong> and <em>italic</em></p>');
});

it('keeps an explicit direction rather than overriding it', function (): void {
    /*
     * ⚠️ `dir` IS AN ALLOWED ATTRIBUTE, so an author who wrote `dir="rtl"` has said something more
     * specific than `auto` — a block of Arabic that opens with a Latin brand name, for instance,
     * where `auto` would resolve from the brand name and be wrong. This fills a gap; it does not
     * overrule a decision.
     */
    expect(storedRichText('<h2 dir="rtl">Explicit stays</h2>'))->toBe('<h2 dir="rtl">Explicit stays</h2>')
        ->and(storedRichText('<p dir="ltr">Also kept</p>'))->toBe('<p dir="ltr">Also kept</p>');
});

it('still strips everything it stripped before', function (): void {
    /*
     * ⚠️ THE SANITISER IS A SECURITY BOUNDARY (field-types.md §6), and this change reaches into its
     * attribute walk. Adding an attribute there must not become a way of keeping one: a `<script>`
     * is still removed, a disallowed attribute is still dropped, and a hostile `href` is still
     * neutralised on a block that now also carries a direction.
     */
    expect(storedRichText('<p onclick="x()">text</p>'))->toBe('<p dir="auto">text</p>')
        ->and(storedRichText('<script>alert(1)</script><p>after</p>'))->toBe('<p dir="auto">after</p>')
        ->and(storedRichText('<p><a href="javascript:alert(1)">link</a></p>'))
        ->toBe('<p dir="auto"><a href="#">link</a></p>');
});

it('is idempotent, so a re-save does not accumulate attributes', function (): void {
    // A value is sanitised on every write, and an edit that changes nothing must produce the same
    // bytes — otherwise every save looks dirty and files a revision (issue #59's cost, again).
    $once = storedRichText('<p>Hello</p><p>مرحبا</p>');

    expect(storedRichText($once))->toBe($once);
});
