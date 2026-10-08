// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { READERS } = require('./accounts');

/*
 * A reader signs in — ADR-037, as built: the skeleton's own readers, at core's pages under each site's prefix. The PHP
 * suite drives every refusal; only a browser says the error summary takes focus, its link reaches the field, the pages
 * pass axe in both directions, nothing is fetched from another host, and a reader's session still cannot open a panel.
 *
 * ⚠️ NO STORAGE STATE: every test starts as a stranger.
 *
 * ⚠️ THE THROTTLE IS RESET BEFORE EACH TEST. Sign-in allows five POSTs a minute from one connection, success included,
 * and this whole suite is one connection — 127.0.0.1. Clearing the cache is the harness standing in for a minute passing.
 */

const SKELETON = path.join(__dirname, '..', 'skeleton');
const GOLFDOM = READERS.golfdom;
const RIVAL = READERS.rival;

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: SKELETON, encoding: 'utf8' }).trim();
}

/** Sign in through the form, as a person does. */
async function signIn(page, prefix, { email, password }) {
    await page.goto(`${prefix}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

async function axe(page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

    expect(results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => artisan('cache:clear'));

test('signs reader 1 in at Golfdom, to an account page that names them — accessible, and fetching nothing from elsewhere', async ({ page }) => {
    const requested = [];
    page.on('request', (request) => requested.push(request.url()));

    await page.goto('/golfdom/account/sign-in');
    await expect(page).toHaveTitle('Sign in — Golfdom');
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    await axe(page);

    await signIn(page, '/golfdom', GOLFDOM);

    await expect(page).toHaveURL(/\/golfdom\/account$/);
    await expect(page).toHaveTitle('Your account — Golfdom');
    await expect(page.getByText(`Signed in as ${GOLFDOM.email}.`, { exact: true })).toBeVisible();
    await axe(page);

    const own = new URL(page.url()).host;
    const foreign = requested.map((url) => new URL(url)).filter((url) => ['http:', 'https:'].includes(url.protocol) && url.host !== own);

    expect(foreign.map((url) => url.href)).toEqual([]);
});

test('works from the keyboard alone: a refusal focuses its summary, whose link reaches the field', async ({ page }) => {
    await page.goto('/golfdom/account/sign-in');

    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Email address', { exact: true })).toBeFocused();
    await page.keyboard.type(GOLFDOM.email);
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Password', { exact: true })).toBeFocused();
    await page.keyboard.type('not-the-password-at-all');
    await page.keyboard.press('Enter');

    await expect(page).toHaveTitle('Error: Sign in — Golfdom');

    const summary = page.getByRole('alert');
    await expect(summary).toBeFocused();
    await expect(summary.getByRole('heading', { name: 'There is a problem' })).toBeVisible();
    await axe(page);

    await page.keyboard.press('Tab');
    const link = summary.getByRole('link', { name: 'That email address and password don\'t match an account here. Check both and try again.' });
    await expect(link).toBeFocused();
    await page.keyboard.press('Enter');

    const email = page.getByLabel('Email address', { exact: true });
    await expect(email).toBeFocused();
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveValue(GOLFDOM.email);
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');

    const describedBy = await email.getAttribute('aria-describedby');
    await expect(page.locator(`#${describedBy}`)).toBeVisible();
    await expect(page.locator(`#${describedBy}`)).toHaveText('Error: That email address and password don\'t match an account here. Check both and try again.');
});

test('signs out, says so, and the account page then asks to sign in', async ({ page }) => {
    await signIn(page, '/golfdom', GOLFDOM);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    await page.getByRole('button', { name: 'Sign out', exact: true }).click();

    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);
    await expect(page.getByRole('status')).toHaveText('You\'ve signed out.');
    await axe(page);

    await page.goto('/golfdom/account');
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);
});

