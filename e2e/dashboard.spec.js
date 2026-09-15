// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The dashboard says something — the alpha pass found it empty.
 *
 * A new install opened onto Filament's dashboard with no widgets: a heading and nothing under it. It now counts
 * each entry type the sidebar offers and lists what changed most recently. `DashboardWidgetsTest` pins which
 * types, counts and rows; this pins that the page renders them and that each link lands.
 *
 * ⚠️ NO COUNT IS ASSERTED AS A NUMBER. Other specs in this project create and delete entries, so the numbers on
 * the page are not this spec's to know — the reason `permissions.spec.js` asserts a table rather than a row.
 *
 * The session is an owner's. What a copy-editor sees is in `permissions.spec.js`.
 */

const SITE = 'golfdom';

test.describe('dashboard', () => {
    test('counts each type the sidebar offers, linking to its list', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        const stats = page.locator('a.fi-wi-stats-overview-stat');

        await expect(stats.filter({ hasText: 'Articles' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/article$`));
        await expect(stats.filter({ hasText: 'Products' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/product$`));

        // Turned off for this site (ADR-022), so absent here exactly as it is from the sidebar.
        await expect(stats.filter({ hasText: 'Podcasts' })).toHaveCount(0);
    });

    test('lists recently updated entries, each opening its own entry', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        const row = page.locator('.fi-wi-table .fi-ta-row').first();
        await expect(row).toBeVisible();

        await row.locator(`a[href*="/admin/${SITE}/c/"]`).first().click();

        // The view page, which is the grant that listed the row, rather than a form an editor may be refused.
        await page.waitForURL(new RegExp(`/admin/${SITE}/c/[a-z0-9_-]+/\\d+$`));
    });
});
