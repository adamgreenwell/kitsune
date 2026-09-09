// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Does an explicitly localized admin URL survive a Livewire update?
 *
 * ⚠️ THIS FILE EXISTS BECAUSE A REVIEW FINDING SAID IT DOES NOT, AND MEASUREMENT SAID
 * OTHERWISE. The reasoning was sound and the conclusion was wrong: `SetUiLocale` is
 * persistent middleware, Livewire's update endpoint carries no query string, so the
 * middleware does fall back to the stored preference on an update. What that reasoning
 * missed is that Livewire v4 ships `SupportLocales`
 * (vendor/livewire/livewire/src/Features/SupportLocales/SupportLocales.php, registered
 * at LivewireServiceProvider.php:201): it memoises `app()->getLocale()` on dehydrate and
 * calls `setLocale()` from that memo on hydrate. Hydration runs AFTER the middleware
 * stack, so the memo wins and the initial page's locale is carried forward.
 *
 * Nothing tested that, which is why an argument could stand against it. This does.
 *
 * ⚠️ ASSERTED ON THE UPDATE RESPONSE, not on the page afterwards. A Livewire update
 * patches the component, and `<html dir>` is outside every component — so it keeps its
 * initial value whatever the update resolved, and asserting on it would pass even if the
 * locale reverted completely. An earlier probe of mine did exactly that and looked
 * convincing.
 *
 * ⚠️ AND THE RESPONSE IS JSON, so non-ASCII arrives `\uXXXX`-escaped. A regex for raw
 * Arabic finds nothing in it and reads as "no Arabic", which is the same
 * measuring-the-wrong-artifact mistake one layer down. It is unescaped before matching.
 */

/**
 * `filament-support::components/breadcrumbs.label`, which is chrome rather than content.
 *
 * ⚠️ A CONTENT string cannot discriminate here: the seed deliberately contains an
 * Arabic-titled entry (for the direction tests), so an update response contains Arabic
 * either way. Only a translated INTERFACE string distinguishes "rendered in Arabic" from
 * "contains Arabic data".
 *
 * Source: vendor/filament/support/resources/lang/{en,ar}/components/breadcrumbs.php.
 * If Filament changes these, this fails with a pointer rather than silently passing.
 */
const CHROME = { en: 'Breadcrumb', ar: 'مسار التصفح' };

/** The last Livewire update response for an interaction on the given URL, decoded. */
async function updateResponseFor(page, url) {
    const bodies = [];
    const capture = async (res) => {
        // The endpoint is /livewire-<8 hex>/update, hashed from APP_KEY by
        // Livewire\Mechanisms\HandleRequests\EndpointResolver — NOT the v3
        // /livewire/update, so it is matched by shape rather than by literal.
        if (/livewire-[0-9a-f]+\/update/.test(res.url()) && res.request().method() === 'POST') {
            try { bodies.push(await res.text()); } catch (e) { /* a redirected body is not ours */ }
        }
    };

    page.on('response', capture);
    await page.goto(url);

    // A sortable column header is the cheapest real update: it re-renders the table
    // component server-side, which is what carries the chrome strings.
    await page.locator('th button, th a').first().click();
    await expect.poll(() => bodies.length, { timeout: 10_000 }).toBeGreaterThan(0);
    page.off('response', capture);

    const raw = bodies[bodies.length - 1];

    return raw.replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
}

test.describe('an explicitly localized admin URL survives a Livewire update', () => {
    test('the update renders in the requested locale, not the stored one', async ({ page }) => {
        const body = await updateResponseFor(page, '/admin/golfdom/c/article?locale=ar');

        expect(body).toContain(CHROME.ar);
        // And not both, which would mean a half-translated render rather than a locale.
        expect(body).not.toContain(CHROME.en);
    });

    test('without a requested locale the update renders in the default', async ({ page }) => {
        /*
         * ⚠️ The control, and it is what makes the test above mean anything. The signed-in
         * editor for this project has no stored preference and the site's locale is `en`,
         * so the same interaction must come back in English — otherwise the first test
         * would pass against an admin that is simply always Arabic.
         */
        const body = await updateResponseFor(page, '/admin/golfdom/c/article');

        expect(body).toContain(CHROME.en);
        expect(body).not.toContain(CHROME.ar);
    });
});
