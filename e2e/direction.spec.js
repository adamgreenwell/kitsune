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

