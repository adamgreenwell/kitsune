// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const crypto = require('node:crypto');

/*
 * The credentials page — ADR-040, its admin half: "a secret that cannot be read back through any admin path", measured
 * rather than argued.
 *
 * ⚠️ THE CLAIM, EXACTLY: a value crosses the admin once, as the body of one POST to `…/credentials/set`, and no response,
 * no Livewire request or snapshot, no redirect and no page afterwards holds any twelve characters of it. The PHP suite
 * cannot see a browser's traffic, which is the whole reason this file exists (AGENTS.md §9).
 *
 * ⚠️ THE CREDENTIALS ARE THE TEST MODULE'S, `kitsune/e2e-credential-slots`, which `global-setup.js` enables and nothing
 * publishes. Values are made here at runtime, never written into the repository.
 *
 * This session is Golfdom's owner. The refusal for a member who is not one is `permissions.spec.js`'s.
 */

const SITE = 'golfdom';
const PAGE = `/admin/${SITE}/credentials`;
const SET = `/admin/${SITE}/credentials/set`;
const LIVEWIRE = /livewire-[0-9a-f]+\/update/;
const STATIC = /\.(css|js|woff2?)(\?|$)/;

const PAYMENT = 'Browser-test payment key';
const HOOK = 'Browser-test webhook secret';
const SHARED = 'Browser-test shared secret';

/** A value that fits a line: the declared prefix, then forty letters and digits that survive any encoding unchanged. */
function value(prefix = '') {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let out = prefix;

    for (const byte of crypto.randomBytes(40)) {
        out += alphabet[byte % alphabet.length];
    }

    return out;
}

/** The value and every twelve characters of it: what "holds a piece of it" means. */
function windows(v) {
    const out = [v];

    for (let at = 0; at + 12 <= v.length; at++) {
        out.push(v.slice(at, at + 12));
    }

    return out;
}

/** Every request and every dynamic response the page sees, bodies included. */
function capture(page) {
    const seen = { requests: [], responses: [], pending: [] };

    page.on('request', (request) => {
        seen.requests.push({ url: request.url(), method: request.method(), body: request.postData() || '' });
    });

    page.on('response', (response) => {
        if (STATIC.test(response.url())) {
            return;
        }

        seen.pending.push((async () => {
            let body = '';

            try {
                body = await response.text();
            } catch {
                // A redirect has no body to read here; its Location is checked, and its body through `page.request`.
            }

            seen.responses.push({ url: response.url(), status: response.status(), location: response.headers().location || '', body });
        })());
    });

    return seen;
}

