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

    await page.context().storageState({ path: file });
}

setup('authenticate', async ({ page }) => {
    await signIn(page, 'alpha@kitsune.test', authFile);
});

/*
 * ⚠️ AND THE OTHER ORG'S OWNER, as the positive control for every cross-org refusal. A spec asserting that
 * Golfdom cannot see the rival's file passes just as well when the file was never seeded, or when a broken
 * list shows nobody anything; the rival being shown it, in their own site, is what makes the refusal mean
 * something. Signed in here rather than in a spec for the reason this file exists at all.
 */
setup('authenticate as the rival org\'s owner', async ({ page }) => {
    await signIn(page, 'rival@kitsune.test', path.join(__dirname, '..', '.playwright', 'admin-rival-auth.json'));
});
