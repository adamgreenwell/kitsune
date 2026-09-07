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

    test('emits no URL with an empty {type} segment', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        const hrefs = await page.locator('a[href*="/c/"]').evaluateAll(
            (links) => links.map((a) => a.getAttribute('href')),
        );

        expect(hrefs.length).toBeGreaterThan(0);
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
