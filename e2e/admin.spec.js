// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The admin loop, and the isolation boundary underneath it.
 *
 * These exist because the two worst bugs found while building this panel
 * were both invisible to the PHP suite: the navigation item that 500d every
 * page outside /c/{type}, and the tenant bootstrap cycle that 404d /admin.
 * Neither is reachable without rendering a real page in a real browser.
 */

const SITE = 'golfdom';

test.describe('admin', () => {
    test('reaches the dashboard, which lives OUTSIDE /c/{type}', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        // The standing regression test CONTRIBUTING requires. Filament calls
        // getUrl() on every Resource nav item while rendering the sidebar;
        // with {type} in the URI and none in this URL, that throws unless
        // $shouldRegisterNavigation is false.
        await expect(page).toHaveTitle(/Dashboard/);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });

    test('lists entries of a type', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        await expect(page).toHaveTitle(/Entries/);
        await expect(page.getByText('Course maintenance in week 1')).toBeVisible();
    });

    test('opens an entry for editing and drives Livewire', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);
        await page.getByRole('link', { name: 'Edit' }).first().click();

        await expect(page).toHaveURL(/\/c\/article\/\d+\/edit/);
        // Filament generates its own input ids, so match on the wire binding,
        // which is stable and states what the field actually is.
        const title = page.locator('input[wire\\:model="data.title"]');
        await expect(title).toHaveCount(1);
        await expect(title).not.toBeEmpty();
    });

    test('creates an entry, stamping the type the URL identified', async ({ page }) => {
        // Regression: entry_type_id is NOT NULL and appears in no form field,
        // so before CreateEntry stamped it, every create failed on a database
        // constraint. Caught in review, never by a test — because no test
        // exercised create at all.
        await page.goto(`/admin/${SITE}/c/product/create`);

        const title = `Spec-created product ${Date.now()}`;
        await page.locator('input[wire\\:model="data.title"]').fill(title);
        await page.locator('form').getByRole('button', { name: /Create/i }).first().click();

        // Filament redirects to the edit form, where the title is an input
        // value rather than page text.
        await expect(page).toHaveURL(/\/c\/product\/\d+/, { timeout: 15_000 });
        await expect(page.locator('input[wire\\:model="data.title"]')).toHaveValue(title);

        // And it must land under the type the URL identified, not another.
        await page.goto(`/admin/${SITE}/c/product`);
        await expect(page.getByText(title).first()).toBeVisible();
    });

    test('emits no URL with an empty {type} segment', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        const hrefs = await page.locator('a[href*="/c/"]').evaluateAll(
            (links) => links.map((a) => a.getAttribute('href')),
        );

        expect(hrefs.length).toBeGreaterThan(0);
        expect(hrefs.filter((h) => /\/c\/(\/|$|\?)/.test(h ?? ''))).toEqual([]);
    });
    test('drives a ManageRelatedRecords PAGE under {type}', async ({ page }) => {
        // Spike #10, the last untested corner of ADR-012's URL contract.
        // RelationManager *components* register no routes; this construct is
        // a resource page and does, which is why it needed covering
        // separately.
        await page.goto(`/admin/${SITE}/c/article/1/related`);

        await expect(page).toHaveTitle(/Related entries/);
        await expect(page.getByText('Course maintenance in week 2')).toBeVisible();

        const hrefs = await page.locator('a[href*="/c/"]').evaluateAll(
            (links) => links.map((a) => a.getAttribute('href')),
        );
        expect(hrefs.filter((h) => /\/c\/(\/|$|\?)/.test(h ?? ''))).toEqual([]);
    });
});

test.describe('isolation, from the attacker side', () => {
    test('404s an entry type belonging to another org', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/confidential`);

        // Seeded against Rival Publishing. {type} is user-controlled input
        // and this is a security boundary, not a convenience (ADR-012).
        expect(response?.status()).toBe(404);
    });

    test('404s an entry type that does not exist', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/nonsense`);

        expect(response?.status()).toBe(404);
    });

    test('allows a global system type, which every org shares', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/image`);

        // org_id NULL means available to all — the pattern ADR-021 reuses.
        expect(response?.status()).toBe(200);
    });

    test('does not show another org\'s content in any list', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        await expect(page.getByText('Should never be visible from Golfdom')).toHaveCount(0);
    });

    test('does not offer another org\'s type in navigation', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        await expect(page.getByRole('link', { name: 'Articles' })).toBeVisible();
        await expect(page.getByText('Confidential')).toHaveCount(0);
    });
});
