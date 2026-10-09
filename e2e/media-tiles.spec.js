// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { menuItem } = require('./menu');
const AxeBuilder = require('@axe-core/playwright').default;

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
 *
 * ⚠️ AND WHAT A TILE SAYS, SINCE DECISION 39: a private tile's answer is said in words, its button keeps focus, and a
 * public image that does not load is an amber badge. Those are asserted by what a tile shows, where focus is and what
 * a screen reader is given — and still by the requests the page makes, since decision 6's promise is unchanged: none
 * before a press, and one per press.
 */

const SITE = 'golfdom';

/** WCAG 2.1 A and AA — the level the project is aiming at. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
/*
 * ⚠️ THIS SPEC'S OWN FILES, FOUND BY SEARCHING FOR THEIR TITLES, so a page change is measured over rows no other spec
 * adds or removes: a PDF and five private images, six in all, which at five a page are two pages with a private tile
 * on each. Capitalised alike, so they sort the same way under a binary collation and a case-insensitive one.
 */
const PROBE = 'Tiles probe';
const PDF_TITLE = `${PROBE} rules`;
const PRIVATE_TITLES = [1, 2, 3, 4, 5].map((n) => `${PROBE} private ${n}`);

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

/**
 * This spec's files, stored as an upload stores them, shared across the org: a private PDF — a type delivery never
 * renders — and five private images.
 *
 * ⚠️ THE PDF AS ONE STORED BEFORE `image` NAMED THE FILES IT ACCEPTS (ADR-042 decision 33), which it keeps: the type's
 * list is set aside for that one store, below the model, and put back whatever happens.
 */
function storeFixtures() {
    const titles = PRIVATE_TITLES.map((title) => `'${title}'`).join(', ');

    tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + " $store = function (string $bytes, string $name, string $title) use ($type) { $path = tempnam(sys_get_temp_dir(), 'kitsune-tiles-'); file_put_contents($path, $bytes);"
        + " try { \\Kitsune\\Core\\Media\\MediaLibrary::store($path, $name, $type, 'private', $title); } finally { @unlink($path); } };"
        + " $settings = \\Illuminate\\Support\\Facades\\DB::table('entry_types')->where('id', $type->id)->value('settings');"
        + " \\Illuminate\\Support\\Facades\\DB::table('entry_types')->where('id', $type->id)->update(['settings' => null]);"
        + ` try { $store("%PDF-1.4\\n1 0 obj<<>>endobj\\ntrailer<<>>\\n%%EOF\\n", 'rules.pdf', '${PDF_TITLE}'); }`
        + " finally { \\Illuminate\\Support\\Facades\\DB::table('entry_types')->where('id', $type->id)->update(['settings' => $settings]); }"
        + ` foreach ([${titles}] as $title) { $store(base64_decode('${PNG_BASE64}'), 'probe.png', $title); }`);
}

function removeFixtures() {
    for (const prefix of [PROBE, STATES]) {
        tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->where('title', 'like', "
            + JSON.stringify(`${prefix} %`) + ")->get())"
            + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
    }
}

/*
 * ⚠️ THE TILE STATES' OWN FILES (decision 39), under a prefix the six-row search above never matches, and made old, so
 * the list's first page — sorted by when each changed — is the one the tests above read. Each test below opens the list
 * searched to them. Private images for the answers a press can get, two of them to be taken away after the list loads;
 * a public image; and a public image whose bytes are gone from its disk.
 */
const STATES = 'Tile states';
// ⚠️ NO TITLE INSIDE ANOTHER: a card is found by the text it holds, and `… b` would find `… bytes` too.
const STATE = {
    a: `${STATES} alpha`,
    b: `${STATES} beta`,
    c: `${STATES} gamma`,
    gone: `${STATES} gone`,
    bytes: `${STATES} emptied`,
    public: `${STATES} public`,
    broken: `${STATES} unloaded`,
};

function storeStateFixtures() {
    const privates = [STATE.a, STATE.b, STATE.c, STATE.gone, STATE.bytes].map((title) => `'${title}'`).join(', ');

    tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + " $store = function (string $title, string $visibility) use ($type) { $path = tempnam(sys_get_temp_dir(), 'kitsune-tiles-'); file_put_contents($path, base64_decode('" + PNG_BASE64 + "'));"
        + " try { return \\Kitsune\\Core\\Media\\MediaLibrary::store($path, 'state.png', $type, $visibility, $title); } finally { @unlink($path); } };"
        + ` foreach ([${privates}] as $title) { $store($title, 'private'); }`
        + ` $store('${STATE.public}', 'public');`
        + ` $broken = \\Kitsune\\Core\\Media\\MediaDelivery::fileFor($store('${STATE.broken}', 'public'));`
        + ' \\Illuminate\\Support\\Facades\\Storage::disk($broken->disk)->delete($broken->path);'
        + ` \\Illuminate\\Support\\Facades\\DB::table('entries')->where('title', 'like', '${STATES} %')->update(['updated_at' => '2001-01-01 00:00:00']);`);
}

