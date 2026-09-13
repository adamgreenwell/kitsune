// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Field values resolve their OWN direction — issue #39, gap G3 of the #12 spike.
 *
 * ⚠️ Every assertion here measures RENDERED direction. Asserting that `dir="auto"`
 * is present proves an attribute; `dir="auto"` can be present and resolve the wrong
 * way, and an attribute test passes either way. That is exactly the mistake the
 * original RTL check made — it visited the English site and asserted `dir="ltr"`,
 * which stays green while every RTL layout is broken.
 *
 * `getComputedStyle(el).direction` returns the direction the browser actually
 * RESOLVED for that element, which for `dir="auto"` means it read the first strong
 * directional character in the value. That is the thing under test.
 */

const SITE = 'golfdom';

/** Seeded by DatabaseSeeder specifically so there is bidirectional content in one org. */
const ARABIC_TITLE = 'صيانة الملاعب في الأسبوع السابع';
const LATIN_TITLE = 'Course maintenance in week 1';

/** The direction the browser resolved for an element, not the attribute it carries. */
async function resolvedDirection(locator) {
    return locator.evaluate((el) => getComputedStyle(el).direction);
}

/*
 * The element that actually RENDERS the value.
 *
 * ⚠️ Not the `<td>`. Filament puts a column's extra attributes on an inner
 * `div.fi-ta-text-item`, so the cell and the link above it stay `ltr` while the
 * element holding the text resolves `rtl`. Measuring the cell fails against a
 * correct implementation — which is how this was found, and why it is worth a
 * named helper rather than a locator repeated four times.
 *
 * `getByText` resolves to the innermost element containing the string, so it finds
 * that div without the test knowing where Filament chose to put the attribute — and
 * without asserting the attribute at all.
 */
function valueElement(page, text) {
    return page.getByText(text, { exact: true }).first();
}

/*
 * The public route reaches every prefix the model can store — issue #38, and three failed attempts.
 *
 * ⚠️ THIS IS THE TEST THAT WOULD HAVE CAUGHT THE THIRD ONE. The skeleton's site route matched a
 * single path segment while `Site::MAX_PREFIX_SEGMENTS` is 4, so a site at `/news/fr` saved, was
 * resolvable, and returned 404. Widening it to a multi-segment pattern then shadowed Filament's
 * `/admin/{tenant}` and broke the dashboard — and the unit test written for the widening asserted
 * that the route file still contained the phrase "declaration order", which stayed true while the
 * admin was broken.
 *
 * A string is not a behaviour. These two assertions are behaviours: a nested prefix resolves, and
 * the admin still answers.
 */
test.describe('the public route reaches what the model stores', () => {
    test('a nested path prefix resolves and is served in its own locale', async ({ page }) => {
        // Hebrew, so this cannot pass by matching the Arabic single-segment site.
        const response = await page.goto('/news/fr');

        expect(response?.status()).toBe(200);
        expect(await resolvedDirection(page.locator('html'))).toBe('rtl');
    });

    test('a prefix nobody claims is still refused', async ({ page }) => {
        // ⚠️ `Route::fallback()` matches every unmatched URL, so the 404 has to come from the route
        // body. Without it the placeholder would render for every wrong URL in the application.
        const response = await page.goto('/news');

        expect(response?.status()).toBe(404);
    });

    test('the admin dashboard is not shadowed by the public route', async ({ page }) => {
        /*
         * ⚠️ THE DASHBOARD SPECIFICALLY, and the first version of this test used
         * `/admin/golfdom/c/article` and proved nothing — it passed against the shadowing route too.
         * The URL that broke is `/admin/golfdom`: two segments, matched by a two-segment public
         * pattern, and the panel's dashboard route is the one that loses. A deeper resource URL was
         * still reached, which is why picking it made the test vacuous.
         *
         * Verified by reverting: with `->get('/{site}')->where('site', '…{0,3}')` this fails and the
         * fallback passes. A fallback cannot shadow anything, because it runs only when nothing else
         * matched — at any depth, including panel routes added later.
         */
        const response = await page.goto(`/admin/${SITE}`);

        expect(response?.status()).toBe(200);

        // The panel's own chrome, which the public placeholder does not render.
        await expect(page.locator('.fi-sidebar, .fi-topbar').first()).toBeVisible();
    });
});

