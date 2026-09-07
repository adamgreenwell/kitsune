// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');

const authFile = path.join(__dirname, '..', '.playwright', 'admin-auth.json');

/*
 * Signs in once and saves the session for every admin spec to reuse.
 *
 * Signing in per test meant nine sign-ins against one dev server and one
 * SQLite file, which was intermittently timing out — and reads as a broken
 * selector rather than as contention, which cost a couple of rounds to see.
 * Authenticating once is also what Playwright recommends.
 */
setup('authenticate', async ({ page }) => {
    await page.goto('/admin/login');

    await page.locator('input[type="email"]').fill('alpha@kitsune.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(
        (url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'),
        { timeout: 30_000 },
    );

    await expect(page.locator('body')).not.toContainText('Sign in');

    await page.context().storageState({ path: authFile });
});