/**
 * The list, searched to the tile states' own files, as a page load draws it — every card one of them, and the first
 * private one there. Not counted: the test of a deleted entry takes one away for the tests after it.
 */
async function openStates(page, query = '') {
    await page.goto(`/admin/${SITE}/c/image?search=${encodeURIComponent(STATES)}${query}`);
    await expect(card(page, STATE.a)).toBeVisible();
    await expect(page.locator('.fi-ta-record').filter({ hasNotText: STATES })).toHaveCount(0);
}

/** The id of a fixture's entry, read from its card's Edit link. */
async function cardId(page, title) {
    const href = await card(page, title).getByRole('link', { name: 'Edit' }).getAttribute('href');

    return href.match(/\/c\/image\/(\d+)\/edit$/)[1];
}

/** Golfdom's copy-editor, who holds nothing on `image` until a test grants it and revokes it again. */
function copyEditor(action) {
    tinker(IN_GOLFDOM
        + " \\Kitsune\\Core\\Models\\Role::query()->where('handle', 'copy-editor')->firstOrFail()"
        + `->${action}(\\Kitsune\\Core\\Auth\\Permissions::forEntryType('image', 'view'));`);
}

const READER_STATE = '.playwright/admin-reader-auth.json';

/** A private tile's parts: its frame, its button, its status line, and its image once drawn. */
function privateTile(page, title) {
    const frame = card(page, title).locator('[data-kitsune-tile="deferred"]');

    return { frame, button: frame.getByRole('button'), line: frame.locator('[role="status"]'), image: frame.locator('img') };
}

/** The words a private tile says, as the lang file has them. */
const SAYS = {
    loading: 'Loading the preview.',
    shown: 'Preview shown.',
    signedout: 'You are signed out.',
    refused: 'You may no longer see this file.',
    gone: 'This file is no longer available here.',
    failed: 'The preview could not be loaded. Reload the page and try again.',
};

/** Whether a request is to the route that authorises first. */
const toRoute = (url) => /^\/admin\/[^/]+\/media\/\d+$/.test(new URL(url).pathname);

/** The list's *Bulk actions* menu, opened, and the action in it. */
async function bulkAction(page, label) {
    await page.getByRole('button', { name: /bulk actions/i }).click();

    return menuItem(page, label);
}

/** The modal whose submit is this button. */
const modal = (page, submit) => page.locator('.fi-modal-window').filter({ has: page.getByRole('button', { name: submit, exact: true }) }).last();

/** A gate a routed answer waits on, opened by the test. */
function gate() {
    let open;
    const opened = new Promise((resolve) => { open = resolve; });

    return { open, opened };
}

/** A 1×1 PNG. */
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
    removeFixtures();
    storeFixtures();
    storeStateFixtures();
});

