<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Rich text resolves direction per BLOCK, which is issue #39's last gap.
 *
 * ⚠️ `dir` WAS ALREADY ALLOWED AND THAT WAS NOT ENOUGH. `RichTextType::control()` said *"`dir` is
 * already in ALLOWED_ATTRIBUTES, so per-block direction survives sanitising"* — true, and it reads as
 * though the feature exists. Surviving is not producing: nothing PRODUCED a `dir`, so an author
 * writing an Arabic paragraph beside an English one got neither and every block inherited the chrome.
 *
 * ⚠️ A SINGLE `dir` ON THE FIELD IS WORSE THAN NONE, which is why this is per block. `dir="auto"` on
 * the editor resolves from the FIRST strong directional character in the whole document, so a body
 * that opens in English and continues in Arabic renders every Arabic paragraph left-to-right — and
 * looks handled.
 *
 * ⚠️ ASSERTED THROUGH A REAL WRITE, not by calling a helper. Review objected that the first version
 * of this feature made `withBlockDirection()` public, which broadens the extension API before v1.2 —
 * something CONTRIBUTING lists among the things that will not merge. It is private now, so these
 * tests go through `Entry::create()` and read the column, which is the path production uses anyway.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Publisher', 'slug' => 'rtd-pub']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'rtd-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $this->bodyStorage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'body', 'type' => 'rich_text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);
    Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $this->bodyStorage->id,
        'label' => 'Body', 'ordering' => 0,
    ]);
});

afterEach(fn () => app(Context::class)->forget());

/** The bytes actually in the column after a real save. */
function storedBody(string $html): string
{
    /** @var EntryType $type */
    $type = test()->type;

    $entry = Entry::create([
        'entry_type_id' => $type->id,
        'title' => 'Body test',
        'values' => ['body' => $html],
    ]);

    $raw = DB::table('entries')->where('id', $entry->getKey())->value('values');

    return (string) (json_decode((string) $raw, true)['body'] ?? '');
}

it('gives each text-bearing block its own direction', function (): void {
    expect(storedBody('<p>Hello world</p><p>مرحبا بالعالم</p>'))
        ->toBe('<p dir="auto">Hello world</p><p dir="auto">مرحبا بالعالم</p>');
});

it('stamps every block tag that holds a run of text', function (string $tag): void {
    expect(storedBody("<{$tag}>text</{$tag}>"))->toContain('dir="auto"');
})->with(['p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figcaption']);

it('leaves containers alone, because their children each resolve their own', function (): void {
    /*
     * ⚠️ `ul` AND `figure` HOLD BLOCKS, NOT TEXT. A direction on them would be inherited by children
     * that should each resolve from their own content — so a list whose first item is English would
     * drag an Arabic second item left-to-right, which is the per-field failure one level down.
     */
    $list = storedBody('<ul><li>English item</li><li>عنصر عربي</li></ul>');

    expect($list)->toBe('<ul><li dir="auto">English item</li><li dir="auto">عنصر عربي</li></ul>')
        ->and($list)->not->toContain('<ul dir');

    $figure = storedBody('<figure><img src="/a.png" alt="x"><figcaption>عربي</figcaption></figure>');

    expect($figure)->toContain('<figcaption dir="auto">')
        ->and($figure)->not->toContain('<figure dir')
        ->and($figure)->not->toContain('<img dir');
});

it('keeps a valid explicit direction rather than overriding it', function (): void {
    /*
     * ⚠️ `dir` IS AN ALLOWED ATTRIBUTE, so an author who wrote `dir="rtl"` has said something more
     * specific than `auto` — a block of Arabic that opens with a Latin brand name, where `auto` would
     * resolve from the brand name and be wrong. This fills a gap; it does not overrule a decision.
     *
     * ⚠️ CASE-INSENSITIVELY, because HTML attribute values are: `dir="RTL"` is a direction and is
     * kept as the author wrote it.
     */
    expect(storedBody('<h2 dir="rtl">Explicit stays</h2>'))->toBe('<h2 dir="rtl">Explicit stays</h2>')
        ->and(storedBody('<p dir="ltr">Also kept</p>'))->toBe('<p dir="ltr">Also kept</p>')
        ->and(storedBody('<p dir="RTL">Kept as written</p>'))->toBe('<p dir="RTL">Kept as written</p>');
});

