// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Per-type authorization, measured in a browser — ADR-033, issue #81, and Phase 4's last unchecked line.
 *
 * ⚠️ THE LINK IS NOT THE GUARANTEE, and this file exists because the PHP suite structurally cannot say so.
 * Hiding a navigation item an authenticated user can still reach by typing the URL is the classic shape of
 * this bug: the panel looks correct, the boundary is not there, and every unit test passes because it asks
 * the policy directly rather than asking the application. ADR-024 makes the browser layer mandatory for
 * exactly this class.
 *
 * The session here belongs to `reader@kitsune.test`, a copy-editor: `entry.article.view` and
 * `entry.article.update`, and deliberately nothing else. An owner cannot measure a permission system, and a
 * user with only `view` cannot reach the form on which `publish` is enforced.
 */

const SITE = 'golfdom';

test.describe('a user holds only what was granted', () => {
    test('reaches the entry type they may view', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/article`);

        expect(response?.status()).toBe(200);

        /*
         * ⚠️ THE TABLE, NOT A PARTICULAR ROW, and the first version asserted a row. It named a seeded
         * article and passed in isolation while failing in the full suite — other specs write entries, the
         * list sorts by `updated_at desc` and pages at ten, so which rows are on page one is not this
         * spec's to know. A fixture another test can move is a fixture that fails for the wrong reason.
         */
        await expect(page.locator('.fi-ta').first()).toBeVisible();
    });

    test('is refused at the URL for a type they may not view', async ({ page }) => {
        /*
         * ⚠️ 403 AND NOT 404, which is worth being precise about. `IdentifyEntryType` 404s a type that does
         * not exist, belongs to another org, or is disabled for this site — a tenancy boundary. This type
         * passes all three and the refusal is an authorization one, so conflating them would hide a tenancy
         * failure behind a permission message or the reverse.
         */
        const response = await page.goto(`/admin/${SITE}/c/product`);

        expect(response?.status()).toBe(403);
    });

    test('is refused at the URL for an action they may not take', async ({ page }) => {
        // `view` on articles is held; `create` is not, and they are separate permissions rather than levels.
        const response = await page.goto(`/admin/${SITE}/c/article/create`);

        expect(response?.status()).toBe(403);
    });

    test('is offered no link to a type they may not view', async ({ page }) => {
        /*
         * The other half, and the one a user actually experiences. Navigation is supplied explicitly by
         * `KitsunePanel`, so Filament never asks a resource whether an item should appear — the filter is
         * Kitsune's own, and this is what asserts it runs.
         */
        await page.goto(`/admin/${SITE}/c/article`);

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Articles' })).toBeVisible();
        await expect(sidebar.getByRole('link', { name: 'Products' })).toHaveCount(0);
    });

    test('is offered no published status, because publishing is its own permission', async ({ page }) => {
        /*
         * ⚠️ THE OPTIONS ARE THE VISIBLE HALF ONLY. `EntryResource` also validates the value against the
         * same list, because a hand-built request never opens the select — and a permission enforced only
         * by what a page renders is a permission enforced only against people who use the page. That half
         * is asserted in PHP, where a request can be built without a browser.
         *
         * ⚠️ AND IT IS THE EDIT FORM, WHICH IS WHY THE FIXTURE HOLDS `update`. Create is refused for this
         * user — the assertion two tests up — so the form that carries the control has to be reached the
         * other way.
         */
        await page.goto(`/admin/${SITE}/c/article`);

        // Whichever row is first; which rows are on page one is not this spec's business.
        await page.locator('.fi-ta a[href*="/c/article/"]').first().click();
        await page.waitForURL(/\/c\/article\/\d+/);
        await page.getByRole('link', { name: /^edit$/i }).first().click();
        await page.waitForURL(/\/edit$/);

        const status = page.locator('select[id$="status"]');
        await expect(status).toBeVisible();

        // Trimmed: `allInnerTexts()` returns the option markup's whitespace with it, so an untrimmed
        // `toContain('Draft')` fails on a list that plainly contains Draft.
        const options = (await status.locator('option').allInnerTexts()).map((text) => text.trim());

        expect(options).toContain('Draft');
        expect(options).toContain('Archived');
        expect(options).not.toContain('Published');
    });
});
