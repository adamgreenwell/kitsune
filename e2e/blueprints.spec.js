// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { seedBlueprintAdmin, state, reverseUnused } = require('./blueprint-admin-fixture');

test.beforeAll(() => seedBlueprintAdmin());

async function ownerPage(browser, storageState = '.playwright/admin-auth.json') {
    const context = await browser.newContext({ storageState });
    return { context, page: await context.newPage() };
}

function section(page, handle) {
    return page.locator('.fi-section').filter({ has: page.getByRole('heading', { name: handle, exact: true }) }).first();
}

test('owner reaches Blueprints from the dashboard and applies then reverses unused structure', async ({ browser }) => {
    const { context, page } = await ownerPage(browser);
    try {
        await page.goto('/admin/blueprint-admin');
        await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
        await page.getByRole('link', { name: 'Blueprints', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/blueprint-admin\/blueprints$/);
        await expect(page.getByRole('heading', { name: 'Blueprints', exact: true })).toBeVisible();
        await section(page, 'marketing-site').getByRole('button', { name: 'Apply or update', exact: true }).click();
        const dialog = page.locator('.fi-modal-window').filter({ visible: true });
        await expect(dialog).toContainText('Blueprint review blueprint-admin');
        await expect(dialog).toContainText('not a successful dry run');
        const confirmation = dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' });
        await confirmation.fill('marketing-site');
        await dialog.getByRole('button', { name: 'Confirm apply or update', exact: true }).click();
        await expect(dialog).toContainText('confirmation or reviewed state no longer matches');
        expect(state('blueprint-admin').blueprints.count).toBe(0);
        await expect(dialog).toHaveCSS('opacity', '1');
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await confirmation.fill('marketing-site @ blueprint-admin');
        await dialog.getByRole('button', { name: 'Confirm apply or update', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(section(page, 'marketing-site')).toContainText('Installed: 1.1.0');
        expect(state('blueprint-admin').entries.count).toBe(0);
        await section(page, 'marketing-site').getByRole('button', { name: 'Review reverse', exact: true }).click();
        await expect(dialog).toContainText('Would remove if nothing blocks');
        await dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' }).fill('marketing-site @ blueprint-admin');
        await dialog.getByRole('button', { name: 'Confirm reverse', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        expect(state('blueprint-admin').blueprints.count).toBe(0);
        await page.goto('/admin/blueprint-admin');
        await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
    } finally { await context.close(); }
});

test('names content obstacles and refuses reverse without changing retained rows', async ({ browser }) => {
    const { context, page } = await ownerPage(browser);
    try {
        const before = state('blueprint-blocked');
        await page.goto('/admin/blueprint-blocked/blueprints');
        await section(page, 'blog').getByRole('button', { name: 'Review reverse', exact: true }).click();
        const dialog = page.locator('.fi-modal-window').filter({ visible: true });
        await expect(dialog).toContainText('Blocks this reverse');
        await expect(dialog).toContainText('post still has 1 entry');
        await dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' }).fill('blog @ blueprint-blocked');
        await dialog.getByRole('button', { name: 'Confirm reverse', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Blueprint action did not complete', exact: true })).toBeVisible();
        expect(state('blueprint-blocked')).toEqual(before);
    } finally { await context.close(); }
});

test('refuses a stale review, a non-owner and another org owner at the actual URL', async ({ browser }) => {
    const { context, page } = await ownerPage(browser);
    try {
        await page.goto('/admin/blueprint-stale/blueprints');
        await section(page, 'blog').getByRole('button', { name: 'Apply or update', exact: true }).click();
        const dialog = page.locator('.fi-modal-window').filter({ visible: true });
        await expect(dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' })).toBeVisible();
        reverseUnused('blueprint-stale', 'blog');
        await dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' }).fill('blog @ blueprint-stale');
        await dialog.getByRole('button', { name: 'Confirm apply or update', exact: true }).click();
        await expect(dialog).toContainText('confirmation or reviewed state no longer matches');
        expect(state('blueprint-stale').blueprints.count).toBe(0);
    } finally { await context.close(); }

    for (const storageState of ['.playwright/admin-reader-auth.json', '.playwright/admin-rival-auth.json']) {
        const actor = await ownerPage(browser, storageState);
        try {
            const response = await actor.page.goto('/admin/blueprint-admin/blueprints');
            expect([403, 404]).toContain(response.status());
            await expect(actor.page.getByRole('heading', { name: 'Blueprints', exact: true })).not.toBeVisible();
        } finally { await actor.context.close(); }
    }
});

test('RTL owner can use the review by keyboard with focus return, mobile layout and accessible labels', async ({ browser }, testInfo) => {
    const { context, page } = await ownerPage(browser, '.playwright/admin-rtl-auth.json');
    try {
        await page.goto('/admin/blueprint-rtl/blueprints');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        const trigger = section(page, 'blog').getByRole('button', { name: 'Apply or update', exact: true });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const dialog = page.locator('.fi-modal-window').filter({ visible: true });
        const input = dialog.getByRole('textbox', { name: 'Confirm blueprint and organisation' });
        await expect(input).toBeVisible();
        await input.focus();
        await input.fill('blog @ blueprint-rtl');
        await expect(input).toBeFocused();
        // As on Credentials, scan the rendered modal after its fade-in rather than measuring transition colours.
        await expect(dialog).toHaveCSS('opacity', '1');
        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(axe.violations).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath('blueprints-rtl-desktop.png') });
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(trigger).toBeFocused();
        // The form disappears before the containing overlay finishes its leave transition.
        await expect(page.locator('.fi-modal').filter({ visible: true })).toHaveCount(0);
        await page.setViewportSize({ width: 375, height: 812 });
        await page.emulateMedia({ colorScheme: 'dark' });
        // A fresh mobile load starts with its drawer closed rather than capturing the desktop drawer transition.
        await page.reload();
        await page.getByRole('heading', { name: 'Blueprints', exact: true }).scrollIntoViewIfNeeded();
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(page.locator('.fi-sidebar')).not.toBeInViewport();
        const bounds = await section(page, 'blog').boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(375);
        await page.screenshot({ path: testInfo.outputPath('blueprints-rtl-mobile.png') });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(state('blueprint-rtl').blueprints.count).toBe(0);
    } finally { await context.close(); }
});
