// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * The media list's tiles, where they load — ADR-042 decision 6, with Adam's answers of 2026-09-29.
 *
 * ⚠️ THE BROWSER, BECAUSE BOTH OF THE CRITERION'S HALVES ARE ABOUT REQUESTS. A public tile's path carries no host, so it
 * loads from the server the admin is on — here `127.0.0.1:8125`, while `APP_URL` names `http://localhost`, which no
 * server in this suite answers. And the list asks the private route for nothing until a tile is clicked: every request
 * the page makes is listened to from before it loads, and each "none" is paired with the same page doing something that
 * would ask, so a zero is not a page that never rendered — or, where nothing can make it ask, with the tile rendered as
 * its type and nothing in it to click or load.
 *
 * The fixtures: "Golfdom logo" is public; "Course map" (kept to Golfdom) and "Shared course photo" are private.
 * Every file uploaded here is removed again, as `media-upload.spec.js` removes its own.
 */

const PRIVATE_ROUTE = /^\/admin\/golfdom\/media\/\d+$/;

function media() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

/** A 1×1 PNG, named as asked. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const png = (name) => ({ name, mimeType: 'image/png', buffer: PNG });

/** Where an uploaded title's bytes are, on the disk its row names. */
function bytesOf(title) {
    return tinker("$e = \\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('reading a browser-test upload', fn ($q) => $q->where('title', "
        + JSON.stringify(title) + ")->firstOrFail()); $f = DB::table('media_files')->where('entry_id', $e->id)->first(); echo Storage::disk($f->disk)->path($f->path);");
}

/** Force-delete every entry with one of these titles, with its org in context: a force-delete is audited. */
function removeUploads(titles) {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test upload', fn ($q) => $q->whereIn('title', "
        + JSON.stringify(titles) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** Grant or revoke `entry.image.{action}` on the copy-editor's role, as `media-upload.spec.js` does. */
function copyEditor(method, actions) {
    tinker("app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail(DB::table('sites')->where('slug', 'golfdom')->value('org_id')));"
        + " $role = \\Kitsune\\Core\\Models\\Role::query()->where('handle', 'copy-editor')->firstOrFail();"
        + ` foreach (${JSON.stringify(actions.map((action) => `entry.image.${action}`))} as $p) { if (${method === 'grant' ? '! ' : ''}$role->permissions()->where('permission', $p)->exists()) { $role->${method}($p); } }`);
}

const READER_STATE = '.playwright/admin-reader-auth.json';

const row = (page, title) => page.locator('.fi-ta-row').filter({ hasText: title });

/** A private tile's placeholder, by the name it gives itself. */
const placeholder = (page, title) => page.getByRole('button', { name: `Show the file ${title}`, exact: true });

/** The same tile once its file is shown: its name says so, whether it was clicked or made anew from what the page kept. */
const shown = (page, title) => page.getByRole('button', { name: `Showing the file ${title}`, exact: true });

/** Whether the image in a row has loaded. */
const loaded = (page, title) => row(page, title).locator('img').first()
    .evaluate((img) => img instanceof HTMLImageElement && img.complete && img.naturalWidth > 0)
    .catch(() => false);

/**
 * The list's own Livewire round trip. It resolves when the response's headers arrive, not when Livewire has applied
 * them: whatever must follow from an update is waited on in the page itself.
 */
const rerendered = (page) => page.waitForResponse((r) => /\/livewire-[0-9a-f]+\/update/.test(r.url()) && r.request().method() === 'POST');

/** Sort by title, and wait until the list says it is sorted the other way. */
async function sortByTitle(page) {
    const header = page.locator('.fi-ta th[aria-sort]').filter({ hasText: 'Title' });
    const before = await header.getAttribute('aria-sort');

    await Promise.all([rerendered(page), page.locator('.fi-ta').getByRole('button', { name: 'Title', exact: true }).click()]);
    await expect(header).not.toHaveAttribute('aria-sort', String(before));
}

/** Open Upload, stage the files, and submit — as `media-upload.spec.js`'s helper does. */
async function upload(page, files, { visibility = 'private' } = {}) {
    await page.locator('button[wire\\:click="mountAction(\'upload\')"]').click();

    /*
     * ⚠️ INTO FILEPOND'S OWN INPUT, ONCE FILEPOND HAS STARTED. A modal opened a second time on one page loads FilePond
     * again, and a file set on the input it then replaces is never taken — the drop area stays empty and the wait below
     * times out, which reads as an upload that hung.
     */
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('.filepond--root')).toBeVisible();
    await dialog.locator('input.filepond--browser').setInputFiles(files);
    await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]'))
        .toHaveCount(files.length, { timeout: 20_000 });

    if (visibility === 'public') {
        await dialog.getByRole('radio', { name: /^Public/ }).check();
        await dialog.getByLabel('Make these files public').check();
    }

    await dialog.getByRole('button', { name: 'Upload', exact: true }).click();
    await expect(page.locator('.fi-no-notification').filter({ hasText: 'uploaded' }).first()).toBeVisible({ timeout: 15_000 });
    await page.locator('.fi-no-notification .fi-no-notification-close-btn').first().click();
}

