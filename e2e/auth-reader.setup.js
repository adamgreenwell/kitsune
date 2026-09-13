// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');

const authFile = path.join(__dirname, '..', '.playwright', 'admin-reader-auth.json');

/*
 * Signs in as the user who holds exactly one grant — ADR-033.
 *
 * ⚠️ A THIRD SESSION, because the two that existed are both owners and an owner cannot measure a permission
 * system. The seeded `reader@kitsune.test` holds `entry.article.view` and nothing else, which is the only
 * fixture that can tell "the sidebar hid the link" apart from "the URL refused the request".
 */
setup('authenticate as the reader', async ({ page }) => {
    await page.goto('/admin/login');

    await page.locator('input[type="email"]').fill('reader@kitsune.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(
        (url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'),
        { timeout: 30_000 },
    );

    await expect(page.locator('body')).not.toContainText('Sign in');

    await page.context().storageState({ path: authFile });
});
