// @ts-check
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { DOWNLOAD, READERS, downloadBytes, seedReaderDownload } = require('./accounts');

/*
 * The reader's download route — ADR-041 (decisions 1–4, Adam, 2026-10-11), as an owner and readers meet it in a browser.
 * An owner offers a private file kept to Golfdom for one entitlement name, from the file's own page; the link it shows
 * lands on a page under the site's reader prefix that names the file and links to its bytes; only a reader signed in
 * there who holds the name gets them.
 *
 * The PHP suite drives every refusal, the query counts and the faults. Only a browser says the offer works from the
 * keyboard and its pages pass axe in both directions, a guest's sign-in comes back to the file, the browser saves the
 * bytes under the title as typed and exactly as stored, and another site's page — whose requests only a browser marks as
 * from elsewhere — gets nothing from an embed and, from a link, the page rather than the bytes.
 *
 * ⚠️ THE FIXTURE IS `seedReaderDownload()`'s (`e2e/accounts.js`): Golfdom's PDF, offered for `club.rules-2026`, which
 * reader 1 holds from `e2e.order:2`. Seeded by `global-setup.js` and again here, idempotently: `entitlements.spec.js`
 * erases reader 1's rows before it counts them, and the first test below stops the offer to make it again.
 *
 * ⚠️ NO STORAGE STATE, as `readers.spec.js` has none: every reader here starts as a stranger and signs in through the
 * form. The two owners are opened from the states `setup` and `setup-rtl` saved, so no admin sign-in is spent here.
 *
 * ⚠️ THE READER THROTTLE IS RESET BEFORE EACH TEST, for `readers.spec.js`'s reason: five sign-ins a minute from one
 * connection, and this whole suite is one connection.
 */

const SKELETON = path.join(__dirname, '..', 'skeleton');
const OWNER_STATE = '.playwright/admin-auth.json';
const RTL_STATE = '.playwright/admin-rtl-auth.json';
const HOLDER = READERS.golfdom;

/** Another site — over https, so Chromium treats it as the secure context its Local Network Access checks ask for. */
const ELSEWHERE = 'https://elsewhere.test';

/** WCAG 2.1 A and AA — the level the project is aiming at. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/** What every reader page is sent with (`ReaderArea::HEADERS`), and what a file offered to readers is sent with instead. */
const PAGE_CSP = "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";
const FILE_CSP = "default-src 'none'; sandbox; frame-ancestors 'none'";

/** The one refusal, for every cause (`readers.download.missing`). */
const MISSING = 'This download isn\'t available to this account. The link may be wrong or out of date, or the file may have been taken down. If you think you should have it, contact Golfdom.';

const sha256 = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');

/** What the download must be, from the bytes the fixture stored — never from the server that sends them. */
const EXPECTED = sha256(downloadBytes());

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: SKELETON, encoding: 'utf8' }).trim();
}

function tinker(code) {
    return artisan('tinker', '--execute', code);
}

/** The name the file's row is offered for, below the model — empty when it is offered to no one. */
function gate(id) {
    return tinker(`echo (string) DB::table('media_files')->where('entry_id', ${Number(id)})->value('reader_entitlement');`);
}

/** Stop the offer as Golfdom's owner, through the writer's own door, so the page can make it again. */
function stopOffering(id) {
    return tinker("$c = app(Kitsune\\Core\\Tenancy\\Context::class); $site = Kitsune\\Core\\Models\\Site::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('slug', 'golfdom')->firstOrFail());"
        + ' $c->forget()->setOrg(Kitsune\\Core\\Models\\Org::query()->findOrFail($site->org_id)); $c->setSite($site);'
        + " auth()->setUser(App\\Models\\User::query()->where('email', 'alpha@kitsune.test')->firstOrFail());"
        + ` echo Kitsune\\Core\\Media\\MediaVisibility::stopOffering(Kitsune\\Core\\Models\\Entry::query()->findOrFail(${Number(id)}));`);
}

