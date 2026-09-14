// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');

/*
 * Signs in the sessions that are not owners — ADR-033.
 *
 * ⚠️ NOT OWNERS, because the two sessions that existed first both are, and an owner cannot measure a permission
 * system. The seeded `reader@kitsune.test` is a copy-editor holding `entry.article.view` and
 * `entry.article.update`: the fixture that can tell "the sidebar hid the link" apart from "the URL refused the
 * request", and that can reach the form on which `publish` is enforced.
 *
 * ⚠️ AND `viewer@kitsune.test` HOLDS `entry.article.view` ALONE, because the copy-editor's `update` is exactly
 * what makes a restore allowed. Both are signed in here so the one project that measures permissions depends
 * on one setup.
 */
async function signIn(page, email, file) {
    await page.goto('/admin/login');

    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill('password');
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(
        (url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'),
        { timeout: 30_000 },
    );

    await expect(page.locator('body')).not.toContainText('Sign in');

    await page.context().storageState({ path: path.join(__dirname, '..', '.playwright', file) });
}

setup('authenticate as the reader', async ({ page }) => {
    await signIn(page, 'reader@kitsune.test', 'admin-reader-auth.json');
});

setup('authenticate as the viewer', async ({ page }) => {
    await signIn(page, 'viewer@kitsune.test', 'admin-viewer-auth.json');
});
