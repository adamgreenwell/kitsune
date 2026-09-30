// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * The media list's tiles — ADR-042 decision 6, laid out as the card grid Adam chose (decision 17).
 *
 * ⚠️ BY THE REQUESTS THE PAGE MAKES, NOT BY WHAT IT SHOWS. Decision 6's promises are about the network: a public tile
 * loads from the host serving the admin, and the list makes no request to the route that authorises first until a
 * private tile is clicked. So each is asserted by listening to the page's own requests, and each "none" has a control
 * that makes one.
 *
 * ⚠️ THE BROWSER, BECAUSE THAT IS WHERE A TILE LOADS. The PHP suite can say what HTML a tile is; only a browser says
 * whether it fetched anything, from where, and whether a sort or a page change fetched it again.
 *
 * ⚠️ THE PDF STORED HERE IS REMOVED AGAIN, force-deleted with its org in context, because the suite shares one database
 * and the other media specs count what the image list holds.
 */

const SITE = 'golfdom';
// Capitalised, so it sorts among the seeded titles the same way under a binary collation and a case-insensitive one.
const PDF_TITLE = 'Tiles probe rules';

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

/** Golfdom's org and site in context, as a request to its admin would have them. */
const IN_GOLFDOM = "$site = \\Kitsune\\Core\\Models\\Site::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('slug', 'golfdom')->firstOrFail());"
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($site->org_id));'
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);';

/** A private PDF, shared across the org — a type delivery never renders — and its entry id. */
function storePdf() {
    return Number(tinker(IN_GOLFDOM
        + " $path = tempnam(sys_get_temp_dir(), 'kitsune-tiles-'); file_put_contents($path, \"%PDF-1.4\\n1 0 obj<<>>endobj\\ntrailer<<>>\\n%%EOF\\n\");"
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + ` echo \\Kitsune\\Core\\Media\\MediaLibrary::store($path, 'rules.pdf', $type, 'private', ${JSON.stringify(PDF_TITLE)})->id; @unlink($path);`));
}

function removePdf() {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->where('title', "
        + JSON.stringify(PDF_TITLE) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** Every request, from now on, to the route that authorises first — `/admin/{site}/media/{id}`. */
function privateRequests(page) {
    const seen = [];
    page.on('request', (request) => {
        if (/^\/admin\/[^/]+\/media\/\d+$/.test(new URL(request.url()).pathname)) {
            seen.push(request.url());
        }
    });

    return seen;
}

/** The card for a title on the media list. */
const card = (page, title) => page.locator('.fi-ta-record').filter({ hasText: title });

/** Whether an image element has finished loading real bytes. */
const loaded = (image) => image.evaluate((element) => element instanceof HTMLImageElement && element.complete && element.naturalWidth > 0);

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    removePdf();
    storePdf();
});

test.afterAll(() => removePdf());