/** Nothing of the value in any response, any Livewire request, any URL, the page or a component snapshot. */
async function expectClean(page, seen, v) {
    await Promise.all(seen.pending);

    for (const piece of windows(v)) {
        for (const response of seen.responses) {
            expect(response.body.includes(piece), `a response from ${response.url} holds a piece of the value`).toBe(false);
            expect(response.location.includes(piece), `a redirect from ${response.url} holds a piece of the value`).toBe(false);
        }
    }

    for (const request of seen.requests) {
        expect(request.url.includes(v), `a request URL holds the value: ${request.method} ${request.url}`).toBe(false);

        if (LIVEWIRE.test(request.url)) {
            expect(request.body.includes(v), 'a Livewire request holds the value').toBe(false);
        }
    }

    const content = await page.content();
    const snapshots = await page.locator('[wire\\:snapshot]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('wire:snapshot') || ''));

    for (const piece of windows(v)) {
        expect(content.includes(piece), 'the page holds a piece of the value').toBe(false);

        for (const snapshot of snapshots) {
            expect(snapshot.includes(piece), 'a component snapshot holds a piece of the value').toBe(false);
        }
    }
}

/** The bodies that carried the value: the claim is that there is exactly one, and it is the set POST. */
function carriers(seen, v) {
    return seen.requests.filter((request) => request.body.includes(v)).map((request) => `${request.method} ${new URL(request.url).pathname}`);
}

/** One line of the page: a credential's section, and its mode's fieldset where it keeps one per mode. */
function lineOf(page, label, mode) {
    const section = page.locator('section.fi-section').filter({ has: page.getByRole('heading', { name: label, exact: true }) });

    return mode ? section.locator('fieldset').filter({ has: page.locator('legend', { hasText: mode === 'test' ? 'Test mode' : 'Live mode' }) }) : section;
}

function lineName(label, mode) {
    return mode ? `${label} (${mode} mode)` : label;
}

/** Set or Replace, whichever the line offers now — tests do not depend on each other's leftovers. */
async function openSet(page, label, mode) {
    const name = lineName(label, mode);
    const set = page.getByRole('button', { name: `Set ${name}`, exact: true });
    await (await set.count() ? set : page.getByRole('button', { name: `Replace ${name}`, exact: true })).click();

    const modal = page.locator('.fi-modal-window').filter({ has: page.locator(`form[action$="/credentials/set"]`) });
    await expect(modal).toBeVisible();

    return modal;
}

async function setValue(page, label, mode, v) {
    const modal = await openSet(page, label, mode);
    await modal.locator('input[name="password"]').fill(v);
    await Promise.all([page.waitForURL(PAGE), modal.getByRole('button', { name: 'Save', exact: true }).click()]);
}

async function csrf(page) {
    return page.locator('meta[name="csrf-token"]').getAttribute('content');
}

async function switchTo(page, mode) {
    const label = mode === 'live' ? 'Switch to live mode' : 'Switch to test mode';
    const button = page.getByRole('button', { name: label, exact: true }).first();

    if (! await button.count()) {
        return;
    }

    await button.click();
    await page.locator('.fi-modal-window').getByRole('button', { name: label, exact: true }).click();
    await expect(page.locator('.fi-no-notification').filter({ hasText: mode === 'live' ? 'Now in live mode' : 'Now in test mode' })).toBeVisible();
}

test.describe('the credentials page', () => {
    test('is linked from an owner\'s sidebar, and lists what the enabled modules declare', async ({ page }) => {
        // From the dashboard, not the page itself: a nav item's URL closure runs on every page (AGENTS.md §9).
        await page.goto(`/admin/${SITE}`);
        const link = page.locator('.fi-sidebar').getByRole('link', { name: 'Credentials', exact: true });
        await expect(link).toHaveAttribute('href', new RegExp(`${PAGE}$`));

        const response = await page.goto(PAGE);
        expect(response?.status()).toBe(200);

        for (const label of [PAYMENT, HOOK, SHARED]) {
            await expect(page.getByRole('heading', { name: label, exact: true })).toBeVisible();
        }

        await expect(lineOf(page, PAYMENT, 'live')).toContainText('Begins fx_live_. 32 to 255 characters.');
        await expect(lineOf(page, HOOK, 'test')).toContainText('the line you paste into decides');
        await expect(page.locator('.fi-badge', { hasText: /^\s*(Test|Live) mode\s*$/ }).first()).toBeVisible();
    });

    test('takes a value through one POST, and nothing the admin sends back holds any of it', async ({ page }) => {
        await page.goto(PAGE);
        const seen = capture(page);
        const v = value('fx_test_');

        const modal = await openSet(page, PAYMENT, 'test');
        await expect(modal.locator('.fi-modal-heading')).toContainText(`${PAYMENT} (test mode)`);
        await modal.locator('input[name="password"]').fill(v);

        // A double click sends one POST: the first disables the button.
        await Promise.all([page.waitForURL(PAGE), modal.getByRole('button', { name: 'Save', exact: true }).dblclick()]);

        await expect(page.locator('.fi-no-notification').filter({ hasText: `Saved: ${PAYMENT} (test mode)` })).toBeVisible();
        await expect(lineOf(page, PAYMENT, 'test').locator('.fi-badge').first()).toHaveText('Set');
        await expect(lineOf(page, PAYMENT, 'test')).toContainText('Alpha User');

        await page.reload();

        expect(carriers(seen, v)).toEqual([`POST ${SET}`]);
        await expectClean(page, seen, v);

        // Replaced, then removed: still nothing of either value anywhere.
        const v2 = value('fx_test_');
        await setValue(page, PAYMENT, 'test', v2);
        await expect(page.locator('.fi-no-notification').filter({ hasText: `Replaced: ${PAYMENT} (test mode)` })).toBeVisible();

        await page.getByRole('button', { name: `Remove ${PAYMENT} (test mode)`, exact: true }).click();
        await page.locator('.fi-modal-window').getByRole('button', { name: 'Remove', exact: true }).click();
        await expect(lineOf(page, PAYMENT, 'test').locator('.fi-badge').first()).toHaveText('Removed');
        await expect(lineOf(page, PAYMENT, 'test')).toContainText('Alpha User');

        expect(carriers(seen, v2)).toEqual([`POST ${SET}`]);
        await expectClean(page, seen, v);
        await expectClean(page, seen, v2);

        // The answer itself, read without following it: a 303 to the page, holding nothing.
        const v3 = value();
        const answer = await page.request.post(SET, {
            form: { _token: String(await csrf(page)), slot: 'e2e.shared-secret', password: v3 },
            maxRedirects: 0,
        });

        expect(answer.status()).toBe(303);
        expect(answer.headers().location).toMatch(new RegExp(`${PAGE}$`));
        expect((await answer.text()).includes(v3)).toBe(false);
    });

    test('sends nothing of a value typed and then cancelled', async ({ page }) => {
        await page.goto(PAGE);
        const seen = capture(page);
        const typed = value();
        const escaped = value();

        let modal = await openSet(page, SHARED, null);
        await modal.locator('input[name="password"]').fill(typed);
        await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(modal).toBeHidden();

        modal = await openSet(page, SHARED, null);
        await modal.locator('input[name="password"]').fill(escaped);
        await page.keyboard.press('Escape');
        await expect(modal).toBeHidden();

        // A Livewire round trip after both, so a value left in the page's state would travel now.
        await openSet(page, PAYMENT, 'live');
        await page.keyboard.press('Escape');

        expect(carriers(seen, typed)).toEqual([]);
        expect(carriers(seen, escaped)).toEqual([]);
        await expectClean(page, seen, typed);
        await expectClean(page, seen, escaped);
    });

    test('refuses a test-mode key for a live-mode org, in its own words (ADR-040)', async ({ page }) => {
        await page.goto(PAGE);
        await switchTo(page, 'test');

        try {
            await page.getByRole('button', { name: 'Switch to live mode', exact: true }).click();
            const confirm = page.locator('.fi-modal-window').filter({ hasText: 'Switch' });
            await expect(confirm).toContainText('These have no usable live value yet');
            await expect(confirm).toContainText(HOOK);
            await confirm.getByRole('button', { name: 'Switch to live mode', exact: true }).click();

            await expect(page.locator('.fi-badge', { hasText: /^\s*Live mode\s*$/ })).toBeVisible();
            await expect(lineOf(page, PAYMENT, 'live')).toContainText('In use');
            await expect(lineOf(page, PAYMENT, 'test')).not.toContainText('In use');

            // Live mode's badge is the danger colour, which the page shows in no other state.
            const live = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
            expect(live.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''))
                .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ') + ' ' + node.failureSummary).join(' | ')}`)).toEqual([]);

            const seen = capture(page);
            const before = await lineOf(page, PAYMENT, 'live').locator('.fi-badge').first().innerText();
            const wrong = value('fx_test_');
            await setValue(page, PAYMENT, 'live', wrong);

            await expect(page.locator('.fi-no-notification').filter({ hasText: 'was not saved for live mode: it is a test-mode key (it begins fx_test_)' })).toBeVisible();
            await expect(lineOf(page, PAYMENT, 'live').locator('.fi-badge').first()).toHaveText(before);
            await expectClean(page, seen, wrong);
        } finally {
            await page.goto(PAGE);
            await switchTo(page, 'test');
        }

        await expect(page.locator('.fi-badge', { hasText: /^\s*Test mode\s*$/ })).toBeVisible();
    });

    test('is the org\'s at every one of its sites, and nobody else\'s', async ({ page, browser }) => {
        await page.goto(PAGE);
        const v = value();
        await setValue(page, SHARED, null, v);

        // Another site of the same org: the same line. Status and who only — that site's chrome and dates may be French.
        await page.goto(`/admin/golfdom-fr/credentials`);
        await expect(lineOf(page, SHARED, null).locator('.fi-badge').first()).toHaveText('Set');
        await expect(lineOf(page, SHARED, null)).toContainText('Alpha User');

        // Another org, from the side of the one crossing.
        await page.goto(PAGE);
        const token = String(await csrf(page));
        expect((await page.request.get('/admin/rival-golfdom/credentials')).status()).toBe(404);
        const crossed = await page.request.post('/admin/rival-golfdom/credentials/set', {
            form: { _token: token, slot: 'e2e.payment-key', mode: 'test', password: value('fx_test_') },
            maxRedirects: 0,
        });
        expect(crossed.status()).toBe(404);

        const rival = await browser.newContext({ storageState: '.playwright/admin-rival-auth.json' });

        try {
            const rivalPage = await rival.newPage();
            const own = await rivalPage.goto('/admin/rival-golfdom/credentials');
            expect(own?.status()).toBe(200);
            await expect(lineOf(rivalPage, PAYMENT, 'test').locator('.fi-badge').first()).toHaveText(/Not set|Removed|Set/);
            await expect(rivalPage.locator('body')).not.toContainText('Alpha User');

            const reached = await rivalPage.request.post(SET, {
                form: { _token: String(await csrf(rivalPage)), slot: 'e2e.shared-secret', password: value() },
                maxRedirects: 0,
            });
            expect(reached.status()).toBe(404);
        } finally {
            await rival.close();
        }

        await page.goto(PAGE);
        await expect(lineOf(page, SHARED, null).locator('.fi-badge').first()).toHaveText('Set');
    });

    test('lets nobody signed in in, and takes nothing from them', async ({ page, browser }) => {
        await page.goto(PAGE);
        const before = await lineOf(page, SHARED, null).innerText();

        // An empty state explicitly: a context made here otherwise takes the project's signed-in one.
        const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });

        try {
            const anonPage = await anonymous.newPage();
            await anonPage.goto(PAGE);
            await expect(anonPage).toHaveURL(/\/admin\/login$/);

            const v = value();
            const posted = await anonymous.request.post(SET, { form: { slot: 'e2e.shared-secret', password: v }, maxRedirects: 0 });

            // Laravel asks who is signed in before it compares tokens, so this is the login redirect, not a 419.
            expect([302, 419]).toContain(posted.status());
            expect(posted.headers().location || '').not.toMatch(/credentials/);
            expect((await posted.text()).includes(v)).toBe(false);
        } finally {
            await anonymous.close();
        }

        await page.goto(PAGE);
        expect(await lineOf(page, SHARED, null).innerText()).toBe(before);
    });

    test('files a value under the line it was pasted into, where nothing in it says which', async ({ page }) => {
        await page.goto(PAGE);
        const testBefore = await lineOf(page, HOOK, 'test').locator('.fi-badge').first().innerText();

        let modal = await openSet(page, HOOK, 'test');
        await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(modal).toBeHidden();

        modal = await openSet(page, HOOK, 'live');
        await expect(modal.locator('.fi-modal-heading')).toContainText(`${HOOK} (live mode)`);
        await expect(modal.locator('input[name="mode"]')).toHaveValue('live');

        await modal.locator('input[name="password"]').fill(value('fxhook_'));
        await Promise.all([page.waitForURL(PAGE), modal.getByRole('button', { name: 'Save', exact: true }).click()]);

        await expect(lineOf(page, HOOK, 'live').locator('.fi-badge').first()).toHaveText('Set');
        await expect(lineOf(page, HOOK, 'test').locator('.fi-badge').first()).toHaveText(testBefore);
    });

    test('can be used from the keyboard alone, and its form has no serious WCAG violations', async ({ page }) => {
        await page.goto(PAGE);

        const name = await page.getByRole('button', { name: `Set ${SHARED}`, exact: true }).count() ? `Set ${SHARED}` : `Replace ${SHARED}`;
        await page.getByRole('button', { name, exact: true }).focus();
        await page.keyboard.press('Enter');

        const field = page.locator('.fi-modal-window input[name="password"]');
        await expect(field).toBeVisible();
        await expect(field).toBeFocused();

        // Scanned once the modal has finished fading in: text caught mid-transition reads as low contrast.
        await expect(page.locator('.fi-modal-window').filter({ has: page.locator('input[name="password"]') })).toHaveCSS('opacity', '1');
        await page.evaluate(() => Promise.all(document.getAnimations().map((animation) => animation.finished)));

        const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        const blocking = results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''));
        expect(blocking.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ') + ' ' + node.failureSummary).join(' | ')}`)).toEqual([]);

        await page.keyboard.type(value());
        await Promise.all([page.waitForURL(PAGE), page.keyboard.press('Enter')]);

        await expect(page.locator('.fi-no-notification').filter({ hasText: SHARED })).toBeVisible();
    });
});