/** Sign in through the form, as a person does. */
async function signIn(page, prefix, { email, password }) {
    await page.goto(`${prefix}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

/** A reader page: core's own markup, so every violation fails, as in `readers.spec.js`. */
async function readerAxe(page) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

    expect(results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

/**
 * An admin page: critical and serious only, over whatever is on screen once it has stopped moving — the line
 * `accessibility.spec.js` draws for inherited Filament markup. Text caught mid-transition reads as low contrast.
 *
 * ⚠️ FINITE ANIMATIONS ONLY: a spinner turns forever (the media page has one, measured), and its `finished` never settles.
 */
async function adminAxe(page) {
    await page.evaluate(() => Promise.all(document.getAnimations()
        .filter((animation) => animation.effect?.getComputedTiming().iterations !== Infinity)
        .map((animation) => animation.finished)));

    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const blocking = results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''));

    expect(blocking.map((violation) => `${violation.id}: ${violation.nodes.map((node) => `${node.target.join(' ')} ${node.failureSummary}`).join(' | ')}`)).toEqual([]);
}

/** A header action on the file's page, by its name. */
const headerAction = (page, name) => page.locator('.fi-header').getByRole('button', { name, exact: true });

/** The open modal whose heading this is. */
const modal = (page, heading) => page.locator('.fi-modal-window').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });

/** A notification, by its words. */
const notice = (page, words) => page.locator('.fi-no-notification').filter({ hasText: words });

/** The file's own section on its page. */
const fileSection = (page) => page.locator('.fi-section').filter({ has: page.getByRole('heading', { name: 'File', exact: true }) });

/** The reader link the File section shows, as text. */
const readerLink = (page) => fileSection(page).locator('.fi-in-text-item').filter({ hasText: '/account/downloads/' });

/** The button beside it that copies it. */
const copyButton = (page) => fileSection(page).getByRole('button', { name: 'Copy reader link', exact: true });

/** `APP_URL`'s origin, as the application reads it. */
function appOrigin() {
    return new URL(tinker("echo config('app.url');")).origin;
}

/** A header's directives, in order of name, so `no-store, private` and `private, no-store` are one value. */
const directives = (value) => (value ?? '').split(',').map((directive) => directive.trim()).filter((directive) => directive !== '').sort();

/** The reader pages' headers on a response, and nothing of the file's. */
function expectPageHeaders(headers) {
    expect(headers['content-security-policy']).toBe(PAGE_CSP);
    expect(directives(headers['cache-control'])).toEqual(['no-store', 'private']);
    expect(headers['referrer-policy']).toBe('no-referrer');
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-robots-tag']).toBe('noindex');
    expect(headers['cross-origin-resource-policy']).toBeUndefined();
    expect(headers['content-disposition']).toBeUndefined();
}

/** The fixture as seeded — its entry id, and the sha256 of the bytes its disk holds. */
let fixture = { id: '', sha256: '', size: 0 };

/** The link the owner's page shows, which the guest below opens. */
let shared = '';

const landing = (prefix = '/golfdom') => `${prefix}/account/downloads/${fixture.id}`;
const fileOf = (prefix = '/golfdom') => `${landing(prefix)}/file`;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    fixture = seedReaderDownload();
    // The bytes on the disk are the bytes stored: what a download is compared with is the file, not a copy of it.
    expect(fixture.sha256).toBe(EXPECTED);
});

test.beforeEach(() => artisan('cache:clear'));

// Offered and granted, as `global-setup.js` left it, whatever a failure above left undone.
test.afterAll(() => {
    seedReaderDownload();
});

test('offers the file to readers from its page by the keyboard alone, with no serious WCAG violations, and shows its link', async ({ browser }) => {
    expect(stopOffering(fixture.id)).toBe('switched');
    expect(gate(fixture.id)).toBe('');

    const context = await browser.newContext({ storageState: OWNER_STATE });
    const page = await context.newPage();

    try {
        await page.goto(`/admin/golfdom/c/image/${fixture.id}`);

        // Offered to no one: *Offer to readers* is there, *Make public* is still possible, and the section says so.
        const offer = headerAction(page, 'Offer to readers');
        await expect(offer).toBeEnabled();
        await expect(headerAction(page, 'Change offer')).toHaveCount(0);
        await expect(headerAction(page, 'Make public')).toBeEnabled();
        await expect(fileSection(page)).toContainText('Not offered');
        await adminAxe(page);

        await offer.focus();
        await page.keyboard.press('Enter');

        const dialog = modal(page, `Offer "${DOWNLOAD.title}" to readers`);
        const name = dialog.getByLabel('Entitlement*', { exact: true });
        await expect(name).toBeFocused();

        // An identifier, not a word: left to right whatever the panel's language, and nothing corrects what is typed.
        await expect(name).toHaveAttribute('dir', 'ltr');
        await expect(name).toHaveAttribute('spellcheck', 'false');
        await expect(name).toHaveAttribute('autocapitalize', 'off');
        // Decision 4: served as it is stored, with any location it still holds, and the owner is told so before the offer.
        await expect(dialog).toContainText('Readers get this file as it is stored here: a photo keeps any location it still holds');

        await expect(dialog).toHaveCSS('opacity', '1');
        await adminAxe(page);

        await page.keyboard.type(DOWNLOAD.entitlement);
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('button', { name: 'Offer', exact: true })).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(notice(page, `"${DOWNLOAD.title}" is offered to readers`)).toBeVisible();
        await expect(dialog).toBeHidden();
        expect(gate(fixture.id)).toBe(DOWNLOAD.entitlement);

        // Read again from the server, as anyone opening the page meets it.
        await page.goto(`/admin/golfdom/c/image/${fixture.id}`);

        await expect(headerAction(page, 'Offer to readers')).toHaveCount(0);
        await expect(headerAction(page, 'Change offer')).toBeEnabled();
        await expect(headerAction(page, 'Stop offering')).toBeEnabled();
        // Decision 2: an offered file stays private until the offer stops, and the disabled button says so.
        await expect(headerAction(page, 'Make public')).toBeDisabled();
        await headerAction(page, 'Make public').hover({ force: true });
        await expect(page.getByText('It is offered to readers, so it stays private', { exact: false })).toBeVisible();

        const section = fileSection(page);
        await expect(section).toContainText(DOWNLOAD.entitlement);
        await expect(section).toContainText('Your admin account is not a reader account');

        /*
         * ⚠️ THE LINK'S ORIGIN IS THE APPLICATION'S `APP_URL`, NEVER THE BROWSER'S `baseURL` — `e2e/mail.js`'s rule. Golfdom
         * is host-less, and a host-less site's absolute link is built from `APP_URL` in development alone (ADR-021, as
         * amended), never from the Host the admin was reached through. So it is checked against that, and its path is
         * what the guest below opens on the server this suite talks to.
         */
        const link = readerLink(page);
        await expect(link).toHaveText(`${appOrigin()}/golfdom/account/downloads/${fixture.id}`);
        shared = new URL((await link.innerText()).trim()).pathname;

        // The link is text; its copy control is a real button with a name, reached and pressed from the keyboard, and
        // what it copied is said in Filament's notification — inside its `role="status"` container, so it is announced.
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await expect(fileSection(page).locator('.fi-copyable')).toHaveCount(0);
        const copy = copyButton(page);
        await copy.focus();
        await expect(copy).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('status').filter({ hasText: 'Reader link copied' })).toHaveCount(1);
        await expect(notice(page, 'Reader link copied')).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(`${appOrigin()}${shared}`);

        await adminAxe(page);
    } finally {
        await context.close();
    }
});

test('shows the offer, its name and its link left to right in the Arabic admin, with no serious WCAG violations, its dialog open too', async ({ browser }) => {
    const context = await browser.newContext({ storageState: RTL_STATE });
    const page = await context.newPage();

    try {
        await page.goto(`/admin/golfdom/c/image/${fixture.id}`);
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

        const section = fileSection(page);
        const link = readerLink(page);
        await expect(link).toHaveText(`${appOrigin()}/golfdom/account/downloads/${fixture.id}`);
        expect(await link.evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');
        expect(await section.getByText(DOWNLOAD.entitlement, { exact: true }).evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');
        await expect(copyButton(page)).toBeVisible();

        // Core's notes are English under the Arabic admin, each in its own direction — and text, with no label made from
        // a component's name for a screen reader to read first.
        const note = section.getByText('Your admin account is not a reader account', { exact: false });
        await expect(note).toHaveAttribute('dir', 'auto');
        expect(await note.evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');
        await expect(section.getByText('Media file reader', { exact: false })).toHaveCount(0);
        await adminAxe(page);

        await headerAction(page, 'Change offer').click();

        const dialog = modal(page, `Change which readers may download "${DOWNLOAD.title}"`);
        const name = dialog.getByLabel('Entitlement*', { exact: true });
        await expect(name).toHaveValue(DOWNLOAD.entitlement);
        expect(await name.evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');

        await expect(dialog).toHaveCSS('opacity', '1');
        await adminAxe(page);

        // Closed without a change: the offer stands as the owner made it.
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        expect(gate(fixture.id)).toBe(DOWNLOAD.entitlement);
    } finally {
        await context.close();
    }
});

test('sends a guest from the link to sign in, back to the file\'s page, and saves the stored file under its title', async ({ page }) => {
    expect(shared).toMatch(new RegExp(`/golfdom/account/downloads/${fixture.id}$`));

    const requested = [];
    page.on('request', (request) => requested.push(request.url()));

    await page.goto(shared);
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);

    await page.getByLabel('Email address', { exact: true }).fill(HOLDER.email);
    await page.getByLabel('Password', { exact: true }).fill(HOLDER.password);

    const [arrived] = await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'GET' && new URL(response.url()).pathname === landing()),
        page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);

    await expect(page).toHaveURL(new RegExp(`${landing()}$`));
    expect(arrived.status()).toBe(200);
    expectPageHeaders(arrived.headers());

    // The page's title says "Download", not what was bought: a history is no place to list it.
    await expect(page).toHaveTitle('Download — Golfdom');

    // The owner's title, isolated so it cannot carry the line's direction with it, in a heading that takes its own.
    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toHaveText(`\u2068${DOWNLOAD.title}\u2069`);
    await expect(heading).toHaveAttribute('dir', 'auto');
    // In the site's language, as the owner wrote it for the site — not necessarily the copy's, which `<main>` carries.
    await expect(heading).toHaveAttribute('lang', await page.locator('html').getAttribute('lang') ?? '');
    await expect(page.locator('main')).toContainText(/PDF file, \d+(\.\d)? KB/);

    const download = page.getByRole('link', { name: 'Download', exact: true });
    await expect(download).toHaveAttribute('href', fileOf());
    await readerAxe(page);

    const [saved] = await Promise.all([page.waitForEvent('download'), download.click()]);

    // Sent as `filename*`, so the title arrives as typed rather than as its ASCII slug.
    expect(saved.suggestedFilename().normalize('NFC')).toBe(DOWNLOAD.filename.normalize('NFC'));
    expect(await saved.failure()).toBeNull();

    // Every byte, in order: three of the stream's 256 KiB chunks, and the file the fixture stored.
    const bytes = fs.readFileSync(await saved.path());
    expect(bytes.length).toBe(fixture.size);
    expect(sha256(bytes)).toBe(EXPECTED);

    // The page stays where it was, and nothing was fetched from another host.
    await expect(page).toHaveURL(new RegExp(`${landing()}$`));

    const own = new URL(page.url()).host;
    const foreign = requested.map((url) => new URL(url)).filter((url) => ['http:', 'https:'].includes(url.protocol) && url.host !== own);
    expect(foreign.map((url) => url.href)).toEqual([]);
});

test('sends the page with the reader pages\' headers, and the file with its own: sandboxed, never framed, never another origin\'s, never stored', async ({ page }) => {
    await signIn(page, '/golfdom', HOLDER);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    const html = await page.request.get(landing());
    expect(html.status()).toBe(200);
    expectPageHeaders(html.headers());

    const file = await page.request.get(fileOf());
    const headers = file.headers();
    expect(file.status()).toBe(200);
    expect(headers['content-type']).toBe('application/pdf');
    expect(headers['content-length']).toBe(String(fixture.size));
    expect(headers['content-disposition']).toMatch(/^attachment; filename=[^;]+\.pdf; filename\*=utf-8''R%C3%A8gles%20du%20parcours%202026\.pdf$/);
    expect(headers['content-security-policy']).toBe(FILE_CSP);
    expect(headers['cross-origin-resource-policy']).toBe('same-origin');
    expect(directives(headers['cache-control'])).toEqual(['no-store', 'private']);
    expect(headers['referrer-policy']).toBe('no-referrer');
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-robots-tag']).toBe('noindex');
    expect(sha256(await file.body())).toBe(EXPECTED);

    // ⚠️ AND KEPT TO GOLFDOM (decision 2): the holder at Golfdom's Arabic edition, the same org and their own session,
    // is told what everyone refused is told — and the refusal keeps the page's headers, not the file's.
    const elsewhere = await page.request.get(fileOf('/golfdom-ar'));
    expect(elsewhere.status()).toBe(404);
    expectPageHeaders(elsewhere.headers());
});

test('tells a reader without the name what it tells anyone about a file that does not exist, left to right and right to left', async ({ page }) => {
    await signIn(page, '/golfdom', READERS.golfdom2);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    const refused = await page.goto(landing());
    expect(refused?.status()).toBe(404);
    expectPageHeaders(refused?.headers() ?? {});
    await expect(page).toHaveTitle('Download — Golfdom');
    await expect(page.getByRole('alert')).toHaveText(MISSING);
    await expect(page.getByRole('link', { name: 'Your account', exact: true })).toHaveAttribute('href', '/golfdom/account');
    await expect(page.getByRole('link', { name: 'Download', exact: true })).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText(DOWNLOAD.title);
    await readerAxe(page);

    // ⚠️ ONE ANSWER: an id that names nothing is told exactly the same, so the page cannot say which files exist.
    const told = await page.locator('main').innerHTML();
    expect((await page.goto('/golfdom/account/downloads/98765432'))?.status()).toBe(404);
    expect(await page.locator('main').innerHTML()).toBe(told);

    // The bytes' own address, typed: sent to the file's page, and refused there — no download, no bytes.
    let downloaded = false;
    page.on('download', () => { downloaded = true; });
    await page.goto(fileOf());
    await expect(page).toHaveURL(new RegExp(`${landing()}$`));
    await expect(page.getByRole('alert')).toHaveText(MISSING);
    const direct = await page.request.get(fileOf());
    expect(direct.status()).toBe(404);
    expectPageHeaders(direct.headers());
    expect(downloaded).toBe(false);

    // Right to left: Golfdom's Arabic edition, where the reader is signed in too (one org), and the document is Arabic.
    const rtl = await page.goto(landing('/golfdom-ar'));
    expect(rtl?.status()).toBe(404);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.getByRole('alert')).toContainText('This download isn\'t available to this account.');
    await readerAxe(page);
});

test('gives another site\'s page nothing, though it carries the reader\'s session: its embeds are refused unread, and its link lands on the file\'s page', async ({ page, baseURL }) => {
    await signIn(page, '/golfdom', HOLDER);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    const context = page.context();
    const origin = new URL(String(baseURL)).origin;
    const target = `${origin}${fileOf()}`;
    const cookie = tinker("echo config('session.cookie');");

    /*
     * ⚠️ THE READER'S SESSION GOES WITH THE EMBEDS, as it would under `SESSION_SAME_SITE=none` — the configuration
     * `kitsune:readers status` warns about, and the one `IsolateDownload` exists for. Under `lax`, Laravel's default, the
     * browser sends no session with another site's image and the request is a guest's; rewritten to `None` (and `Secure`,
     * which Chromium requires of it and allows on loopback), it is the holder's — so a refusal here is the route refusing
     * a holder, not a guest.
     */
    await context.addCookies((await context.cookies()).map((stored) => ({ ...stored, sameSite: 'None', secure: true })));

    /*
     * ⚠️ AND THE BROWSER PUT WHERE A REAL ONE IS. Deployed, Kitsune and the other site are both on the public internet,
     * and Chromium's Local Network Access checks play no part. Here the server is on loopback, so Chromium refuses another
     * site's embed of it before a request leaves (measured: `ERR_BLOCKED_BY_LOCAL_NETWORK_ACCESS_CHECKS`), and "the page
     * got no image" would pass without Kitsune being asked. The other site is served over https — the checks ask a secure
     * context — and given the permission a visitor could grant it, so what is measured is Kitsune's answer.
     */
    await context.grantPermissions(['local-network-access'], { origin: ELSEWHERE });

    /*
     * ⚠️ READ FROM THE NETWORK AS CHROMIUM SAW IT, through its own protocol: what each embed sent — where it came from,
     * what it was for, whether the session went with it — and the status the server answered. Playwright's own events
     * cannot say it: Chromium blocks the image's answer (ORB) and the frame's (`frame-ancestors`) after it arrives, and
     * reports neither status, so a page that got nothing would look the same whether Kitsune refused or served the file.
     */
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    const embeds = new Map();
    const sent = new Map();
    const answered = new Map();

    cdp.on('Network.requestWillBeSent', (event) => {
        if (event.request.url === target) {
            embeds.set(event.requestId, event.type);
        }
    });
    cdp.on('Network.requestWillBeSentExtraInfo', (event) => {
        const header = (name) => Object.entries(event.headers).find(([key]) => key.toLowerCase() === name)?.[1] ?? '';
        sent.set(event.requestId, {
            site: header('sec-fetch-site'),
            dest: header('sec-fetch-dest'),
            session: header('cookie').split(';').some((pair) => pair.trim().startsWith(`${cookie}=`)),
        });
    });
    cdp.on('Network.responseReceivedExtraInfo', (event) => answered.set(event.requestId, event.statusCode));

    let downloaded = false;
    page.on('download', () => { downloaded = true; });

    // Another site's page, as a browser meets one: its own origin, an image and a frame pointed at the bytes, and a link.
    await page.route(`${ELSEWHERE}/**`, (route) => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: '<!doctype html><html lang="en"><head><title>Elsewhere</title></head><body>'
            + `<img id="probe" alt="" src="${target}"><iframe id="frame" title="Embedded" src="${target}"></iframe>`
            + `<p><a id="link" href="${target}">Your course rules</a></p></body></html>`,
    }));

    await page.goto(`${ELSEWHERE}/`);

    const image = page.locator('#probe');
    await expect.poll(() => image.evaluate((element) => element instanceof HTMLImageElement && element.complete)).toBe(true);
    expect(await image.evaluate((element) => (element instanceof HTMLImageElement ? element.naturalWidth : -1))).toBe(0);

    // ⚠️ BOTH EMBEDS REACHED KITSUNE WITH THE HOLDER'S SESSION, AND BOTH WERE THE 404 — not the file, and not the guest's
    // 303 `RequireReader` sends: `IsolateDownload` answered from the request's Fetch Metadata, before the reader was read.
    await expect.poll(() => [...embeds.keys()].filter((id) => answered.has(id) && sent.has(id)).length).toBe(2);
    const seen = [...embeds].map(([id, type]) => ({ type, ...sent.get(id), status: answered.get(id) }))
        .sort((a, b) => a.type.localeCompare(b.type));
    expect(seen).toEqual([
        { type: 'Document', site: 'cross-site', dest: 'iframe', session: true, status: 404 },
        { type: 'Image', site: 'cross-site', dest: 'image', session: true, status: 404 },
    ]);
    expect(downloaded).toBe(false);

    // ⚠️ A LINK FROM ELSEWHERE STRAIGHT TO THE BYTES lands on the file's page, the reader still signed in, and downloads
    // nothing: the page never downloads by itself, so a window another site opens on it learns nothing it can read.
    await page.locator('#link').click();
    await expect(page).toHaveURL(`${origin}${landing()}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`\u2068${DOWNLOAD.title}\u2069`);
    await expect(page.getByRole('link', { name: 'Download', exact: true })).toBeVisible();
    expect(downloaded).toBe(false);

    await cdp.detach();
});

test('sends Rival\'s reader to Golfdom\'s sign-in as it sends a guest, for the page and the bytes, and a sign-in from the bytes\' link returns to the page', async ({ page }) => {
    // The same address as reader 1, with Rival's own password: another org's reader, and nobody at Golfdom.
    await signIn(page, '/rival', READERS.rival);
    await expect(page).toHaveURL(/\/rival\/account$/);

    for (const address of [landing(), fileOf()]) {
        await page.goto(address);
        await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);
    }

    // Asked directly: the guest's 303, the same for an id that names nothing — never a 404 that would say which exists.
    for (const address of [landing(), fileOf(), '/golfdom/account/downloads/98765432']) {
        const response = await page.request.get(address, { maxRedirects: 0 });
        expect(response.status()).toBe(303);
        expect(response.headers().location).toBe('/golfdom/account/sign-in');
    }

    // The control: their own org's pages still know them.
    await page.goto('/rival/account');
    await expect(page.getByText(`Signed in as ${READERS.rival.email}.`, { exact: true })).toBeVisible();

    /*
     * ⚠️ AND A LINK STRAIGHT TO THE BYTES COMES BACK TO THE PAGE AFTER A SIGN-IN, not to the bytes and not to the account:
     * opened from the address bar it is sent to its page, which keeps itself to return to, and the sign-in there — reader
     * 1's, at Golfdom, which takes this browser's one reader slot — lands on it. Nothing downloads on the way.
     */
    let downloaded = false;
    page.on('download', () => { downloaded = true; });

    await page.goto(fileOf());
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);
    await page.getByLabel('Email address', { exact: true }).fill(HOLDER.email);
    await page.getByLabel('Password', { exact: true }).fill(HOLDER.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`${landing()}$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`\u2068${DOWNLOAD.title}\u2069`);
    expect(downloaded).toBe(false);
});
