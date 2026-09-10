<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\Types\RichTextType;
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

it('gives a run inside a figure its own direction', function (): void {
    /*
     * ⚠️ A `figure` HOLDS FLOW CONTENT, so a run can sit directly inside one — and the outer pass
     * could not reach it. Review found it:
     * `<figure><strong>مرحبا</strong><figcaption>English</figcaption></figure>` is valid rich text,
     * and the Arabic run got no direction at all while the caption did.
     */
    expect(storedBody('<figure><strong>مرحبا</strong><figcaption>English</figcaption></figure>'))
        ->toBe('<figure><p dir="auto"><strong>مرحبا</strong></p><figcaption dir="auto">English</figcaption></figure>');

    expect(storedBody('<figure>bare <em>run</em><figcaption>cap</figcaption></figure>'))
        ->toBe('<figure><p dir="auto">bare <em>run</em></p><figcaption dir="auto">cap</figcaption></figure>');
});

it('leaves a run with no text unwrapped', function (): void {
    /*
     * ⚠️ THE COMMONEST FIGURE THERE IS, and the first version of the recursion above broke it:
     * `<figure><img><figcaption>` had its image wrapped in a paragraph, because the run was
     * non-empty. `dir="auto"` on an image resolves from no characters at all, so the wrapper was
     * markup added for nothing.
     */
    expect(storedBody('<figure><img src="/a.png" alt="x"><figcaption>عربي</figcaption></figure>'))
        ->toBe('<figure><img src="/a.png" alt="x"><figcaption dir="auto">عربي</figcaption></figure>');

    // Same at the top level: an image alone is not a paragraph.
    expect(storedBody('<img src="/a.png" alt="x">'))->toBe('<img src="/a.png" alt="x">');
});

it('does not wrap a loose run inside a list', function (): void {
    /*
     * ⚠️ NOT `ul` OR `ol`, however many text-bearing runs they hold. The recursion covers containers
     * that take FLOW content, and a list takes only `li` — so a `<p>` there would fix a direction by
     * producing markup no browser should be handed. Loose text directly inside `ul` is invalid input
     * to begin with, and the sanitiser does not produce it from valid input.
     */
    expect(storedBody('<ul><li>En</li><li>عربي</li></ul>'))
        ->toBe('<ul><li dir="auto">En</li><li dir="auto">عربي</li></ul>');

    // Two runs directly inside the list, which is the shape that would tempt the pass in.
    expect(storedBody('<ul>English<li>Item</li>עברית</ul>'))
        ->toBe('<ul>English<li dir="auto">Item</li>עברית</ul>');
});

it('gives every run in a flow-content container its own direction', function (string $html, string $expected): void {
    /*
     * ⚠️ `figure` ALONE WAS TOO NARROW, which review found after the figure fix. `blockquote`, `li`
     * and `figcaption` take flow content too, so the identical per-run defect sat in all three:
     * `<blockquote>English<p>عربي</p>עברית</blockquote>` gave the trailing Hebrew run no wrapper, so
     * it resolved from the blockquote's own `auto` — and that reads `English` first.
     *
     * The set is decided by what a `<p>` may legally sit inside, not by which tags hold text.
     */
    expect(storedBody($html))->toBe($expected);
})->with([
    [
        '<blockquote>English<p>عربي</p>עברית</blockquote>',
        '<blockquote dir="auto"><p dir="auto">English</p><p dir="auto">عربي</p><p dir="auto">עברית</p></blockquote>',
    ],
    [
        '<ul><li>English<p>عربي</p>עברית</li></ul>',
        '<ul><li dir="auto"><p dir="auto">English</p><p dir="auto">عربي</p><p dir="auto">עברית</p></li></ul>',
    ],
    [
        '<figure><img src="/a.png" alt="x"><figcaption>English<p>عربي</p>עברית</figcaption></figure>',
        '<figure><img src="/a.png" alt="x"><figcaption dir="auto"><p dir="auto">English</p>'
            .'<p dir="auto">عربي</p><p dir="auto">עברית</p></figcaption></figure>',
    ],
]);

