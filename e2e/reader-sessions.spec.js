// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { READERS } = require('./accounts');
const { mailMark, mailTo, mailLink } = require('./mail');

/*
 * Staff and a reader in one browser — ADR-037, as built. One cookie carries both sessions, under a key per guard; a
 * reader's sign-out must not end the staff session, nor refuse its next form.
 *
 * ⚠️ THE OWNER SIGNS IN HERE, IN THIS SPEC'S OWN CONTEXT, NEVER FROM THE SAVED STATE. A reader's sign-in rotates the
 * session's id and destroys the old one, so a context opened from `.playwright/admin-auth.json` would end the session
 * every other admin spec reuses.
 *
 * ⚠️ NAMED RESIDUALS, ASSERTED SO A CHANGE IS NOTICED: a reader's sign-in rotates the CSRF token — which a browser's
 * same-origin request does not notice, because Laravel lets one through before it compares tokens, but a client that
 * sends no Fetch Metadata does, once (419); and Filament's logout invalidates the whole session, the reader's too.
 */

const SKELETON = path.join(__dirname, '..', 'skeleton');
const GOLFDOM = READERS.golfdom;
const GOLFDOM2 = READERS.golfdom2;

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: SKELETON, encoding: 'utf8' }).trim();
}

async function ownerSignsIn(page) {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('alpha@kitsune.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'), { timeout: 30_000 });
}

async function csrfToken(page) {
    return (await page.locator('meta[name="csrf-token"]').getAttribute('content')) || '';
}

async function readerSignsIn(page) {
    await page.goto('/golfdom/account/sign-in');
    await page.getByLabel('Email address', { exact: true }).fill(GOLFDOM.email);
    await page.getByLabel('Password', { exact: true }).fill(GOLFDOM.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/golfdom\/account$/);
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => artisan('cache:clear'));

test('keeps the owner signed in while a reader signs in and out in the same browser', async ({ browser }) => {
    const context = await browser.newContext();
    const admin = await context.newPage();
    const reader = await context.newPage();

    await ownerSignsIn(admin);
    await readerSignsIn(reader);

    // The staff session survived the reader's sign-in.
    await admin.reload();
    await expect(admin).toHaveURL(/\/admin\/golfdom$/);
    await expect(admin.getByRole('heading', { level: 1 })).toBeVisible();

    // The token the admin tab now holds, then the reader signs out.
    await admin.goto('/admin/golfdom/c/article');
    const token = await csrfToken(admin);
    await reader.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(reader.getByRole('status')).toHaveText('You\'ve signed out.');

    // The same token is still the session's: a form posted with it is checked, not refused as forged.
    const posted = await admin.request.post('/golfdom/account/sign-in', { form: { _token: token, email: 'nobody@kitsune.test', password: 'not-the-password-at-all' }, maxRedirects: 0 });
    expect(posted.status()).toBe(422);

    // And the admin tab carries on.
    const update = admin.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/update'));
    await admin.locator('.fi-ta').getByPlaceholder('Search').fill('a');
    expect((await update).status()).toBe(200);

    await context.close();
});

test('rotates the token at a reader\'s sign-in, which a browser\'s same-origin request does not notice — a named residual', async ({ browser }) => {
    const context = await browser.newContext();
    const admin = await context.newPage();
    const reader = await context.newPage();

    await ownerSignsIn(admin);
    await admin.goto('/admin/golfdom/c/article');
    const before = await csrfToken(admin);
    await readerSignsIn(reader);

    // Checked by the token alone, as a client that sends no Fetch Metadata is: the old token is refused.
    const stale = await admin.request.post('/golfdom/account/sign-out', { form: { _token: before }, maxRedirects: 0 });
    expect(stale.status()).toBe(419);

    // A browser marks its own request same-origin, and Laravel's check lets that through before it compares tokens,
    // so the admin tab left open across the reader's sign-in keeps working.
    const update = admin.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/update'));
    await admin.locator('.fi-ta').getByPlaceholder('Search').fill('a');
    expect((await update).status()).toBe(200);

    await context.close();
});

test('signs the reader out with the owner when Filament logs out — a named residual', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await ownerSignsIn(page);
    await readerSignsIn(page);

    // Filament's own logout route, posted as its user menu posts it: with the page's token.
    await page.goto('/admin/golfdom');
    const out = await page.request.post('/admin/logout', { form: { _token: await csrfToken(page) }, maxRedirects: 0 });
    expect(out.status()).toBe(302);

    await page.goto('/admin/golfdom');
    await expect(page).toHaveURL(/\/admin\/login$/);

    await page.goto('/golfdom/account');
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);

    await context.close();
});

test('keeps the owner working after a reader chooses a new password in the same browser — one reload, a named residual', async ({ browser }) => {
    const context = await browser.newContext();
    const admin = await context.newPage();
    const reader = await context.newPage();

    await ownerSignsIn(admin);
    await admin.goto('/admin/golfdom/c/article');

    // Reader 2's recovery, in the owner's browser: a reset signs its reader in, which rotates the session as sign-in does.
    const mark = mailMark();
    await reader.goto('/golfdom/account/recover');
    await reader.getByLabel('Email address', { exact: true }).fill(GOLFDOM2.email);
    await reader.getByRole('button', { name: 'Send me a link', exact: true }).click();
    await reader.goto(String(mailLink(await mailTo(GOLFDOM2.email, mark))));
    await reader.getByLabel('New password', { exact: true }).fill(GOLFDOM2.password);
    await reader.getByLabel('Type the same password again', { exact: true }).fill(GOLFDOM2.password);
    await reader.getByRole('button', { name: 'Change my password', exact: true }).click();
    await expect(reader).toHaveURL(/\/golfdom\/account$/);

    // The staff session survived it, and after one reload the admin carries on.
    await admin.reload();
    await expect(admin).toHaveURL(/\/admin\/golfdom\/c\/article$/);
    const update = admin.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/update'));
    await admin.locator('.fi-ta').getByPlaceholder('Search').fill('a');
    expect((await update).status()).toBe(200);

    await context.close();
});

