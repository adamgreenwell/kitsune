// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('node:path');
const { BLOG_OWNER } = require('./accounts');

/*
 * Signs in the ~~two~~ three people the blueprints' browser tests need — Phase 5, ADR-039: Blog's owner and writer,
 * and the Marketing Site's writer, in the same org.
 *
 * ⚠️ IN AN ORG OF THEIR OWN. `inkwell` holds no entry type or role but ~~its seeded owner role and~~ what
 * `kitsune:blueprint apply blog --owner` wrote, so the owner measures what the command created and the writer measures
 * the role it created — assigned to her in `e2e/global-setup.js` through the audited path, because a blueprint assigns
 * nobody.
 *
 * ⚠️ THE OWNER SIGNS IN WITH THE PASSWORD THE COMMAND WAS GIVEN (ADR-026, as amended): their account is the one
 * `--owner` created, so this sign-in is the proof it can be signed in to. The writer is the seeder's, as before.
 */

/**
 * Signs one person in, waiting out the login throttle if it is in the way.
 *
 * ⚠️ FILAMENT ALLOWS FIVE SIGN-INS A MINUTE FROM ONE ADDRESS, and the suite's other setups use all five before these
 * run — so in a full run the sixth click is refused with "Too many login attempts", the page stays on the form, and
 * the wait below timed out reading as a broken sign-in. The throttle is the product's and stays; this waits out the
 * window its notification names, then signs in again.
 */
async function signIn(page, email, password, file) {
    await page.goto('/admin/login');

    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);

    for (let attempt = 0; attempt < 2; attempt++) {
        await page.locator('form').getByRole('button', { name: 'Sign in' }).click();

        const throttled = page.locator('.fi-no-notification').filter({ hasText: 'Too many login attempts' });
        // Each wait settles to a word rather than rejecting, so the one that loses the race leaves nothing unhandled.
        const outcome = await Promise.race([
            page.waitForURL((url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'), { timeout: 30_000 })
                .then(() => 'signed in', () => 'timed out'),
            throttled.first().waitFor({ timeout: 30_000 }).then(() => 'throttled', () => 'timed out'),
        ]);

        if (outcome !== 'throttled') {
            break;
        }

        const wait = Number((await throttled.first().innerText()).match(/try again in (\d+) seconds/)?.[1] ?? 60);
        await page.waitForTimeout((wait + 1) * 1000);

        // And the refusal's toast gone, so the next attempt's outcome is not read from the last one's.
        await expect(throttled).toHaveCount(0, { timeout: 15_000 });
    }

    await expect(page).not.toHaveURL(/\/login$/);
    await expect(page.locator('body')).not.toContainText('Sign in');

    await page.context().storageState({ path: path.join(__dirname, '..', '.playwright', file) });
}

/* Long enough to wait out one throttle window, which is a minute at most. */
setup.describe.configure({ timeout: 120_000 });

setup('authenticate as the blog owner', async ({ page }) => {
    await signIn(page, BLOG_OWNER.email, BLOG_OWNER.password, 'blog-owner-auth.json');
});

setup('authenticate as the blog writer', async ({ page }) => {
    await signIn(page, 'blog-writer@kitsune.test', 'password', 'blog-writer-auth.json');
});

setup('authenticate as the marketing writer', async ({ page }) => {
    await signIn(page, 'marketing-writer@kitsune.test', 'password', 'marketing-writer-auth.json');
});