test.afterAll(() => removeFixtures());

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
        // The control for the badge below (decision 42): an image that loaded keeps its badge hidden, and is visible.
        await expect(card(page, 'Golfdom logo').locator('[data-kitsune-tile="direct"] .fi-badge')).toBeHidden();
        await expect(image).toHaveCSS('visibility', 'visible');

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

        // Decision 40: asked as an Ajax request naming no Accept of its own, and drawn from that answer's bytes.
        // `allHeaders()`, because Chromium's network stack adds the Accept, which `headers()` leaves out.
        const sent = await response.request().allHeaders();
        expect(sent['x-requested-with']).toBe('XMLHttpRequest');
        expect(sent['accept']).toBe('*/*');
        await expect(tile.locator('img')).toHaveAttribute('src', /^blob:/);
    });

    /*
     * ⚠️ AND NONE ON A SEARCH, A SORT OR A PAGE CHANGE, which re-render every tile. Searched to this spec's six files,
     * five a page, by title descending: the PDF and four private images on the first page, and the fifth on the second.
     * The grid is asserted after each, since the layout is decided as the table is built, on every Livewire request.
     *
     * ⚠️ AND TEN A PAGE AGAIN AFTERWARDS, because Filament keeps the choice in the session, which every spec here shares
     * through one signed-in state: left at five, a later spec's upload could land on a page it never looks at.
     */
    test('makes none on a search, a sort or a page change either', async ({ page }) => {
        const seen = privateRequests(page);

        // By what Filament binds them to, not by their words, which it pads with whitespace; two per-page selects render.
        const perPage = page.locator('.fi-ta select[wire\\:model\\.live="tableRecordsPerPage"]').filter({ visible: true }).first();
        const sortColumn = page.locator('.fi-ta select[x-model="column"]');
        const sortDirection = page.locator('.fi-ta select[x-model="direction"]');
        const [first, ...rest] = PRIVATE_TITLES;

        await page.goto(`/admin/${SITE}/c/image`);

        try {
            await page.locator('.fi-ta').getByPlaceholder('Search').fill(PROBE);
            await expect(page.locator('.fi-ta-record')).toHaveCount(6);

            await perPage.selectOption('5');
            await expect(page.locator('.fi-ta-record')).toHaveCount(5);

            await sortColumn.selectOption('title');
            await sortDirection.selectOption('desc');
            await expect(page.locator('.fi-ta-record').first()).toContainText(PDF_TITLE);
            await expect(page.locator('.fi-ta-content-grid')).toHaveCount(1);

            for (const title of rest) {
                await expect(card(page, title).locator('[data-kitsune-tile="deferred"]')).toBeVisible();
            }

            await page.getByRole('button', { name: 'Next' }).click();
            await expect(page.locator('.fi-ta-record')).toHaveCount(1);
            await expect(card(page, first).locator('[data-kitsune-tile="deferred"]')).toBeVisible();
            await expect(page.locator('.fi-ta-content-grid')).toHaveCount(1);
            await page.waitForLoadState('networkidle');

            expect(seen).toEqual([]);

            // The control: the private tile on this page still loads when clicked.
            const deferred = card(page, first).locator('[data-kitsune-tile="deferred"]');
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

/*
 * ────────────────────────────────  What a private tile says — decisions 39–41  ────────────────────────────────
 */

test.describe('a private tile, pressed', () => {
    /*
     * ⚠️ BY THE KEYBOARD, BECAUSE THAT IS WHO LOST FOCUS. Main hid the button once it was pressed, and focus fell to the
     * page (measured). Shift+Tab then Tab, so focus arrives by the keyboard and the ring is the one a keyboard user sees.
     */
    for (const [key, title] of [['Enter', STATE.a], ['Space', STATE.b]]) {
        test(`keeps keyboard focus on its button once shown, by ${key}, and asks nothing when pressed again`, async ({ page }) => {
            const seen = privateRequests(page);
            await openStates(page);
            const tile = privateTile(page, title);
            const view = card(page, title).getByRole('link', { name: 'View' });

            // The control: before a press, Tab from the button reaches the card's View.
            await tile.button.focus();
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Tab');
            await expect(tile.button).toBeFocused();
            await page.keyboard.press('Tab');
            await expect(view).toBeFocused();
            await page.keyboard.press('Shift+Tab');
            await expect(tile.button).toBeFocused();

            // Its status line is in the page before anything is said, empty: a live region added with its words is not
            // reliably read. Displayed, not merely present — an empty line has no height to be visible by.
            await expect(tile.line).toBeAttached();
            await expect(tile.line).toHaveCSS('display', 'block');
            await expect(tile.line).toHaveText('');

            await page.keyboard.press(key);

            await expect.poll(() => loaded(tile.image)).toBe(true);
            await expect(tile.button).toBeFocused();
            await expect(tile.button).toHaveAttribute('aria-disabled', 'true');
            await expect(tile.button).toHaveAccessibleName(`Show preview of "${title}"`);
            await expect(tile.button).toHaveAccessibleDescription(SAYS.shown);
            await expect(tile.line).toHaveClass(/fi-sr-only/);
            expect(seen).toHaveLength(1);

            await page.keyboard.press(key);
            await page.waitForLoadState('networkidle');
            expect(seen).toHaveLength(1);
            await expect(tile.button).toBeFocused();

            await page.keyboard.press('Tab');
            await expect(view).toBeFocused();
        });
    }

    /*
     * ⚠️ OUTSIDE THE SQUARE. Filament draws a focused link's ring 2px outside it, and main's frame clipped it away. The
     * control: unfocused, there is no ring at all.
     */
    for (const scheme of ['light', 'dark']) {
        test(`draws its focus ring outside its square, idle and shown, ${scheme}`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: scheme });
            await openStates(page);
            await expect(page.locator('html')).toHaveClass(scheme === 'dark' ? /(^|\s)dark(\s|$)/ : /^(?!.*(^|\s)dark(\s|$))/);
            const tile = privateTile(page, STATE.c);
            const ring = () => tile.button.evaluate((el) => {
                const style = getComputedStyle(el);

                return { style: style.outlineStyle, offset: style.outlineOffset, width: style.outlineWidth };
            });

            expect((await ring()).style).toBe('none');
            await expect(tile.frame).toHaveCSS('overflow', 'visible');

            await tile.button.focus();
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Tab');
            expect(await ring()).toEqual({ style: 'solid', offset: '2px', width: '2px' });

            await page.keyboard.press('Enter');
            await expect.poll(() => loaded(tile.image)).toBe(true);
            expect(await ring()).toEqual({ style: 'solid', offset: '2px', width: '2px' });
        });
    }

    /*
     * ⚠️ A REAL 403: the copy-editor's grant on `image` taken away after her list loaded, and given back. The words say
     * what the editor can do next, never that the file is gone.
     */
    test('says the editor may no longer see it when the grant was taken after the list loaded, and shows it once given back', async ({ browser }) => {
        copyEditor('grant');
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();

        try {
            const seen = privateRequests(page);
            await openStates(page);
            const tile = privateTile(page, STATE.a);
            copyEditor('revoke');

            const [refused] = await Promise.all([page.waitForResponse((r) => toRoute(r.url())), tile.button.click()]);

            expect(refused.status()).toBe(403);
            await expect(tile.line).toHaveText(SAYS.refused);
            await expect(tile.line).toBeVisible();
            await expect(tile.button).toBeFocused();
            await expect(tile.button).not.toHaveAttribute('aria-disabled', 'true');
            await expect(tile.button).toHaveAccessibleDescription(SAYS.refused);
            await expect(tile.image).toHaveCount(0);
            expect(seen).toHaveLength(1);

            copyEditor('grant');
            await tile.button.click();

            await expect.poll(() => loaded(tile.image)).toBe(true);
            await expect(tile.button).toHaveAccessibleDescription(SAYS.shown);
            expect(seen).toHaveLength(2);
        } finally {
            copyEditor('revoke');
            await context.close();
        }
    });

    /* ⚠️ REAL 404s, both said alike: an entry deleted after the list loaded, and a file whose bytes were. */
    test('says a file is no longer available here when its entry was deleted, or its bytes were, after the list loaded', async ({ page }) => {
        const seen = privateRequests(page);
        await openStates(page);
        const gone = await cardId(page, STATE.gone);

        tinker(IN_GOLFDOM + ` \\Kitsune\\Core\\Models\\Entry::query()->findOrFail(${gone})->forceDelete();`
            + ` $file = \\Kitsune\\Core\\Media\\MediaDelivery::fileFor(\\Kitsune\\Core\\Models\\Entry::query()->where('title', '${STATE.bytes}')->firstOrFail());`
            + ' \\Illuminate\\Support\\Facades\\Storage::disk($file->disk)->delete($file->path);');

        for (const title of [STATE.gone, STATE.bytes]) {
            const tile = privateTile(page, title);
            const [answer] = await Promise.all([page.waitForResponse((r) => toRoute(r.url())), tile.button.click()]);

            expect(answer.status()).toBe(404);
            await expect(tile.line).toHaveText(SAYS.gone);
            await expect(tile.image).toHaveCount(0);
        }

        expect(seen).toHaveLength(2);
    });

    /*
     * ⚠️ SIGNED OUT: ONE REQUEST, ANSWERED 401, AND NOTHING FOLLOWED (decision 40). Main's image was redirected to sign
     * in and recorded the file as where to land (measured); `MediaTilesTest` holds the middleware to recording nothing.
     * Not signed in again here: Filament allows five sign-ins a minute, and the suite signs in more than that.
     */
    test('says the editor was signed out, asking once and following nothing, and offers to sign in again', async ({ page }) => {
        const seen = privateRequests(page);
        const logins = [];
        page.on('request', (request) => {
            if (new URL(request.url()).pathname.endsWith('/admin/login')) {
                logins.push(request.url());
            }
        });

        await openStates(page);
        const tile = privateTile(page, STATE.a);
        const list = page.url();
        await page.context().clearCookies();

        const [answer] = await Promise.all([page.waitForResponse((r) => toRoute(r.url())), tile.button.click()]);

        expect(answer.status()).toBe(401);
        expect(answer.headers()['location']).toBeUndefined();
        await expect(tile.line).toHaveText(`${SAYS.signedout} Sign in again`);
        await expect(tile.button).toBeFocused();
        expect(page.url()).toBe(list);
        expect(seen).toHaveLength(1);
        expect(logins).toEqual([]);

        // The control: asked as a page asks, the same route redirects to sign in.
        const plain = await page.request.get(answer.url(), { maxRedirects: 0 });
        expect(plain.status()).toBe(302);
        expect(plain.headers()['location']).toMatch(/\/admin\/login$/);

        // The link is the next stop from the button, ringed, and reloads the list, which sends the editor to sign in.
        const link = tile.line.getByRole('link', { name: 'Sign in again' });
        await page.keyboard.press('Tab');
        await expect(link).toBeFocused();
        await expect(link).toHaveCSS('outline-style', 'solid');
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin\/login$/);
    });

    /*
     * ⚠️ EVERY OTHER ANSWER SAYS IT COULD NOT BE LOADED, AND SAYS TO RELOAD; A PRESS AFTER IT ASKS AGAIN, ONCE. Among
     * them a 403 and a 401 that are not the route's own JSON — a gateway's challenge page, basic auth in front — which
     * are never said as Kitsune's reason (review, decision 39).
     */
    test('says it could not be loaded on any other answer, and asks again when pressed again', async ({ page }) => {
        for (const answer of ['500', 'abort', '302', 'gateway 403', 'gateway 401']) {
            const seen = privateRequests(page);
            const logins = [];
            page.on('request', (request) => {
                if (new URL(request.url()).pathname.endsWith('/admin/login')) {
                    logins.push(request.url());
                }
            });
            let first = true;
            await page.route(/\/admin\/[^/]+\/media\/\d+$/, async (route) => {
                if (! first) {
                    return route.continue();
                }

                first = false;

                if (answer === 'abort') {
                    return route.abort('failed');
                }

                if (answer.startsWith('gateway')) {
                    return route.fulfill({ status: Number(answer.slice(-3)), contentType: 'text/html', body: '<h1>Attention Required</h1>' });
                }

                return answer === '500'
                    ? route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>Server error</h1>' })
                    : route.fulfill({ status: 302, headers: { location: '/admin/login' } });
            });

            await openStates(page);
            const tile = privateTile(page, STATE.c);

            await tile.button.click();
            await expect(tile.line, answer).toHaveText(SAYS.failed);
            await expect(tile.line.getByRole('link')).toHaveCount(0);
            await expect(tile.button).toBeFocused();
            await expect(tile.image).toHaveCount(0);
            expect(seen).toHaveLength(1);
            expect(logins, `${answer}: a redirect is never followed`).toEqual([]);

            await tile.button.click();
            await expect.poll(() => loaded(tile.image)).toBe(true);
            await expect(tile.button).toHaveAccessibleDescription(SAYS.shown);
            expect(seen).toHaveLength(2);

            await page.unrouteAll({ behavior: 'ignoreErrors' });
        }
    });

    /*
     * ⚠️ A 200 THAT IS NOT AN IMAGE IS NEVER SAID TO BE SHOWN. What the line says is recorded as it changes, from the
     * first render, so a "shown" that was said and then taken back is caught.
     */
    test('never says it is shown when what arrives is not an image', async ({ page }) => {
        await page.addInitScript(() => {
            window.__said = [];
            new MutationObserver(() => {
                for (const line of document.querySelectorAll('[data-kitsune-tile="deferred"] [role="status"]')) {
                    window.__said.push(line.textContent.trim());
                }
            }).observe(document, { subtree: true, childList: true, characterData: true });
        });
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Sign in</title>' }));

        await openStates(page);
        const tile = privateTile(page, STATE.b);
        await tile.button.click();

        await expect(tile.line).toHaveText(SAYS.failed);
        await expect(tile.image).toHaveCount(0);
        await expect(tile.button).not.toHaveAttribute('aria-disabled', 'true');
        const said = await page.evaluate(() => window.__said);
        expect(said).not.toContain(SAYS.shown);
        expect(said).toContain(SAYS.loading);
    });

    /* ⚠️ A REFUSAL SAID AGAIN PASSES THROUGH ITS LOADING WORDS, so a screen reader hears that something happened. */
    test('says a refusal again when pressed again, passing through its loading words', async ({ page }) => {
        await page.addInitScript(() => {
            window.__said = [];
            new MutationObserver(() => {
                const line = document.querySelector('[data-kitsune-tile="deferred"] [role="status"]');
                const text = line?.textContent.trim();

                if (text && window.__said.at(-1) !== text) {
                    window.__said.push(text);
                }
            }).observe(document, { subtree: true, childList: true, characterData: true });
        });
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => route.fulfill({ status: 403, contentType: 'application/json', body: '{}' }));

        await openStates(page, '&sort=title:asc');
        const tile = privateTile(page, STATE.a);

        await tile.button.click();
        await expect(tile.line).toHaveText(SAYS.refused);
        await tile.button.click();
        await expect.poll(() => page.evaluate(() => window.__said.length)).toBeGreaterThanOrEqual(4);

        expect(await page.evaluate(() => window.__said)).toEqual([SAYS.loading, SAYS.refused, SAYS.loading, SAYS.refused]);
    });

    /* ⚠️ HELD UNTIL EVERY PRESS HAS LANDED, so the third lands while the first is still out, whatever the runner's pace. */
    test('asks once however fast it is pressed', async ({ page }) => {
        const seen = privateRequests(page);
        const held = gate();
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, async (route) => {
            await held.opened;
            await route.continue();
        });

        await openStates(page);
        const tile = privateTile(page, STATE.c);
        await tile.button.dblclick();
        await expect(tile.line).toHaveText(SAYS.loading);
        await tile.button.click();
        held.open();

        await expect.poll(() => loaded(tile.image)).toBe(true);
        expect(seen).toHaveLength(1);
    });

    /*
     * ⚠️ A CARD MOVED LATER IS DRAWN AGAIN AS A BUTTON (measured), and its request still out is dropped: an answer from
     * before lands on nothing, and a later press's answer is the one said.
     */
    test('drops a request still out when its card moves later, and says nothing of it', async ({ page }) => {
        const seen = privateRequests(page);
        const held = gate();
        const aborted = [];
        page.on('requestfailed', (request) => {
            if (toRoute(request.url())) {
                aborted.push(request.failure()?.errorText);
            }
        });
        let answers = 0;
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, async (route) => {
            answers++;

            if (answers === 1) {
                await held.opened;

                return route.fulfill({ status: 403, contentType: 'application/json', body: '{}' }).catch(() => {});
            }

            return route.fulfill({ status: 403, contentType: 'application/json', body: '{}' });
        });

        await openStates(page, '&sort=title:asc');
        await expect(page.locator('.fi-ta-record').first()).toContainText(STATE.a);
        const tile = privateTile(page, STATE.a);

        await tile.button.click();
        await expect(tile.line).toHaveText(SAYS.loading);

        await page.locator('.fi-ta select[x-model="direction"]').selectOption('desc');
        await expect(page.locator('.fi-ta-record').last()).toContainText(STATE.a);

        await expect.poll(() => aborted.length).toBe(1);
        expect(aborted[0]).toMatch(/ERR_ABORTED|aborted/i);
        await expect(tile.line).toHaveText('');
        await expect(tile.button).toBeVisible();

        held.open();
        await page.waitForTimeout(300);
        await expect(tile.line).toHaveText('');

        // A press after the move is answered, and its answer is the one said.
        await tile.button.click();
        await expect(tile.line).toHaveText(SAYS.refused);
        expect(seen).toHaveLength(2);
    });

    /* ⚠️ NO COPY OF AN ORIGINAL IS KEPT: every `blob:` URL a tile made is let go, drawn or not. */
    test('lets go of every file it drew', async ({ page }) => {
        await page.addInitScript(() => {
            window.__blobs = { made: [], gone: new Set() };
            const make = URL.createObjectURL.bind(URL);
            const revoke = URL.revokeObjectURL.bind(URL);
            URL.createObjectURL = (blob) => {
                const url = make(blob);
                window.__blobs.made.push(url);

                return url;
            };
            URL.revokeObjectURL = (url) => {
                window.__blobs.gone.add(url);
                revoke(url);
            };
        });
        let pressed = 0;
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => (++pressed === 2
            ? route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><p>not an image</p>' })
            : route.continue()));

        await openStates(page);
        const shown = privateTile(page, STATE.a);
        const html = privateTile(page, STATE.b);

        await shown.button.click();
        await expect.poll(() => loaded(shown.image)).toBe(true);
        await html.button.click();
        await expect(html.line).toHaveText(SAYS.failed);

        const blobs = await page.evaluate(() => ({ made: window.__blobs.made, gone: [...window.__blobs.gone] }));
        expect(blobs.made).toHaveLength(2);
        expect(blobs.made.every((url) => blobs.gone.includes(url))).toBe(true);

        // Let go, and still drawn: the URL no longer answers, and the image keeps its pixels.
        const src = await shown.image.getAttribute('src');
        expect(await page.evaluate((url) => fetch(url).then(() => 'answered', () => 'refused'), src)).toBe('refused');
        expect(await loaded(shown.image)).toBe(true);
    });

    /* ⚠️ A RE-RENDER THAT KEEPS ITS CARD KEEPS WHAT IT SAID, AND ASKS NOTHING MORE. */
    test('keeps a shown file and a refusal\'s words through a re-render that keeps its card, asking nothing more', async ({ page }) => {
        const seen = privateRequests(page);
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => (new URL(route.request().url()).pathname.endsWith(`/${refusedId}`)
            ? route.fulfill({ status: 403, contentType: 'application/json', body: '{}' })
            : route.continue()));

        await openStates(page);
        const refusedId = await cardId(page, STATE.b);
        const shown = privateTile(page, STATE.a);
        const refused = privateTile(page, STATE.b);

        await shown.button.click();
        await expect.poll(() => loaded(shown.image)).toBe(true);
        await refused.button.click();
        await expect(refused.line).toHaveText(SAYS.refused);
        expect(seen).toHaveLength(2);

        await Promise.all([
            page.waitForResponse((r) => /\/update$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST'),
            page.evaluate(() => window.Livewire.find(document.querySelector('.fi-ta').closest('[wire\\:id]').getAttribute('wire:id')).$refresh()),
        ]);
        await page.waitForLoadState('networkidle');

        expect(await loaded(shown.image)).toBe(true);
        await expect(shown.button).toHaveAttribute('aria-disabled', 'true');
        await expect(refused.line).toHaveText(SAYS.refused);
        expect(seen).toHaveLength(2);
    });

    /*
     * ⚠️ AT 200 % TEXT THE SQUARE GROWS RATHER THAN CUTTING ITS WORDS (WCAG 1.4.4): measured, a button that could shrink
     * was squeezed to nothing beside a refusal's words. The control: at 100 %, the tile is square.
     */
    test('keeps its button and its words in the square at 200 % text', async ({ page }) => {
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '' }));

        await openStates(page);
        const tile = privateTile(page, STATE.a);
        const box = (locator) => locator.evaluate((el) => {
            const { top, bottom, left, right, width, height } = el.getBoundingClientRect();

            return { top, bottom, left, right, width, height };
        });

        const square = await box(tile.frame);
        expect(Math.abs(square.width - square.height)).toBeLessThan(1);

        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        await tile.button.click();
        await expect(tile.line).toHaveText(SAYS.failed);

        const label = tile.button.getByText('Show preview', { exact: true });
        await expect(label).toBeVisible();
        const [frame, button, words, line] = await Promise.all([box(tile.frame), box(tile.button), box(label), box(tile.line)]);
        expect(words.top).toBeGreaterThanOrEqual(button.top - 0.5);
        expect(words.bottom).toBeLessThanOrEqual(button.bottom + 0.5);
        expect(button.height).toBeGreaterThan(0);
        expect(line.bottom).toBeLessThanOrEqual(frame.bottom + 0.5);
    });

    /*
     * ⚠️ A TILE WHOSE KIND CHANGES IN PLACE (review, decision 39). Decision 34's *Make selected public* and *Make selected
     * private* re-render a card where it is, so the frame is the same node while Alpine tears the private tile's script
     * down and reconciles the public tile's data into the same object, and back. A press still out when the switch lands
     * is dropped, and neither kind leaves anything of its own on the other. Measured before only in a static page.
     */
    test('becomes a public tile in place when Make selected public switches its file, with a press still out, and a private one again', async ({ page }) => {
        const seen = privateRequests(page);
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (/Alpine Expression Error/i.test(message.text())) {
                errors.push(message.text());
            }
        });
        const aborted = [];
        page.on('requestfailed', (request) => {
            if (toRoute(request.url())) {
                aborted.push(request.failure()?.errorText);
            }
        });
        const held = gate();
        let answers = 0;
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, async (route) => {
            if (++answers === 1) {
                await held.opened;

                return route.continue().catch(() => {});
            }

            return route.continue();
        });

        await openStates(page);
        const title = STATE.c;
        const tile = privateTile(page, title);
        await tile.frame.evaluate((el) => { el.kitsuneProbe = 'the same node'; });

        await tile.button.click();
        await expect(tile.line).toHaveText(SAYS.loading);

        await card(page, title).getByRole('checkbox').check();
        await (await bulkAction(page, 'Make selected public')).click();
        const asked = modal(page, 'Make public');
        await asked.getByLabel('Make these files public').check();
        await asked.getByRole('button', { name: 'Make public', exact: true }).click();
        await expect(page.locator('.fi-no-notification').filter({ hasText: 'One file was made public' })).toBeVisible({ timeout: 30_000 });

        const direct = card(page, title).locator('[data-kitsune-tile="direct"]');
        await expect(direct).toBeVisible();
        expect(await direct.evaluate((el) => el.kitsuneProbe)).toBe('the same node');
        await expect.poll(() => loaded(direct.locator('img'))).toBe(true);
        await expect(direct.locator('.fi-badge')).toBeHidden();
        await expect(direct.getByRole('button')).toHaveCount(0);
        await expect(direct.locator('[role="status"]')).toHaveCount(0);
        await expect.poll(() => aborted.length).toBe(1);
        expect(aborted[0]).toMatch(/ERR_ABORTED|aborted/i);

        // The answer to the press, released now, lands on nothing.
        held.open();
        await page.waitForTimeout(300);
        await expect(direct.getByRole('button')).toHaveCount(0);
        await expect(direct.locator('.fi-badge')).toBeHidden();

        // And back: an idle button, no words, nothing asked until it is pressed, then one request.
        await card(page, title).getByRole('checkbox').check();
        await (await bulkAction(page, 'Make selected private')).click();
        await modal(page, 'Make private').getByRole('button', { name: 'Make private', exact: true }).click();
        await expect(page.locator('.fi-no-notification').filter({ hasText: 'One file was made private' })).toBeVisible({ timeout: 30_000 });

        await expect(tile.button).toHaveAccessibleName(`Show preview of "${title}"`);
        expect(await tile.frame.evaluate((el) => el.kitsuneProbe)).toBe('the same node');
        await expect(tile.button).not.toHaveAttribute('aria-disabled', 'true');
        await expect(tile.line).toHaveText('');
        await expect(tile.image).toHaveCount(0);
        await expect(tile.frame.locator('.fi-badge')).toHaveCount(0);
        await page.waitForLoadState('networkidle');
        const before = seen.length;

        await tile.button.click();
        await expect.poll(() => loaded(tile.image)).toBe(true);
        await expect(tile.button).toHaveAttribute('aria-disabled', 'true');
        expect(seen).toHaveLength(before + 1);
        expect(errors).toEqual([]);
    });
});