/**
 * Show five rows a page, then go to the next page and back, checking the tile wherever its row is.
 *
 * ⚠️ FILAMENT KEEPS ROWS-PER-PAGE IN THE SESSION, which every spec signed in as the owner shares: the caller puts it back
 * (`restorePerPage()`), or a later list — this spec's, repeated, or another's — shows five rows and hides its own.
 */
async function changePage(page, title) {
    let seen = 0;

    await Promise.all([rerendered(page), page.locator('.fi-pagination-records-per-page-select select:visible').first().selectOption('5')]);

    await expect(page.locator('.fi-ta-row')).toHaveCount(5);

    for (const [button, secondPage] of [['.fi-pagination-next-btn', true], ['.fi-pagination-previous-btn', false]]) {
        await Promise.all([rerendered(page), page.locator(button).click()]);
        // On the page the click asked for: only a second page has a way back.
        await expect(page.locator('.fi-pagination-previous-btn')).toHaveCount(secondPage ? 1 : 0);

        if (await row(page, title).count() > 0) {
            await expect.poll(() => loaded(page, title)).toBe(true);
            seen++;
        }
    }

    // Not vacuous: the row was on one of the two pages.
    expect(seen).toBeGreaterThan(0);
}

async function restorePerPage(page) {
    await page.goto('/admin/golfdom/c/image');
    await Promise.all([rerendered(page), page.locator('.fi-pagination-records-per-page-select select:visible').first().selectOption('10')]);
}

