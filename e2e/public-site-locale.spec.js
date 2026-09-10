// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * A PUBLIC request is served in its site's locale — issue #38, gap G2.
 *
 * ⚠️ This is the half that middleware alone could not close, and the reason it stayed
 * open. `SetSiteLocale` existed and was unit-tested, but nothing on the public side ever
 * put a Site in Context for it to read: `SetKitsuneContext` mirrors Filament's tenant,
 * and the public side has no panel. So `sites.locale` was applied by nothing exactly
 * where a reader would notice.
 *
 * ⚠️ NO APP_LOCALE ANYWHERE. This runs against the ordinary server on the ordinary port,
 * whose app locale is the default. Direction here comes from a row in the database and
 * from the request that named it — which is the criterion the issue actually states, and
 * the thing an operator can do without touching the environment.
 */

/** The document's own answer, not an attribute we hope means something. */
async function documentDirection(page) {
    return page.evaluate(() => ({
        dir: document.documentElement.getAttribute('dir'),
        lang: document.documentElement.getAttribute('lang'),
        computed: getComputedStyle(document.documentElement).direction,
    }));
}

test.describe('a public request is served in its site\'s locale', () => {
    test('an RTL site is served RTL', async ({ page }) => {
        await page.goto('/golfdom-ar');

        const doc = await documentDirection(page);

        expect(doc.lang).toBe('ar');
        expect(doc.dir).toBe('rtl');
        // ⚠️ The COMPUTED direction as well as the attribute. `dir` can be present and
        // still not be what the browser resolved — the mistake the original RTL check
        // made, and the reason the direction specs measure this instead.
        expect(doc.computed).toBe('rtl');
    });

    test('an LTR site is served LTR from the same server', async ({ page }) => {
        await page.goto('/golfdom');

        const doc = await documentDirection(page);

        expect(doc.lang).toBe('en');
        expect(doc.dir).toBe('ltr');
        expect(doc.computed).toBe('ltr');
    });

    /*
     * ⚠️ THE STRUCTURAL CRITERION: "two sites with different locales are served correctly
     * from the same process".
     *
     * `setLocale()` is process state, so a locale resolved once per process serves
     * whichever site warmed the worker — under PHP-FPM for its life, under Octane until it
     * restarts. Two navigations in one browser session against one dev server is the
     * cheapest thing that fails if that regresses.
     *
     * And it goes RTL then LTR then RTL: a single flip could be a coincidence of ordering,
     * where a return to the first answer cannot be.
     */
    test('two sites with different locales are served correctly in sequence', async ({ page }) => {
        await page.goto('/golfdom-ar');
        expect((await documentDirection(page)).computed).toBe('rtl');

        await page.goto('/golfdom');
        expect((await documentDirection(page)).computed).toBe('ltr');

        await page.goto('/golfdom-ar');
        expect((await documentDirection(page)).computed).toBe('rtl');
    });

    test('a path that names no site is a 404, not a default page', async ({ page }) => {
        // ⚠️ The route requires a site, so it refuses rather than quietly rendering the
        // placeholder in the app default — which would look like success.
        const response = await page.goto('/no-such-site');

        expect(response?.status()).toBe(404);
    });

    test('the admin is still reachable, not swallowed by the site route', async ({ page }) => {
        /*
         * ⚠️ `{site}` matches one segment of ANYTHING, including `admin`. Laravel resolves
         * in declaration order and the site route is registered last, so the panel's routes
         * win — but that is an ordering fact rather than a guarantee, and reordering
         * routes/web.php would break the whole admin silently. This is the test that
         * notices.
         */
        const response = await page.goto('/admin');

        expect(response?.status()).toBeLessThan(400);
        await expect(page.locator('html')).toHaveAttribute('dir', /ltr|rtl/);
    });
});