test.describe('a field value carries its own direction', () => {
    test('an RTL title renders RTL inside an LTR admin', async ({ page }) => {
        // ⚠️ The LTR admin is the interesting case: the chrome says `ltr`, and without
        // `dir="auto"` the value inherits that and lays out backwards.
        await page.goto(`/admin/${SITE}/c/article`);

        const value = valueElement(page, ARABIC_TITLE);
        await expect(value).toBeVisible();

        expect(await resolvedDirection(value)).toBe('rtl');
    });

    test('a Latin title still renders LTR in the same list', async ({ page }) => {
        // The other half: `auto` must not flip everything to the first row's direction.
        // A test that only checked the Arabic row would pass on a hard-coded `dir="rtl"`.
        await page.goto(`/admin/${SITE}/c/article`);

        const value = valueElement(page, LATIN_TITLE);
        await expect(value).toBeVisible();

        expect(await resolvedDirection(value)).toBe('ltr');
    });

    test('the edit form resolves each input on its own value', async ({ page }) => {
        // Navigate by the record link rather than by clicking the cell: the cell is a
        // container and the link is what carries the href.
        await page.goto(`/admin/${SITE}/c/article`);
        await page.getByRole('link', { name: ARABIC_TITLE }).first().click();
        await page.waitForURL(/\/(edit|\d+)$/);

        const title = page.getByLabel('Title');
        await expect(title).toBeVisible();

        expect(await resolvedDirection(title)).toBe('rtl');

        // ⚠️ The slug is Latin on the same record, so the form must resolve per FIELD
        // rather than per record. A per-record direction would drag it right-to-left.
        const slug = page.getByLabel('Slug');
        await expect(slug).toBeVisible();

        expect(await resolvedDirection(slug)).toBe('ltr');
    });

    test('a related record resolves its own direction too', async ({ page }) => {
        /*
         * ⚠️ ITS OWN COLUMN, so its own attribute. `ManageEntryRelations::table()` defines
         * a separate `TextColumn::make('title')`, and adding `dir="auto"` to the entry
         * list did nothing here — an attached Arabic-titled record still inherited the
         * panel's direction. Found in review, and the enumeration was short by two: the
         * revisions relation manager defines a third title column.
         *
         * A screen is only as complete as the enumeration behind it.
         */
        await page.goto(`/admin/${SITE}/c/article/1/related`);

        const value = valueElement(page, ARABIC_TITLE);
        await expect(value).toBeVisible();

        expect(await resolvedDirection(value)).toBe('rtl');
    });

    test('every rendered field control resolves its own direction', async ({ page }) => {
        /*
         * ⚠️ THE CONTROLS #39 NAMED AND COULD NOT TEST. Until the seeder defined real
         * fields there was nothing on this page but `title`, `slug` and `status`, so a
         * textarea or a select laying out backwards was not a failing test — it was an
         * unobservable one.
         *
         * Measured by `getComputedStyle`, not by reading `dir`: the attribute can be
         * present and resolve the wrong way, which is the mistake the original RTL check
         * made.
         */
        await page.goto(`/admin/${SITE}/c/article/create`);

        const summary = page.getByLabel('Summary');
        await expect(summary).toBeVisible();

        // Empty, an LTR value, then an RTL value — the same input, three answers.
        await summary.fill('Week seven notes');
        expect(await resolvedDirection(summary)).toBe('ltr');

        await summary.fill('ملاحظات الأسبوع السابع');
        expect(await resolvedDirection(summary)).toBe('rtl');
    });

    test('a number input stays neutral rather than following its digits', async ({ page }) => {
        /*
         * ⚠️ THE OTHER HALF, and the reason this suite is not "everything is auto". A
         * `Neutral` control must NOT carry `dir="auto"` — the value's glyphs are the app's
         * rather than the author's. Without this test, a renderer that set `dir` on
         * everything would pass every other direction test in the file while being wrong
         * about rich text specifically.
         */
        await page.goto(`/admin/${SITE}/c/article/create`);

        const minutes = page.getByLabel('Reading minutes');
        await expect(minutes).toBeVisible();

        expect(await minutes.getAttribute('dir')).toBeNull();
    });

    test("a select carries dir=auto, which its placeholder currently defeats", async ({ page }) => {
        /*
         * ⚠️ THIS TEST PINS A LIMITATION, NOT A FEATURE, and it is written that way on
         * purpose so it fails when the limitation goes away.
         *
         * Measured three times, wrongly twice. `dir="auto"` DOES work on a `<select>` — an
         * isolated `<select dir="auto"><option>الحواجز الرملية</option></select>` computes
         * `rtl`. What defeats it here is Filament's own placeholder: the first option is
         * "Select an option", so the first strong directional character in the element is
         * the `S`, and the select resolves LTR however Arabic its real options are. The
         * seeded `Origin` field has ALL-Arabic labels and still computes `ltr` for exactly
         * that reason.
         *
         * So the renderer is right — the attribute is applied, `Control::Choice` is
         * correctly `Auto`, and a select whose first option were authored text would resolve
         * on it. The gap is in the platform, and it is recorded in
         * `docs/accessibility-inventory.md` rather than hidden behind a passing assertion.
         *
         * Asserting the ATTRIBUTE rather than the computed direction is the exception here,
         * and only because the computed value is known-wrong for a reason outside Kitsune.
         * Everywhere else in this file the computed direction is what is measured.
         */
        await page.goto(`/admin/${SITE}/c/article/create`);

        const arabicOptions = page.getByLabel('Origin');
        await expect(arabicOptions).toBeVisible();

        expect(await arabicOptions.getAttribute('dir')).toBe('auto');

        // ⚠️ The limitation itself, asserted so it cannot quietly change. If Filament stops
        // emitting a Latin placeholder first, this flips to `rtl` and this test fails —
        // which is the notification that the residual can be removed.
        expect(await resolvedDirection(arabicOptions)).toBe('ltr');

        // And the platform `status` select, which is hand-written above the renderer and
        // deliberately carries no direction: its options are app-chosen, not authored.
        const status = page.getByLabel('Status');
        expect(await status.getAttribute('dir')).toBeNull();
    });

    test('rich text carries a direction per block, in the editor too', async ({ page }) => {
        /*
         * ⚠️ TWO FACTS, AND ONLY ONE OF THEM IS GOOD NEWS. Issue #39 called rich text the
         * awkward case, and it is awkward in a way the inventory did not anticipate.
         *
         * The STORED value is right. `RichTextType::toStorage()` stamps `dir="auto"` on
         * every text-bearing block and leaves containers alone, so the value in the
         * database resolves per paragraph — asserted by `RichTextBlockDirectionTest`, and
         * visible in the seeded row:
         *
         *   <p dir="auto">Maintenance notes…</p>
         *   <p dir="auto">ملاحظات الصيانة…</p>
         *   <ul><li dir="auto">Mow the fairway</li><li dir="auto">تنظيف…</li></ul>
         *
         * The EDITOR was not, and issue #67 is that half. Filament's rich editor is
         * TipTap/ProseMirror: it parses the stored HTML into its own document model and
         * re-renders it, and TipTap drops every attribute a node's schema does not
         * declare. So the value was right in the database, right for any consumer, and
         * wrong in the one place an author looks while writing it.
         *
         * ⚠️ THIS TEST USED TO ASSERT THE GAP, on purpose — `dir` null and the resolved
         * direction `ltr` on Arabic text — so that a Filament or TipTap release which
         * started preserving the attribute would fail it and the failure would be the
         * notification. `BlockDirectionPlugin` closes it from our side instead, by
         * declaring `dir` on the node types `Entry` stamps, so the assertion is inverted:
         * it now measures the direction the browser RESOLVED for each block inside the
         * editor, which is the thing the issue's done-when names.
         */
        await page.goto(`/admin/${SITE}/c/article`);
        await page.getByRole('link', { name: ARABIC_TITLE }).first().click();
        await page.waitForURL(/\/(edit|\d+)$/);

        const arabicItem = page.locator('.tiptap li', { hasText: 'تنظيف' }).first();
        await expect(arabicItem).toBeVisible();

        /*
         * ⚠️ THE RESOLVED DIRECTION, NOT THE ATTRIBUTE, for the reason this file opens with:
         * `dir="auto"` can be present and resolve the wrong way. `rtl` here is the browser
         * having read the first strong directional character of THIS list item.
         */
        expect(await resolvedDirection(arabicItem)).toBe('rtl');

        /*
         * ⚠️ AND THE ENGLISH SIBLING MUST STILL RESOLVE `ltr`, which is the half that says
         * the direction is per block rather than per field. A `dir="rtl"` on the list, or
         * one editor-wide direction, would pass the assertion above and fail this one.
         */
        const englishItem = page.locator('.tiptap li', { hasText: 'Mow the fairway' }).first();
        await expect(englishItem).toBeVisible();
        expect(await resolvedDirection(englishItem)).toBe('ltr');

        // Both paragraphs, the same way: the stored value has one of each.
        const arabicParagraph = page.locator('.tiptap p', { hasText: 'ملاحظات' }).first();
        const englishParagraph = page.locator('.tiptap p', { hasText: 'Maintenance notes' }).first();
        await expect(arabicParagraph).toBeVisible();
        expect(await resolvedDirection(arabicParagraph)).toBe('rtl');
        expect(await resolvedDirection(englishParagraph)).toBe('ltr');

        /*
         * ⚠️ AND THE CONTAINER MUST STAY UNDIRECTED EITHER WAY. This is the half that is
         * Kitsune's own and would be a real regression: a `dir` on `ul` would be inherited
         * by every `li`, so an English first item would drag an Arabic second item
         * left-to-right — the per-field failure reproduced one level down. It holds in the
         * editor because `BlockDirectionPlugin` declares `dir` with a NULL DEFAULT: the
         * containers are in its node list so that an author's own `<ul dir="rtl">` survives
         * the round trip, and nothing ever puts a direction there that was not written.
         * Filament's own `textDirection` extension is deliberately not used because its
         * option defaults the attribute onto every node type, which is what this asserts
         * cannot happen.
         */
        const list = page.locator('.tiptap ul').first();
        await expect(list).toBeVisible();
        expect(await list.getAttribute('dir')).toBeNull();
    });

    test('the editable editor resolves each block too, not only the read-only one', async ({ page }) => {
        /*
         * ⚠️ THE EDIT PAGE, NOT THE ONE THE LIST LINKS TO. Following an entry's title reaches its VIEW
         * page, where the editor renders the stored content read-only — which is what the test above
         * measures. This one measures the instance an author actually types into.
         */
        await page.goto(`/admin/${SITE}/c/article`);
        await page.getByRole('link', { name: ARABIC_TITLE }).first().click();
        await page.getByRole('link', { name: /^edit$/i }).first().click();
        await page.waitForURL(/\/edit$/);

        const editor = page.locator('.tiptap[contenteditable="true"]').first();
        await expect(editor).toBeVisible();

        const arabic = editor.locator('p', { hasText: 'ملاحظات' }).first();
        const english = editor.locator('p', { hasText: 'Maintenance notes' }).first();
        await expect(arabic).toBeVisible();

        expect(await resolvedDirection(arabic)).toBe('rtl');
        expect(await resolvedDirection(english)).toBe('ltr');

        /*
         * ⚠️ AND WHAT IS *NOT* ASSERTED HERE, recorded so the gap is a decision rather than an oversight.
         * A block split — pressing Enter at the end of a directed block — must not copy that block's
         * direction onto the new one, which is why the extension declares `keepOnSplit: false` against
         * TipTap's default of true. That is asserted where it can be:
         * `RichEditorDirectionAssetTest` reads it out of the module. It is NOT asserted here because the
         * harness cannot place the caret: `click()`, `Control+Home` and arrow navigation all leave it at
         * the document start, where a split truncates the first block rather than creating a new one — the
         * one path `keepOnSplit` does not govern. A test written there passes with the option and without
         * it, which is worse than no test. Proving it in the browser needs a way to focus the editor and a
         * fixture with a FIXED direction, since the seeded value is `auto` throughout and inheriting
         * `auto` is harmless.
         */
    });

    /*
     * ⚠️ WHAT IS NOT ASSERTED, AND WHY THE OBVIOUS FIX FOR IT IS WRONG. A block the author has just
     * created has no stored direction to preserve — `Entry` stamps `auto` on the way INTO storage, which
     * is too late to help while typing — so Arabic typed into a NEW paragraph renders in the chrome's
     * direction until the value is saved. Review asked for a default of `auto` to close that.
     *
     * Measured, that default costs more than it buys. It also lands on the paragraph INSIDE a list item,
     * and `dir="auto"` resolves from an element's text EXCLUDING any descendant that has its own
     * direction — so on the seeded list the browser reported:
     *
     *   LI[auto]=ltr   wrapping   P[auto]=rtl
     *
     * The text flowed right-to-left while the item's own direction went left-to-right, which puts the
     * bullet on the wrong side. No static default can tell a top-level paragraph from one inside a list
     * item, because they are the same node type; closing the gap needs a handler that knows a block's
     * parent, and that is recorded in `docs/accessibility-inventory.md` rather than traded for a visible
     * regression in already-stored content.
     */

    test('typing RTL text into an empty field flips it live', async ({ page }) => {
        // `dir="auto"` is evaluated by the browser as the value changes, so a new entry
        // gets the same behaviour without the server knowing anything about direction.
        await page.goto(`/admin/${SITE}/c/article/create`);

        const title = page.getByLabel('Title');
        await expect(title).toBeVisible();

        await title.fill('Hello');
        expect(await resolvedDirection(title)).toBe('ltr');

        await title.fill('مرحبا');
        expect(await resolvedDirection(title)).toBe('rtl');
    });
});