it('wraps nothing when the container can carry the run itself', function (string $html, string $expected): void {
    /*
     * ⚠️ THE HALF THAT WIDENING THE PASS BROKE, and it is the reason the rule is about what can carry
     * a direction rather than about which tags to visit. `blockquote`, `li` and `figcaption` are
     * BLOCKS: each already has a direction from the pass above, and that direction serves exactly one
     * run. Wrapping unconditionally put a `<p>` inside every `<li>` in every existing document —
     * correct direction, gratuitous markup, and a visible change, since a paragraph in a list item
     * brings block margins with it.
     *
     * One run and somewhere to hold it needs nothing. Two runs and one direction does not go round.
     */
    expect(storedBody($html))->toBe($expected);
})->with([
    ['<blockquote>عربي</blockquote>', '<blockquote dir="auto">عربي</blockquote>'],
    ['<ul><li>عربي</li></ul>', '<ul><li dir="auto">عربي</li></ul>'],
    [
        '<figure><img src="/a.png" alt="x"><figcaption>عربي</figcaption></figure>',
        '<figure><img src="/a.png" alt="x"><figcaption dir="auto">عربي</figcaption></figure>',
    ],
    // ⚠️ A run BESIDE a block still counts as one: the container's own direction reaches it, and the
    // block has its own. It is the SECOND loose run that has nowhere left to resolve.
    ['<ul><li>English<p>عربي</p></li></ul>', '<ul><li dir="auto">English<p dir="auto">عربي</p></li></ul>'],
]);

it('does not override an explicit direction on the container', function (): void {
    /*
     * ⚠️ THE WRAPPER OVERRODE THE AUTHOR, which review found: `<figure dir="rtl">ACME مرحبا</figure>`
     * became `<figure dir="rtl"><p dir="auto">ACME مرحبا</p></figure>`, and `auto` on the new
     * paragraph resolves from `ACME` — so the run rendered left-to-right inside a container the
     * author had explicitly declared right-to-left. Inheritance was giving the right answer until a
     * wrapper was inserted to break it.
     *
     * ⚠️ THE ANSWER IS TO INSERT NOTHING, which is better than propagating the direction into a
     * wrapper: the container establishes `rtl` and holds one run, so there is nothing a wrapper would
     * add. `figure` is not a block and carries no direction of its own — an EXPLICIT one is still a
     * direction, and this is the case that distinguishes "has one" from "would default to auto".
     */
    expect(storedBody('<figure dir="rtl">ACME مرحبا</figure>'))
        ->toBe('<figure dir="rtl">ACME مرحبا</figure>');

    expect(storedBody('<blockquote dir="rtl">ACME مرحبا</blockquote>'))
        ->toBe('<blockquote dir="rtl">ACME مرحبا</blockquote>');
});

it('wraps without a direction where the container establishes one', function (): void {
    /*
     * ⚠️ THIS TEST HAS BEEN WRONG TWICE, IN OPPOSITE DIRECTIONS, and both are recorded because the
     * defect travelled the same way each time.
     *
     * It first asserted `dir="auto"` on the author's own `<p>`, reasoning "it is their markup, not a
     * wrapper this added" — true about ownership and wrong about the value, since a `<p>` with no
     * direction inside a container declared `rtl` inherits `rtl`. Review found that.
     *
     * It then asserted `dir="rtl"` on the wrappers AND on that `<p>`, propagating the container's
     * choice. Review found that too: a materialised direction is indistinguishable from an author's,
     * so editing the container to `ltr` could never reach the children again.
     *
     * ⚠️ SO NEITHER PASS WRITES A DIRECTION IT DOES NOT HAVE TO. The wrappers and the author's `<p>`
     * are treated identically — both inherit — which is the only version where an ancestor edit still
     * propagates and the only version where the two passes cannot disagree.
     */
    expect(storedBody('<blockquote dir="rtl">ACME<p>x</p>مرحبا</blockquote>'))
        ->toBe('<blockquote dir="rtl"><p>ACME</p><p>x</p><p>مرحبا</p></blockquote>');

    // ⚠️ And where nothing establishes a direction, each run still gets its own `auto` — the case
    // issue #39 is about, and the one a blanket "write nothing" would have broken.
    expect(storedBody('English<p>عربي</p>עברית'))
        ->toBe('<p dir="auto">English</p><p dir="auto">عربي</p><p dir="auto">עברית</p>');
});

