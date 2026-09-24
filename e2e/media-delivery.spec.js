// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { test, expect, request: playwrightRequest } = require('@playwright/test');

/*
 * Private media delivery, measured at the URL — ADR-041, and AGENTS.md §9's browser requirement for a new
 * admin route shape.
 *
 * ⚠️ THE PHP SUITE STRUCTURALLY CANNOT MAKE THE CLAIM THIS FILE MAKES. `MediaDeliveryTest` registers a route
 * of its own carrying no middleware, so it proves the CONTROLLER's decisions and nothing about where the
 * route was mounted. The whole architectural argument for `authenticatedTenantRoutes()` is that Filament
 * nests it inside both `getAuthMiddleware()` and `getTenantMiddleware()` — so an anonymous request is turned
 * away before the controller runs, and a signed-in one arrives with `Context` already populated. Filament's
 * three neighbouring methods each drop one of those, and `tenantRoutes()` — which drops auth — was the first
 * attempt: `IdentifyTenant` then asks for the user before anything has authenticated one, resolves core's
 * org-scoped user model against an empty `Context`, gets null, and aborts 404 at the owner's own file.
 *
 * ⚠️ AND THIS FILE CAUGHT A SECOND BUG THE PHP SUITE COULD NOT. Laravel resolves controller parameters that
 * are not type-hinted as classes POSITIONALLY, so the controller's `string $media` was handed `{tenant:slug}`
 * — the first parameter on the real two-segment route. The PHP fixture's route had one parameter, where
 * position zero happens to be the right one, so all eighteen of its assertions passed against it.
 *
 * ⚠️ AND THE ID IS READ FROM THE ADMIN RATHER THAN GUESSED, which is the other half of §9's reason: the
 * load-bearing risk here is URL generation across page boundaries. Walking `/c/image` → a record page → the
 * media URL crosses two of them.
 *
 * The session is Golfdom's OWNER, who holds every grant in their own org. One block reuses the copy-editor's
 * storage state — `auth-reader.setup.js`'s output, not a sign-in — because `playwright.config.js` records
 * that signing in inside a spec against one dev server and one SQLite file timed out intermittently.
 */

const SITE = 'golfdom';

/*
 * ⚠️ READ INSIDE THE TEST, NOT `require`d AT THE TOP. Playwright loads spec files to collect their tests, and
 * a top-level require of a file `global-setup.js` has not written yet fails collection for the whole project
 * — which reads as "no tests found" rather than as a missing fixture.
 */
function mediaFixture() {
    const file = path.join(__dirname, '..', '.playwright', 'media-fixture.json');

    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function publicMediaPath() {
    return mediaFixture().publicPath;
}

/** The rival org's owner — `auth.setup.js`'s second session. */
const RIVAL_STATE = '.playwright/admin-rival-auth.json';
const READER_STATE = '.playwright/admin-reader-auth.json';

/** The id of the seeded media entry with this title, read by walking the admin to it. */
async function mediaEntryId(page, title) {
    await page.goto(`/admin/${SITE}/c/image`);

    const row = page.locator('.fi-ta-row').filter({ hasText: title }).first();
    await expect(row).toBeVisible();

    await row.locator('a[href*="/c/image/"]').first().click();
    await page.waitForURL(/\/c\/image\/\d+/);

    const id = page.url().match(/\/c\/image\/(\d+)/);
    expect(id, `no numeric id in ${page.url()}`).not.toBeNull();

    return id[1];
}

test('streams a private file to a user whose grants cover it', async ({ page }) => {
    const id = await mediaEntryId(page, 'Course map');

    const response = await page.request.get(`/admin/${SITE}/media/${id}`);

    expect(response.status()).toBe(200);

    /*
     * ⚠️ THE STORED `mime`, WHICH IS ADR-041'S READ-SIDE RULE AT THE URL. Delivery never infers a type from
     * the path; `FilesystemAdapter::response()` would have filled this in from the extension if the header
     * were not passed explicitly.
     */
    expect(response.headers()['content-type']).toBe('image/png');
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    expect(response.headers()['content-security-policy']).toContain("default-src 'none'");
    expect(response.headers()['cache-control']).toContain('no-store');

    /* A listed image renders, so the media UI can show it. Everything else downloads. */
    expect(response.headers()['content-disposition']).toMatch(/^inline/);

    expect((await response.body()).length).toBeGreaterThan(0);
});

/**
 * ⚠️ THE TEST THAT PROVES WHERE THE ROUTE IS MOUNTED, and the only one that can. Registered outside the
 * panel's auth group this returns bytes to an anonymous request, and no unit test would notice.
 */
test('turns an anonymous request away before the controller runs', async ({ page, browser }) => {
    const id = await mediaEntryId(page, 'Course map');

    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });

    const response = await anonymous.request.get(`/admin/${SITE}/media/${id}`, { maxRedirects: 0 });

    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('/admin/login');

    await anonymous.close();
});

