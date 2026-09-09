// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');

const authFile = path.join(__dirname, '..', '.playwright', 'admin-rtl-auth.json');

/*
 * Signs in as the editor whose UI locale is Arabic.
 *
 * ⚠️ A SEPARATE SESSION, not a separate server, and that is the whole point. The RTL
 * project used to run a second `php artisan serve` with `APP_LOCALE=ar`, because the
 * admin's direction came from `__('filament-panels::layout.direction')` and was
 * therefore fixed for the life of the process.
 *
 * Issue #38 made the locale resolve per REQUEST from the viewer's own preference
 * (ADR-018 rule 2), so an environment variable is no longer how an operator reaches an
 * RTL admin — and a fixture built on one would prove something Kitsune no longer does.
 * Two users on one server is the mechanism as shipped.
 */
setup('authenticate as the Arabic-preferring editor', async ({ page }) => {
    await page.goto('/admin/login');

    await page.locator('input[type="email"]').fill('alpha-rtl@kitsune.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(
        (url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'),
        { timeout: 30_000 },
    );

    await expect(page.locator('body')).not.toContainText('Sign in');

    await page.context().storageState({ path: authFile });
});