it('does not let a dir that establishes no direction suppress a wrapper', function (string $invalid): void {
    /*
     * ⚠️ THE SAME TRAP AS `hasAttribute()` ONE LEVEL DOWN. `dir=""` and `dir="banana"` survive
     * sanitising — `dir` is allowed and its value was never checked — and a container test that only
     * asked whether the attribute was PRESENT would read them as a direction, suppressing the wrapper
     * while establishing nothing itself. That is the same failure the block pass was already fixed
     * for once, and it is why `ownDirection()` returns null rather than the raw attribute.
     *
     * ⚠️ ON A `figure`, AND THE FIRST VERSION OF THIS TEST WAS VACUOUS FOR USING `li`. `li` is a
     * BLOCK, so the block pass above has already replaced its invalid `dir` with `auto` by the time
     * this is read — the attribute is valid either way and the two implementations agree. `figure` is
     * not a block and keeps whatever it was given, which is the only place the distinction is
     * observable. Caught by reverting the check and finding the test still passed.
     */
    expect(storedBody('<figure dir="'.$invalid.'">عربي<figcaption>x</figcaption></figure>'))
        ->toBe('<figure dir="'.$invalid.'"><p dir="auto">عربي</p><figcaption dir="auto">x</figcaption></figure>');

    // And with two runs, the wrapper must not carry the meaningless value either.
    expect(storedBody('<figure dir="'.$invalid.'">عربي<p>x</p>עברית</figure>'))
        ->toBe('<figure dir="'.$invalid.'"><p dir="auto">عربي</p><p dir="auto">x</p><p dir="auto">עברית</p></figure>');
})->with(['banana', '']);

it('collects one sentence into one paragraph, not one per node', function (): void {
    // ⚠️ `a <strong>b</strong> c` is ONE sentence. Wrapping each node separately would give three
    // paragraphs, each resolving its own direction — a clause-by-clause direction, which is the
    // failure `BLOCK_TAGS` excludes inline tags to avoid.
    expect(storedBody('a <strong>b</strong> c'))->toBe('<p dir="auto">a <strong>b</strong> c</p>');
});

it('keeps the whitespace that separates words in a run', function (): void {
    /*
     * ⚠️ THE FIRST VERSION CORRUPTED CONTENT, and review found it. Whitespace-only text nodes were
     * skipped outright, so the space in `<strong>hello</strong> <em>world</em>` was left OUTSIDE the
     * paragraph while both elements moved into it — stored as
     * `<p><strong>hello</strong><em>world</em></p> ` and rendered as `helloworld`.
     *
     * A sanitiser that silently joins two words is worse than one that misses an attribute, so this
     * is asserted on the exact bytes rather than on the presence of a `dir`.
     */
    expect(storedBody('<strong>hello</strong> <em>world</em>'))
        ->toBe('<p dir="auto"><strong>hello</strong> <em>world</em></p>');

    // More than one space is still content: collapsing is the renderer's business, not storage's.
    expect(storedBody('<strong>x</strong>  <strong>y</strong>'))
        ->toBe('<p dir="auto"><strong>x</strong>  <strong>y</strong></p>');
});

