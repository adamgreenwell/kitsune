// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Smoke coverage for the installable skeleton.
 *
 * Thin on purpose: the public side is a placeholder until theming (ADR-011).
 * What this proves is that the browser layer itself works end to end —
 * Playwright boots the app, reaches it over HTTP, and can assert on rendered
 * output. The admin's standing regression test CONTRIBUTING requires (a page
 * loaded from OUTSIDE /c/{type}) lives in `admin.spec.js`.
 */

test.describe('skeleton', () => {
    test('renders and reports a booted core', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveTitle(/Kitsune/);
        await expect(page.getByTestId('boot-status')).toHaveText('Booted');
        await expect(page.getByTestId('core-version')).not.toBeEmpty();
    });

    test('defaults to SQLite, so a fresh install needs no database server', async ({ page }) => {
        await page.goto('/');

        // ADR-026 and ADR-027: the one-command install and the resource floor
        // both depend on the default install needing no database server.
        await expect(page.getByTestId('db-driver')).toHaveText('sqlite');
    });

    test('runs on a PHP that meets the floor', async ({ page }) => {
        await page.goto('/');

        const version = await page.getByTestId('php-version').textContent();
        const [major, minor] = (version ?? '').split('.').map(Number);

        // ADR-013 pins ^8.4.
        expect(major).toBeGreaterThanOrEqual(8);
        if (major === 8) {
            expect(minor).toBeGreaterThanOrEqual(4);
        }
    });

    test('points a visitor at the admin instead of saying there is none', async ({ page }) => {
        await page.goto('/');

        // ⚠️ The page said "There is no admin panel yet" for three phases after the admin shipped, while
        // the README sent the same visitor to /admin. Asserted by following the link, not by reading it.
        await expect(page.locator('body')).not.toContainText('no admin panel');

        const link = page.getByTestId('admin-link');
        await expect(link).toHaveAttribute('href', /\/admin$/);

        await link.click();
        await page.waitForURL(/\/admin\/login/);
        await expect(page.locator('input[type="email"]')).toBeVisible();
    });

    test('exposes a health endpoint for the installer to poll', async ({ request }) => {
        const response = await request.get('/up');
        expect(response.status()).toBe(200);
    });
});