/*
 * ────────────────────────────────  A public image that does not load — decision 42  ────────────────────────────────
 */

test.describe('a public tile that does not load', () => {
    /* ⚠️ NOT ASSERTED BY ITS STATUS: `php artisan serve` answers a missing `/storage` file 403 (measured), a web server 404. */
    test('is an amber badge saying it did not load when its file is missing from its disk', async ({ page }) => {
        await openStates(page);
        const tile = card(page, STATE.broken).locator('[data-kitsune-tile="direct"]');
        const badge = tile.locator('.fi-badge');

        await expect(badge).toBeVisible();
        await expect(badge).toHaveText('Did not load');
        await expect(badge).toHaveClass(/fi-color-warning/);
        await expect(tile.locator('img')).toHaveCSS('visibility', 'hidden');

        // The control: the public file whose bytes are there loads, and its badge stays hidden.
        const control = card(page, STATE.public).locator('[data-kitsune-tile="direct"]');
        await expect.poll(() => loaded(control.locator('img'))).toBe(true);
        await expect(control.locator('.fi-badge')).toBeHidden();
    });

    /*
     * ⚠️ BEFORE ALPINE STARTS AS WELL AS AFTER. Measured, the error came first on 13 of 20 loads. Livewire's script is
     * held so the image fails while Alpine is not there to hear it — the order is asserted, not assumed — and then the
     * image's answer is held instead, so it fails once Alpine is listening.
     */
    test('is a badge whether its image failed before Alpine started or after', async ({ page }) => {
        await page.addInitScript(() => {
            window.__order = [];
            document.addEventListener('error', (event) => {
                if (event.target instanceof HTMLImageElement && event.target.closest('[data-kitsune-tile="direct"]')) {
                    window.__order.push('error');
                }
            }, true);
            document.addEventListener('alpine:init', () => window.__order.push('alpine'));
        });

        await page.route(/\/livewire[^/]*\/livewire(\.min)?\.js(\?.*)?$/, async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.continue();
        });
        await openStates(page);

        const badge = card(page, STATE.broken).locator('[data-kitsune-tile="direct"] .fi-badge');
        await expect(badge).toBeVisible();
        expect((await page.evaluate(() => window.__order)).slice(0, 2)).toEqual(['error', 'alpine']);

        await page.unrouteAll({ behavior: 'ignoreErrors' });
        await page.route(/\/storage\//, async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.continue();
        });
        await openStates(page);

        await expect(badge).toBeVisible();
        const order = await page.evaluate(() => window.__order);
        expect(order.indexOf('alpine')).toBeLessThan(order.indexOf('error'));
    });

    /*
     * ⚠️ AN IMAGE WAITING BELOW THE FOLD IS NOT BROKEN, AND ONE TAKEN FOR BROKEN COMES BACK. Hidden rather than taken out
     * of the layout, a lazy image is still asked for when scrolled to, and its `load` takes the badge back.
     */
    test('is not taken for broken while it waits below the fold, and comes back if it ever is', async ({ page }) => {
        /*
         * Far below the fold: Chromium asks for a lazy image once it is within a few thousand pixels of the viewport, so
         * the grid is pushed further than that, from before the page parses, rather than the window made small.
         */
        await page.addInitScript(() => {
            new MutationObserver((_, observer) => {
                if (document.head) {
                    const style = document.createElement('style');
                    style.textContent = '.fi-ta-content-grid { margin-top: 8000px; }';
                    document.head.append(style);
                    observer.disconnect();
                }
            }).observe(document, { childList: true, subtree: true });
        });
        const storage = [];
        page.on('request', (request) => {
            if (new URL(request.url()).pathname.startsWith('/storage/')) {
                storage.push(request.url());
            }
        });

        for (const forced of [false, true]) {
            storage.length = 0;
            await openStates(page, '&sort=title:asc');
            const tile = card(page, STATE.public).locator('[data-kitsune-tile="direct"]');
            const src = await tile.locator('img').getAttribute('src');
            await page.waitForLoadState('networkidle');

            expect(storage.filter((url) => url.endsWith(src))).toEqual([]);
            await expect(tile.locator('.fi-badge')).toBeHidden();

            if (forced) {
                await tile.evaluate((el) => { window.Alpine.$data(el).failed = true; });
            }

            await tile.scrollIntoViewIfNeeded();
            await expect.poll(() => loaded(tile.locator('img'))).toBe(true);
            await expect(tile.locator('.fi-badge')).toBeHidden();
            expect(storage.filter((url) => url.endsWith(src))).toHaveLength(1);
        }
    });
});