/**
 * ⚠️ THE COPY-EDITOR HOLDS `entry.article.*` AND NOTHING ON `image`, so the scope lets her through and the
 * POLICY is the only thing left to refuse her. A user who failed the scope check too would return 404 here
 * and the assertion would pass against a controller that never consulted a policy at all.
 */
test('refuses a signed-in user whose grants do not cover the type', async ({ page }) => {
    const id = await mediaEntryId(page, 'Course map');

    const reader = await playwrightRequest.newContext({
        baseURL: test.info().project.use.baseURL,
        storageState: READER_STATE,
    });

    const response = await reader.get(`/admin/${SITE}/media/${id}`);

    expect(response.status()).toBe(403);

    await reader.dispose();
});

/**
 * ⚠️ ANOTHER ORG'S FILE, ASKED FOR BY AN OWNER. Golfdom's owner holds every grant in their own org, and the
 * file is on the global `image` type both orgs share — so neither the grants nor the type predicate can refuse
 * this. What refuses it is the site boundary: the file is stamped with the rival's site, so Kitsune's `SiteScope`
 * and Filament's tenant scope each keep the row out of the query, and it is never found. The rival's own owner
 * being served the same id, from their own site, is what shows the 404 is the boundary and not a file that was
 * never there.
 */
test('answers 404 for another org\'s file, which that org\'s owner is served', async ({ page, browser }) => {
    const { rivalFileId } = mediaFixture();

    await page.goto(`/admin/${SITE}/c/image`);

    const refused = await page.request.get(`/admin/${SITE}/media/${rivalFileId}`);

    expect(refused.status()).toBe(404);

    const rival = await browser.newContext({ storageState: RIVAL_STATE });

    const served = await rival.request.get(`/admin/rival-golfdom/media/${rivalFileId}`);

    expect(served.status()).toBe(200);
    expect(served.headers()['content-type']).toBe('image/png');

    await rival.close();
});

/** An id no row carries is a 404 rather than a 500, from the same place a real one is served. */
test('answers 404 for an id that names nothing', async ({ page }) => {
    await page.goto(`/admin/${SITE}/c/image`);

    const response = await page.request.get(`/admin/${SITE}/media/98765432`);

    expect(response.status()).toBe(404);
});

/**
 * ⚠️ THE PUBLIC PATH IS FETCHED, NOT COMPUTED, AND REVIEW FOUND WHY THAT MATTERS. ADR-041 gives a public file
 * "a direct URL a CDN can cache — no PHP in the path". Nothing created `public/storage` outside
 * `deploy/release.sh`, so on a documented bare install that URL served 403 while the PHP suite reported the
 * public path working: its assertion compared `MediaDelivery::urlFor()` with `Storage::disk('public')->url()`,
 * which is the same method on both sides of an equals.
 *
 * This asserts the whole chain instead — bytes on the public disk, `storage:link` run by the installer, and
 * the web server returning them with no framework in the way.
 */
test('serves a public file straight off the linked disk, with no PHP in the path', async ({ page }) => {
    const response = await page.request.get(`/storage/${publicMediaPath()}`);

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('image/png');
    expect((await response.body()).length).toBeGreaterThan(0);

    /*
     * ⚠️ AND IT IS NOT THE PANEL ROUTE WEARING A DISGUISE. The private path sends these three; a file served
     * by the web server off a symlink cannot, which is what "no PHP in the path" means in observable terms.
     */
    expect(response.headers()['content-security-policy']).toBeUndefined();
    expect(response.headers()['x-content-type-options']).toBeUndefined();
    expect(response.headers()['content-disposition']).toBeUndefined();
});

/** A public file needs no session at all — that is the entire difference from the route above. */
test('serves a public file to an anonymous visitor', async ({ browser }) => {
    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });

    const response = await anonymous.request.get(`/storage/${publicMediaPath()}`);

    expect(response.status()).toBe(200);
    expect((await response.body()).length).toBeGreaterThan(0);

    await anonymous.close();
});
