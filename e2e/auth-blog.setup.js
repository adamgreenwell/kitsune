// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');

/*
 * Signs in the two people the Blog blueprint's browser test needs — Phase 5, ADR-039.
 *
 * ⚠️ IN AN ORG OF THEIR OWN. `inkwell` holds nothing but what `kitsune:blueprint apply blog` wrote, so the owner
 * measures what the command created and the writer measures the role it created — assigned to her in
 * `e2e/global-setup.js` through the audited path, because a blueprint assigns nobody.
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

setup('authenticate as the blog owner', async ({ page }) => {
    await signIn(page, 'blog-owner@kitsune.test', 'blog-owner-auth.json');
});

setup('authenticate as the blog writer', async ({ page }) => {
    await signIn(page, 'blog-writer@kitsune.test', 'blog-writer-auth.json');
});