test.describe('the media list', () => {
    test('is a grid of tiles, where an article list stays a table', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/image`);

        await expect(page.locator('.fi-ta-content-grid')).toHaveCount(1);
        await expect(card(page, 'Course map').locator('[data-kitsune-tile]')).toHaveCount(1);
        await expect(page.locator('.fi-ta-row')).toHaveCount(0);

        // No card is one link to its record, which would hold a private tile's button inside an `<a>`; View and Edit stay.
        await expect(page.locator('.fi-ta-record a.fi-ta-record-content')).toHaveCount(0);
        await expect(card(page, 'Course map').getByRole('link', { name: 'Edit' })).toHaveAttribute('href', /\/c\/image\/\d+\/edit$/);

        // The control: an article list keeps its rows, and has no tiles.
        await page.goto(`/admin/${SITE}/c/article`);

        await expect(page.locator('.fi-ta-row').first()).toBeVisible();
        await expect(page.locator('[data-kitsune-tile]')).toHaveCount(0);
        await expect(page.locator('.fi-ta-content-grid')).toHaveCount(0);
    });
});

test.describe('a public tile', () => {
    /*
     * ⚠️ FROM THE HOST SERVING THE ADMIN, WHICH HERE IS NOT APP_URL's. The suite's server is `127.0.0.1:8125` and
     * APP_URL is `http://localhost`, so an absolute URL built from APP_URL would be fetched from somewhere else — the
     * very case decision 6 names. The request's origin is the page's, and the bytes arrive.
     */
    test('loads with the page, from the host serving the admin', async ({ page }) => {
        const storage = [];
        page.on('response', (response) => {
            if (new URL(response.url()).pathname.startsWith('/storage/')) {
                storage.push({ origin: new URL(response.url()).origin, status: response.status() });
            }
        });

        await page.goto(`/admin/${SITE}/c/image`);

        const image = card(page, 'Golfdom logo').locator('[data-kitsune-tile="direct"] img');
        await expect(image).toHaveAttribute('src', /^\/storage\//);
        await expect.poll(() => loaded(image)).toBe(true);

        expect(storage.length).toBeGreaterThan(0);
        expect(storage.every(({ origin, status }) => origin === new URL(page.url()).origin && status === 200)).toBe(true);
    });
});

test.describe('a private tile', () => {
    test('makes no request to the route that authorises first until it is clicked, and one when it is', async ({ page }) => {
        const seen = privateRequests(page);

        await page.goto(`/admin/${SITE}/c/image`);
        await page.waitForLoadState('networkidle');

        const tile = card(page, 'Course map').locator('[data-kitsune-tile="deferred"]');
        await expect(tile.getByRole('button', { name: 'Show preview of "Course map"', exact: true })).toBeVisible();
        await expect(tile.locator('img')).toHaveCount(0);
        expect(seen).toEqual([]);

        // The control: clicked, it asks the route once, the bytes arrive, and the page stays on the list.
        const [response] = await Promise.all([
            page.waitForResponse((r) => /^\/admin\/[^/]+\/media\/\d+$/.test(new URL(r.url()).pathname)),
            tile.getByRole('button').click(),
        ]);

        expect(response.status()).toBe(200);
        await expect.poll(() => loaded(tile.locator('img'))).toBe(true);
        expect(seen).toHaveLength(1);
        expect(page.url()).toMatch(/\/c\/image$/);
    });

    /*
     * ⚠️ AND NONE ON A SORT OR A PAGE CHANGE, which re-render every tile. Five a page, so the seeded files and the PDF
     * make two pages; by title, descending, the shared photo is private on the first and the course map on the second.
     * The grid is asserted after each, since the layout is decided as the table is built, on every Livewire request.
     *
     * ⚠️ AND TEN A PAGE AGAIN AFTERWARDS, because Filament keeps the choice in the session, which every spec here shares
     * through one signed-in state: left at five, a later spec's upload could land on a page it never looks at.
     */
    test('makes none on a sort or a page change either', async ({ page }) => {
        const seen = privateRequests(page);

        // By what Filament binds them to, not by their words, which it pads with whitespace; two per-page selects render.
        const perPage = page.locator('.fi-ta select[wire\\:model\\.live="tableRecordsPerPage"]').filter({ visible: true }).first();
        const sortColumn = page.locator('.fi-ta select[x-model="column"]');
        const sortDirection = page.locator('.fi-ta select[x-model="direction"]');

        await page.goto(`/admin/${SITE}/c/image`);

        try {
            await perPage.selectOption('5');
            await expect(page.locator('.fi-ta-record')).toHaveCount(5);

            await sortColumn.selectOption('title');
            await sortDirection.selectOption('desc');
            await expect(page.locator('.fi-ta-record').first()).toContainText('Withdrawn scorecard');
            await expect(page.locator('.fi-ta-content-grid')).toHaveCount(1);
            await expect(card(page, 'Shared course photo').locator('[data-kitsune-tile="deferred"]')).toBeVisible();

            await page.getByRole('button', { name: 'Next' }).click();
            await expect(card(page, 'Course map').locator('[data-kitsune-tile="deferred"]')).toBeVisible();
            await expect(page.locator('.fi-ta-content-grid')).toHaveCount(1);
            await page.waitForLoadState('networkidle');

            expect(seen).toEqual([]);

            // The control: the private tile on this page still loads when clicked.
            const deferred = card(page, 'Course map').locator('[data-kitsune-tile="deferred"]');
            await deferred.getByRole('button').click();
            await expect.poll(() => loaded(deferred.locator('img'))).toBe(true);
            expect(seen).toHaveLength(1);
        } finally {
            await page.goto(`/admin/${SITE}/c/image`);
            await perPage.selectOption('10');
            await expect(perPage).toHaveValue('10');
        }
    });
});

test.describe('a file delivery does not render', () => {
    test('is a badge naming its type, and is never fetched', async ({ page }) => {
        const seen = privateRequests(page);

        await page.goto(`/admin/${SITE}/c/image`);
        await page.waitForLoadState('networkidle');

        const tile = card(page, PDF_TITLE).locator('[data-kitsune-tile="badge"]');
        await expect(tile).toHaveText('PDF');
        await expect(tile.locator('img, button')).toHaveCount(0);
        expect(seen).toEqual([]);
    });
});