it('leaves whitespace that sits between blocks outside the runs', function (): void {
    /*
     * ⚠️ THE OTHER HALF, and why the rule is about POSITION rather than content. Whitespace between
     * blocks is formatting; wrapping it would add an empty paragraph to every pretty-printed value,
     * and a run closing at a block should not swallow the space that merely separated them.
     */
    expect(storedBody('<p>block</p> <strong>after</strong>'))
        ->toBe('<p dir="auto">block</p> <p dir="auto"><strong>after</strong></p>')
        ->and(storedBody('<strong>before</strong> <p>block</p>'))
        ->toBe('<p dir="auto"><strong>before</strong></p> <p dir="auto">block</p>');
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

it('sanitizes a repeated value once, and does not serve a different one from the memo', function (): void {
    /*
     * ⚠️ THE SAME VALUE WAS PARSED THREE TIMES PER WRITE, which review found. `castToStorage()`
     * sanitises and then stamps directions — two parses, argued for in that method — and the
     * revision's loss check then called `sanitize()` again with the same string. Measured, one
     * parse-and-walk is 17.9 ms at 786 KB on a machine much faster than ADR-027's 1 vCPU floor, and
     * the value is unbounded: `scalarValidationRules()` is `['string']` and `apiSchema()` publishes
     * no length. The docblock claiming it was "bounded by MAX_LENGTH" and cost "microseconds" was
     * wrong on both counts — `MAX_LENGTH` bounds an authored validation PATTERN.
     *
     * ⚠️ WHAT A TEST CAN PIN IS THE MEMO'S CORRECTNESS, not its speed. The parse count is a
     * measurement recorded where the decision is; what has to hold forever is that a repeat gets the
     * same bytes, that a DIFFERENT input is never served the previous answer, and that the single
     * entry being evicted leaves the first value still correct. `ValueConversionTest` covers the
     * behaviour the third parse was serving — `unsanitized_values` on a lossy save — so the memo
     * being wrong would fail there too.
     */
    $type = new RichTextType;

    $lossy = '<p>Hello</p><script>alert(1)</script>';
    $other = '<p>Something else</p>';

    $first = $type->sanitize($lossy);

    expect($type->sanitize($lossy))->toBe($first, 'a repeated value came back different')
        ->and($first)->toBe('<p>Hello</p>', 'the script survived')
        ->and($type->sanitize($other))->toBe($other, 'a different value was served from the memo')
        ->and($type->sanitize($lossy))->toBe($first, 'the evicted value came back wrong');

    // ⚠️ And an empty value returns before the memo, so it can neither poison nor read it.
    expect($type->sanitize('  '))->toBe('  ')
        ->and($type->sanitize($lossy))->toBe($first);
});

it('retains no user HTML once the conversion and its loss check are done', function (): void {
    /*
     * ⚠️ `FieldTypeRegistry` IS A SINGLETON, so this instance lives as long as the application — and
     * review found what the first memo cost: under Octane, a queue worker or a long import it pinned
     * the last submitted body AND its sanitised copy across requests, a substantial fraction of
     * ADR-027's 1 GB floor held for nobody until another value replaced it.
     *
     * ⚠️ ASSERTED BY READING THE PRIVATE PROPERTIES, which is unusual and is the honest instrument
     * here: the difference between "cached" and "released" has no other observable consequence, and
     * asserting it through timing would be a test that passes on a fast machine. The property IS the
     * subject.
     *
     * Measured on a 199 KB value: 398 KB held between the two sanitises, 0 KB after the second.
     */
    $type = new RichTextType;
    $klass = RichTextType::class;

    $held = function () use ($type, $klass): int {
        $bytes = 0;

        foreach (['memoInput', 'memoOutput'] as $name) {
            $value = (new ReflectionProperty($klass, $name))->getValue($type);
            $bytes += is_string($value) ? mb_strlen($value) : 0;
        }

        return $bytes;
    };

    $armed = new ReflectionProperty($klass, 'memoArmed');
    $html = str_repeat('<p>Body text</p>', 200);

    // What a conversion does: arm, then sanitise.
    $armed->setValue($type, true);
    $clean = $type->sanitize($html);

    expect($held())->toBeGreaterThan(0, 'the conversion cached nothing, so the loss check will reparse');

    // What the revision's loss check does: ask the same question once.
    expect($type->sanitize($html))->toBe($clean, 'the memo returned different bytes')
        ->and($held())->toBe(0, 'the singleton is still holding the submitted body');

    // ⚠️ A `sanitize()` OUTSIDE a conversion caches nothing at all, because `sanitize()` is public
    // and §6's contract — a memo filled by every caller leaves a body behind whenever nobody returns.
    $type->sanitize($html);

    expect($held())->toBe(0, 'an unarmed sanitize cached a value nobody will read')
        ->and($type->sanitize($html))->toBe($clean, 'a reparse after release returned the wrong bytes');

    // ⚠️ And an empty value disarms rather than passing the arming to the next caller.
    $armed->setValue($type, true);
    $type->sanitize('   ');
    $type->sanitize($html);

    expect($held())->toBe(0, 'an empty value handed its arming to an unrelated call');
});

it('leaves a child undirected so an ancestor edit can still reach it', function (): void {
    /*
     * ⚠️ THE FIX FOR THE LAST ROUND WAS A ONE-WAY DOOR, which review found. Writing the ancestor's
     * direction onto the child stopped `auto` overriding it — and a materialised `dir="rtl"` is
     * indistinguishable from an author's, so editing the FIGURE to `ltr` and saving again left the
     * caption `rtl` for ever. Measured: no later save could undo it, because the value now looked like
     * a choice to respect.
     *
     * A child under a FIXED ancestor gets nothing at all now. Inheritance was already giving the right
     * answer — the same conclusion as the wrapper one rule along, and the same answer: write nothing
     * rather than write the right thing.
     */
    expect(storedBody('<figure dir="rtl"><figcaption>ACME مرحبا</figcaption></figure>'))
        ->toBe('<figure dir="rtl"><figcaption>ACME مرحبا</figcaption></figure>');

    // ⚠️ THE POINT OF IT: the same stored HTML, with only the ancestor edited, and the caption follows.
    expect(storedBody('<figure dir="ltr"><figcaption>ACME مرحبا</figcaption></figure>'))
        ->toBe('<figure dir="ltr"><figcaption>ACME مرحبا</figcaption></figure>');

    // Two levels, so the walk is not a parent-only check.
    expect(storedBody('<figure dir="rtl"><figcaption><p>ACME مرحبا</p></figcaption></figure>'))
        ->toBe('<figure dir="rtl"><figcaption><p>ACME مرحبا</p></figcaption></figure>');

    // ⚠️ And an `auto` ancestor is still not inherited: the child resolves from its own content.
    expect(storedBody('<figure dir="auto"><figcaption>ACME مرحبا</figcaption></figure>'))
        ->toBe('<figure dir="auto"><figcaption dir="auto">ACME مرحبا</figcaption></figure>');

    // No ancestor direction at all, which is the ordinary case.
    expect(storedBody('<figure><figcaption>ACME مرحبا</figcaption></figure>'))
        ->toBe('<figure><figcaption dir="auto">ACME مرحبا</figcaption></figure>');
});

it('does not override an inherited direction when it wraps a run', function (): void {
    /*
     * ⚠️ THE TWO PASSES HAVE TO AGREE ABOUT WHAT DIRECTION IS IN FORCE. A container no longer carries a
     * materialised copy of its ancestor's, so asking only `ownDirection()` in the wrapper pass would
     * read null for this caption, wrap both runs in `dir="auto"`, and override the `rtl` it inherits —
     * undoing the finding above one rule along, which is exactly how this defect has travelled.
     */
    expect(storedBody('<figure dir="rtl"><figcaption>ACME<p>x</p>مرحبا</figcaption></figure>'))
        ->toBe('<figure dir="rtl"><figcaption><p>ACME</p><p>x</p><p>مرحبا</p></figcaption></figure>');
});

it('bounds what a conversion with no loss check can leave behind', function (): void {
    /*
     * ⚠️ "RELEASED ON READ" ONLY BOUNDS THE CASE WHERE THE READ HAPPENS, and review found the case
     * where it does not: `toStorage()` is the published contract and `BaseFieldType::fromApi()`
     * delegates to it, so an importer or a queue worker can convert a body with no revision loss check
     * after it. Measured, 42 KB left on the singleton by one standalone conversion — and megabytes for
     * a large body, since `FieldTypeRegistry` is a singleton and "left behind" means for the life of
     * the process.
     *
     * ⚠️ A CAP BOUNDS IT RATHER THAN ELIMINATING IT, and that is the honest description. Guaranteeing
     * consumption would need either `Entry` to know about the memo or a public method to ask about the
     * loss — the surface this branch's first two rounds were about. Below the cap a conversion saves a
     * parse and the worst case is `2 × MEMO_LIMIT` per worker; above it the loss check parses again,
     * which measured 17.9 ms at 786 KB.
     */
    $type = new RichTextType;
    $klass = RichTextType::class;

    $held = function () use ($type, $klass): int {
        $bytes = 0;

        foreach (['memoInput', 'memoOutput'] as $name) {
            $value = (new ReflectionProperty($klass, $name))->getValue($type);
            $bytes += is_string($value) ? mb_strlen($value) : 0;
        }

        return $bytes;
    };

    $armed = new ReflectionProperty($klass, 'memoArmed');
    $limit = (new ReflectionClass($klass))->getConstant('MEMO_LIMIT');

    // A body past the cap: the conversion caches nothing, so an abandoned one holds nothing.
    $huge = str_repeat('<p>Body</p>', (int) ceil($limit / 11) + 100);

    expect(mb_strlen($huge))->toBeGreaterThan($limit);

    $armed->setValue($type, true);
    $type->sanitize($huge);

    expect($held())->toBe(0, 'a body past the cap was cached anyway');

    // ⚠️ And the arming does not survive to be handed to the next caller.
    $small = '<p>Small</p>';
    $type->sanitize($small);

    expect($held())->toBe(0, 'the abandoned arming cached an unrelated value');

    // Below the cap the memo still does its job, bounded by the cap itself.
    $armed->setValue($type, true);
    $clean = $type->sanitize($small);

    expect($held())->toBeGreaterThan(0)
        ->and($held())->toBeLessThanOrEqual(2 * $limit)
        ->and($type->sanitize($small))->toBe($clean)
        ->and($held())->toBe(0, 'the read did not release it');
});