it('replaces a dir that establishes no direction', function (string $invalid): void {
    /*
     * ⚠️ `hasAttribute()` ALONE WAS THE WRONG TEST, which review found. `dir` is allowed and its
     * VALUE was never checked, so `<p dir="">` and `<p dir="banana">` survive sanitising — and an
     * attribute that establishes nothing was still enough to block the stamp while doing nothing
     * itself, leaving an Arabic block inheriting the chrome. Only `ltr`, `rtl` and `auto` are
     * directions; anything else expresses no choice to respect.
     */
    expect(storedBody('<p dir="'.$invalid.'">مرحبا</p>'))->toBe('<p dir="auto">مرحبا</p>');
})->with(['', 'banana', 'inherit', '0']);

it('gives a top-level run somewhere to carry a direction', function (): void {
    /*
     * ⚠️ A VALUE NEED NOT CONTAIN A BLOCK, which review found and I had assumed away. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes `sanitize()` deliberately preserves — it refuses to wrap
     * loose text, because reshaping something that arrived as a bare string is data loss of its own —
     * so the per-block guarantee did not reach them at all. For API and import input that is the
     * ordinary case rather than an edge one.
     *
     * ⚠️ THIS RESHAPES, AND THAT IS THE TRADE. The alternative for these values is no direction at
     * all, and a top-level run is a paragraph in everything except markup.
     */
    expect(storedBody('مرحبا'))->toBe('<p dir="auto">مرحبا</p>')
        ->and(storedBody('<strong>مرحبا</strong>'))->toBe('<p dir="auto"><strong>مرحبا</strong></p>');
});

it('collects one sentence into one paragraph, not one per node', function (): void {
    // ⚠️ `a <strong>b</strong> c` is ONE sentence. Wrapping each node separately would give three
    // paragraphs, each resolving its own direction — a clause-by-clause direction, which is the
    // failure `BLOCK_TAGS` excludes inline tags to avoid.
    expect(storedBody('a <strong>b</strong> c'))->toBe('<p dir="auto">a <strong>b</strong> c</p>');
});

it('separates runs that a block sits between', function (): void {
    // A list is a block even though it carries no direction itself, so text before and after it are
    // two runs rather than one — which is why `CONTAINER_TAGS` is wider than `BLOCK_TAGS`.
    expect(storedBody('before<ul><li>x</li></ul>after'))
        ->toBe('<p dir="auto">before</p><ul><li dir="auto">x</li></ul><p dir="auto">after</p>');
});

it('does not wrap whitespace between blocks', function (): void {
    // Formatting, not a run. Wrapping it would add an empty paragraph to every pretty-printed value.
    expect(storedBody('<p>x</p>   <p>y</p>'))
        ->toBe('<p dir="auto">x</p>   <p dir="auto">y</p>');
});

it('still strips everything it stripped before', function (): void {
    /*
     * ⚠️ THE SANITISER IS A SECURITY BOUNDARY (field-types.md §6), and this feature runs beside its
     * attribute walk. Adding an attribute must not become a way of keeping one: a `<script>` is still
     * removed, a disallowed attribute is still dropped, and a hostile `href` is still neutralised on a
     * block that now also carries a direction.
     */
    expect(storedBody('<p onclick="x()">text</p>'))->toBe('<p dir="auto">text</p>')
        ->and(storedBody('<script>alert(1)</script><p>after</p>'))->toBe('<p dir="auto">after</p>')
        ->and(storedBody('<p><a href="javascript:alert(1)">link</a></p>'))
        ->toBe('<p dir="auto"><a href="#">link</a></p>');
});

it('is idempotent, so a re-save does not accumulate attributes', function (): void {
    // A value is converted on every write, and an edit that changes nothing must produce the same
    // bytes — otherwise every save looks dirty and files a revision (issue #59's cost, again).
    $once = storedBody('<p>Hello</p><p>مرحبا</p>');

    expect(storedBody($once))->toBe($once);
});