test('keeps one org\'s reader out of another\'s, from the attacker\'s side', async ({ page }) => {
    await signIn(page, '/golfdom', GOLFDOM);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    // Signed in at Golfdom is nobody at Rival.
    await page.goto('/rival/account');
    await expect(page).toHaveURL(/\/rival\/account\/sign-in$/);

    // The same address, with Golfdom's password: Rival's reader 3 has their own.
    await signIn(page, '/rival', { email: RIVAL.email, password: GOLFDOM.password });
    await expect(page).toHaveTitle('Error: Sign in — Rival Golfdom');

    await signIn(page, '/rival', RIVAL);
    await expect(page).toHaveURL(/\/rival\/account$/);
    await expect(page.getByText(`Signed in as ${RIVAL.email}.`, { exact: true })).toBeVisible();

    // One reader slot per browser: signing in at Rival replaced Golfdom's.
    await page.goto('/golfdom/account');
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);
});

test('keeps a reader signed in across the sites of one org', async ({ page }) => {
    await signIn(page, '/golfdom', GOLFDOM);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    await page.goto('/golfdom-fr/account');
    await expect(page).toHaveURL(/\/golfdom-fr\/account$/);
    await expect(page.getByText(`Signed in as ${GOLFDOM.email}.`, { exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
});

test('puts the site\'s language on the document and the copy\'s on its words, at any depth', async ({ page }) => {
    await page.goto('/golfdom-ar/account/sign-in');

    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('main')).toHaveAttribute('lang', 'en');
    await expect(page.locator('main')).toHaveAttribute('dir', 'ltr');
    expect(await page.locator('main').evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');
    await axe(page);

    await page.goto('/news/fr/account/sign-in');
    await expect(page).toHaveTitle('Sign in — Golfdom Nested');
    await expect(page.locator('html')).toHaveAttribute('lang', 'he');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
});

test('cannot drive a panel\'s component as a reader, even with a snapshot the panel rendered for staff', async ({ browser }) => {
    // A real update request, as the owner's browser sends it from the article list.
    // Signed in here, not from the saved state: this project depends on no setup.
    const staff = await browser.newContext();
    const admin = await staff.newPage();
    await admin.goto('/admin/login');
    await admin.locator('input[type="email"]').fill('alpha@kitsune.test');
    await admin.locator('input[type="password"]').fill('password');
    await admin.locator('form').getByRole('button', { name: 'Sign in' }).click();
    await admin.waitForURL((url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'), { timeout: 30_000 });
    await admin.goto('/admin/golfdom/c/article');
    const sent = admin.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/update'));
    await admin.locator('.fi-ta').getByPlaceholder('Search').fill('a');
    const request = await sent;
    const url = new URL(request.url()).pathname;
    const body = JSON.parse(request.postData() || '{}');

    // The control: replayed from the owner's own session, the request is one the panel accepts.
    const staffToken = (await admin.locator('meta[name="csrf-token"]').getAttribute('content')) || '';
    const accepted = await admin.request.post(url, {
        data: { ...body, _token: staffToken },
        headers: { 'X-Livewire': '1', 'X-CSRF-TOKEN': staffToken, Accept: 'application/json' },
        maxRedirects: 0,
    });
    expect(accepted.status()).toBe(200);
    await staff.close();

    // The same request from a browser where only a reader is signed in, carrying that session's own token.
    const readerContext = await browser.newContext();
    const page = await readerContext.newPage();
    await signIn(page, '/golfdom', GOLFDOM);
    await expect(page).toHaveURL(/\/golfdom\/account$/);
    const token = await page.locator('input[name="_token"]').first().inputValue();

    const replayed = await page.request.post(url, {
        data: { ...body, _token: token },
        headers: { 'X-Livewire': '1', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        maxRedirects: 0,
    });

    expect([401, 403]).toContain(replayed.status());
    await readerContext.close();
});

test('cannot reach a panel as a reader, from the reader\'s side', async ({ page }) => {
    await signIn(page, '/golfdom', GOLFDOM);
    await expect(page).toHaveURL(/\/golfdom\/account$/);

    for (const panelPath of ['/admin', '/admin/golfdom', '/admin/golfdom/entitlements']) {
        await page.goto(panelPath);
        await expect(page).toHaveURL(/\/admin\/login$/);
    }

    // A reader's address and password are not staff credentials.
    await page.locator('input[type="email"]').fill(GOLFDOM.email);
    await page.locator('input[type="password"]').fill(GOLFDOM.password);
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/login$/);

    // And the reader is still signed in where readers are.
    await page.goto('/golfdom/account');
    await expect(page.getByText(`Signed in as ${GOLFDOM.email}.`, { exact: true })).toBeVisible();
});