/*
 * ────────────────────────────────  The list, scanned in its states  ────────────────────────────────
 */

test.describe('the media list in its states', () => {
    /* WCAG 2.1 A and AA, as `accessibility.spec.js` scans every page — here with a tile in each of its states. */
    for (const scheme of ['light', 'dark']) {
        test(`has no critical or serious WCAG violations with tiles shown, refused, signed out and not loaded, ${scheme}`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: scheme });
            await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => {
                const path = new URL(route.request().url()).pathname;

                if (path.endsWith(`/${ids.b}`)) {
                    return route.fulfill({ status: 403, contentType: 'application/json', body: '{}' });
                }

                return path.endsWith(`/${ids.c}`)
                    ? route.fulfill({ status: 401, contentType: 'application/json', body: '{}' })
                    : route.continue();
            });

            await openStates(page);
            // The control: the page did render in the scheme asked for.
            await expect(page.locator('html')).toHaveClass(scheme === 'dark' ? /(^|\s)dark(\s|$)/ : /^(?!.*(^|\s)dark(\s|$))/);
            const ids = { b: await cardId(page, STATE.b), c: await cardId(page, STATE.c) };

            await privateTile(page, STATE.a).button.click();
            await expect.poll(() => loaded(privateTile(page, STATE.a).image)).toBe(true);
            await privateTile(page, STATE.b).button.click();
            await expect(privateTile(page, STATE.b).line).toHaveText(SAYS.refused);
            await privateTile(page, STATE.c).button.click();
            await expect(privateTile(page, STATE.c).line).toContainText(SAYS.signedout);
            await expect(card(page, STATE.broken).locator('.fi-badge')).toBeVisible();

            const results = await new AxeBuilder({ page }).include('.fi-ta').withTags(TAGS).analyze();

            if (results.violations.length > 0) {
                console.log(`\nmedia list in its states (${scheme}) — ${results.violations.length} violation(s) at all levels:`);
                for (const v of results.violations) {
                    console.log(`  [${v.impact}] ${v.id}: ${v.help}`);
                    console.log(`    ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target?.join(' ')}`);
                }
            }

            expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
        });
    }
});