test.describe('the media list\'s tiles', () => {
    test.beforeEach(() => {
        fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
    });

    test('shows a public file from the admin\'s own host, not APP_URL\'s', async ({ page }) => {
        const requested = [];
        page.on('request', (request) => requested.push(new URL(request.url())));

        await page.goto('/admin/golfdom/c/image');

        const logo = row(page, 'Golfdom logo').locator('img').first();
        await expect(logo).toHaveAttribute('src', /^\/storage\/media\//);
        await logo.scrollIntoViewIfNeeded();
        await expect.poll(() => loaded(page, 'Golfdom logo')).toBe(true);

        const own = new URL(page.url()).host;
        expect(requested.some((url) => url.host === own && url.pathname.startsWith('/storage/media/'))).toBe(true);
        // `APP_URL` is `http://localhost`, which nothing here serves: a tile built from it would name that host.
        expect(requested.filter((url) => url.host !== own && ['http:', 'https:'].includes(url.protocol)).map((url) => url.href)).toEqual([]);
    });

    test('asks the private route for nothing until a tile is clicked, and for that file once', async ({ page }) => {
        const titles = ['tile-probe-upload', 'tile-probe-upload-after'];
        const hits = [];
        page.on('request', (request) => {
            if (PRIVATE_ROUTE.test(new URL(request.url()).pathname)) {
                hits.push(request.url());
            }
        });

        try {
            await page.goto('/admin/golfdom/c/image');

            // The page itself asks for nothing, with the private tiles there as placeholders — the controls.
            await page.waitForLoadState('networkidle');
            expect(hits).toEqual([]);
            await expect(placeholder(page, 'Course map')).toBeVisible();
            await expect(placeholder(page, 'Shared course photo')).toBeVisible();
            expect(await row(page, 'Course map').locator('img').count()).toBe(0);

            // Nor does anything that could ask: pointing, focusing, a sort, a search, Upload opened and cancelled, an upload.
            await placeholder(page, 'Course map').hover();
            await placeholder(page, 'Shared course photo').focus();
            await sortByTitle(page);
            await Promise.all([rerendered(page), page.locator('.fi-ta-search-field input').fill('course')]);
            await Promise.all([rerendered(page), page.locator('.fi-ta-search-field input').fill('')]);
            await page.locator('button[wire\\:click="mountAction(\'upload\')"]').click();
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
            await expect(page.getByRole('dialog')).toBeHidden();
            await upload(page, [png(`${titles[0]}.png`)]);
            await expect(placeholder(page, titles[0])).toBeVisible();
            await page.waitForLoadState('networkidle');

            expect(hits).toEqual([]);

            // A click asks once, and the file is shown.
            const [response] = await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, 'Course map').click(),
            ]);

            expect(response.status()).toBe(200);
            expect(response.headers()['content-type']).toBe('image/png');
            await expect.poll(() => loaded(page, 'Course map')).toBe(true);
            expect(hits).toHaveLength(1);
            // Said to a screen reader, and the button marked done: there is nothing left for it to do.
            await expect(row(page, 'Course map').getByRole('status')).toHaveText('Shown.');
            await expect(shown(page, 'Course map')).toHaveAttribute('aria-disabled', 'true');

            // Pressed again once shown, or double-clicked, it asks nothing more: the page has it.
            await shown(page, 'Course map').click({ force: true });
            await shown(page, 'Course map').dblclick({ force: true });
            await page.waitForTimeout(500);
            expect(hits).toHaveLength(1);

            /*
             * And stays shown without asking again through everything that makes its row anew: an upload after the click,
             * a sort, which moves its row — Livewire makes a moved row anew — a search that drops the row and brings it
             * back, and a page change there and back. The page keeps what it fetched.
             */
            await upload(page, [png(`${titles[1]}.png`)]);
            await sortByTitle(page);
            // The sort moved its row — from first by title to last — so the row, and its tile, were made anew.
            await expect(page.locator('.fi-ta-row').last()).toContainText('Course map');
            await expect.poll(() => loaded(page, 'Course map')).toBe(true);
            // Made anew from what the page kept, and still named as shown: not an inert "Show the file" (Codex, #158).
            await expect(shown(page, 'Course map')).toHaveAttribute('aria-disabled', 'true');
            await Promise.all([rerendered(page), page.locator('.fi-ta-search-field input').fill('no file is called this')]);
            await expect(row(page, 'Course map')).toHaveCount(0);
            await Promise.all([rerendered(page), page.locator('.fi-ta-search-field input').fill('')]);
            await expect(row(page, 'Course map')).toHaveCount(1);
            await expect.poll(() => loaded(page, 'Course map')).toBe(true);
            await changePage(page, 'Course map');
            await page.waitForTimeout(500);
            expect(hits).toHaveLength(1);

            // A tile not clicked is still a placeholder, and a double-click on it asks once.
            await page.goto('/admin/golfdom/c/image');
            await expect(placeholder(page, 'Shared course photo')).toBeVisible();
            expect(await row(page, 'Shared course photo').locator('img').count()).toBe(0);
            await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, 'Shared course photo').dblclick(),
            ]);
            await expect.poll(() => loaded(page, 'Shared course photo')).toBe(true);
            await page.waitForTimeout(500);
            expect(hits).toHaveLength(2);
        } finally {
            removeUploads(titles);
            // Best effort, and never in place of the test's own failure: a test that timed out has no page to restore.
            await restorePerPage(page).catch(() => {});
        }
    });

    /*
     * ⚠️ A TILE MADE ANEW WHILE ITS FILE IS STILL ARRIVING waits on the same request rather than asking again (review of
     * decision 6): the route is held while the list re-renders, then let go.
     */
    test('shows a file whose request was still open when its row was made anew, having asked once', async ({ page }) => {
        const hits = [];
        let release;
        const released = new Promise((resolve) => {
            release = resolve;
        });

        page.on('request', (request) => {
            if (PRIVATE_ROUTE.test(new URL(request.url()).pathname)) {
                hits.push(request.url());
            }
        });
        await page.route((url) => PRIVATE_ROUTE.test(url.pathname), async (route) => {
            await released;
            await route.continue();
        });

        await page.goto('/admin/golfdom/c/image');
        await placeholder(page, 'Course map').click();
        await expect(row(page, 'Course map').getByRole('status')).toHaveText('Loading…');
        await sortByTitle(page);
        await sortByTitle(page);
        release();

        await expect.poll(() => loaded(page, 'Course map')).toBe(true);
        await sortByTitle(page);
        await page.waitForLoadState('networkidle');
        await expect.poll(() => loaded(page, 'Course map')).toBe(true);
        expect(hits).toHaveLength(1);
    });

    /*
     * A public file that will not load shows its type: a row naming the public disk whose bytes are not there, as decision
     * 5's residue leaves — the same request as a file trashed after the page rendered. (That it does so when the image
     * failed before Alpine started, with nobody listening, is pinned in `MediaTileTest`, not driven here.)
     */
    test('shows a public file that will not load as its type', async ({ page }) => {
        const title = 'tile-probe-public-gone';

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${title}.png`)], { visibility: 'public' });
            fs.rmSync(bytesOf(title));

            await page.goto('/admin/golfdom/c/image');

            await expect(row(page, title).locator('span[x-show="failed"]')).toBeVisible();
            await expect(row(page, title).locator('img')).toBeHidden();
        } finally {
            removeUploads([title]);
        }
    });

    /* A file that arrives but will not show is not kept either: it says so, and the next click asks again. */
    test('says a file that will not show could not be shown, and asks again when clicked again', async ({ page }) => {
        const hits = [];
        page.on('request', (request) => {
            if (PRIVATE_ROUTE.test(new URL(request.url()).pathname)) {
                hits.push(request.url());
            }
        });
        await page.route((url) => PRIVATE_ROUTE.test(url.pathname), (route) => route.fulfill({ status: 200, contentType: 'image/png', body: 'not a png' }));

        await page.goto('/admin/golfdom/c/image');
        await placeholder(page, 'Course map').click();

        await expect(row(page, 'Course map').getByRole('status')).toHaveText('This file could not be shown.');
        expect(await row(page, 'Course map').locator('img').count()).toBe(0);

        await placeholder(page, 'Course map').click();
        await expect.poll(() => hits.length).toBe(2);
    });

    /*
     * ⚠️ WHAT ARRIVES MUST BE ONE OF THE TYPES A TILE MAY SHOW, whatever answered: real PNG bytes sent as `text/html` are
     * not shown — the browser would sniff and show them if the tile did not ask the type first.
     */
    test('shows nothing that arrives as a type a tile may not show', async ({ page }) => {
        await page.route((url) => PRIVATE_ROUTE.test(url.pathname), (route) => route.fulfill({ status: 200, contentType: 'text/html', body: PNG }));

        await page.goto('/admin/golfdom/c/image');
        await placeholder(page, 'Course map').click();

        await expect(row(page, 'Course map').getByRole('status')).toHaveText('This file could not be shown.');
        await page.waitForTimeout(500);
        expect(await row(page, 'Course map').locator('img').count()).toBe(0);
    });

    /*
     * ⚠️ A REDIRECT IS NOT FOLLOWED: a tile that met one — a proxy's, or a panel's own sign-in — is signed out, and never
     * asks the page it was sent to, whose HTML is no file.
     */
    test('follows no redirect, and says the editor was signed out', async ({ page }) => {
        const login = [];
        page.on('request', (request) => {
            if (new URL(request.url()).pathname === '/admin/login') {
                login.push(request.url());
            }
        });
        await page.route((url) => PRIVATE_ROUTE.test(url.pathname), (route) => route.fulfill({ status: 302, headers: { location: '/admin/login' } }));

        await page.goto('/admin/golfdom/c/image');
        await placeholder(page, 'Course map').click();

        await expect(row(page, 'Course map').getByRole('status')).toHaveText('Signed out — sign in again to see this file.');
        await page.waitForTimeout(500);
        expect(login).toEqual([]);
        expect(await row(page, 'Course map').locator('img').count()).toBe(0);
    });

    test('says a file is no longer the reader\'s to see', async ({ browser }) => {
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();

        try {
            copyEditor('grant', ['view']);
            await page.goto('/admin/golfdom/c/image');
            await expect(placeholder(page, 'Course map')).toBeVisible();

            copyEditor('revoke', ['view']);
            const [response] = await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, 'Course map').click(),
            ]);

            expect(response.status()).toBe(403);
            await expect(row(page, 'Course map').getByRole('status')).toHaveText('You may no longer see this file.');
            expect(await row(page, 'Course map').locator('img').count()).toBe(0);

            // A refusal is not kept: with the grant back, the next click asks again, and the file is shown.
            copyEditor('grant', ['view']);
            const [again] = await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, 'Course map').click(),
            ]);

            expect(again.status()).toBe(200);
            await expect.poll(() => loaded(page, 'Course map')).toBe(true);
            await expect(row(page, 'Course map').getByRole('status')).toHaveText('Shown.');
        } finally {
            copyEditor('revoke', ['view']);
            await context.close();
        }
    });

    /*
     * ⚠️ SIGNED OUT, AND NOT SENT TO THE FILE ONCE SIGNED IN AGAIN (Adam, decision 17). A plain request for the file would
     * be redirected to sign in, and Laravel would record the file as where to land afterwards (`redirect()->guest()`);
     * the tile asks as an Ajax request, which is answered 401 — the answer that records nothing — and no redirect.
     *
     * ⚠️ NOT BY SIGNING IN AGAIN HERE: Filament allows five sign-ins a minute from one address, and the setup projects
     * have spent them by the time this runs — the sixth waits, and the test times out on a throttle, not a tile.
     */
    test('says the editor was signed out, having recorded no file to land on after signing in', async ({ browser }) => {
        const context = await browser.newContext({ storageState: '.playwright/admin-auth.json' });
        const page = await context.newPage();

        try {
            await page.goto('/admin/golfdom/c/image');
            await expect(placeholder(page, 'Course map')).toBeVisible();

            await context.clearCookies();
            const [response] = await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, 'Course map').click(),
            ]);

            expect(response.status()).toBe(401);
            expect(response.headers().location).toBeUndefined();
            await expect(row(page, 'Course map').getByRole('status')).toHaveText('Signed out — sign in again to see this file.');
        } finally {
            await context.close();
        }
    });

    test('says a file whose bytes are gone could not be found', async ({ page }) => {
        const title = 'tile-probe-missing';

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${title}.png`)]);
            fs.rmSync(bytesOf(title));

            await page.goto('/admin/golfdom/c/image');
            const [response] = await Promise.all([
                page.waitForResponse((r) => PRIVATE_ROUTE.test(new URL(r.url()).pathname)),
                placeholder(page, title).click(),
            ]);

            expect(response.status()).toBe(404);
            await expect(row(page, title).getByRole('status')).toHaveText('This file could not be found.');
        } finally {
            removeUploads([title]);
        }
    });

    /*
     * Every other file is its type, and asks for nothing — public or private. The public one is the case that could ask:
     * a tile that showed every type as an image would load it with the page, from the public disk.
     */
    test('shows a file the page may not render as its type, and asks for nothing', async ({ page }) => {
        const titles = ['tile-probe-notes', 'tile-probe-public-notes'];
        const text = (title) => ({ name: `${title}.txt`, mimeType: 'text/plain', buffer: Buffer.from('plain words\n') });

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [text(titles[0])]);
            await upload(page, [text(titles[1])], { visibility: 'public' });
            const paths = titles.map((title) => path.basename(bytesOf(title)));

            const asked = [];
            page.on('request', (request) => {
                const url = new URL(request.url());

                if (PRIVATE_ROUTE.test(url.pathname) || paths.some((name) => url.pathname.endsWith(`/${name}`))) {
                    asked.push(url.href);
                }
            });
            await page.goto('/admin/golfdom/c/image');

            for (const title of titles) {
                await row(page, title).scrollIntoViewIfNeeded();
                await expect(row(page, title)).toContainText('No preview: text/plain');
                await expect(row(page, title).getByRole('button', { name: /^Show the file/ })).toHaveCount(0);
                expect(await row(page, title).locator('img').count()).toBe(0);
            }

            await page.waitForTimeout(500);
            expect(asked).toEqual([]);
        } finally {
            removeUploads(titles);
        }
    });
});
