// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { seed, clear, stock, facts, privacyFacts, prepare } = require('./free-order-fixture');
test.beforeEach(seed);
test.afterEach(clear);

test('completes a guest free order by native POST, exports its own fact and never opens Stripe', async ({ page }) => {
    let providerRequests = 0;
    page.on('request', request => { if (/stripe\.com/.test(request.url())) providerRequests++; });
    const path = await prepare(page);
    await expect(page.getByRole('button', { name: 'Complete free order', exact: true })).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    const posted = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === `${path}/payment/complete-free`);
    await page.getByRole('button', { name: 'Complete free order', exact: true }).focus();
    await page.keyboard.press('Enter');
    expect((await posted).status()).toBe(303);
    await expect(page.locator('[data-free-completion-summary]')).toContainText('Completed free of charge');
    await page.reload();
    expect(facts()).toMatchObject({ completions: 1, payments: 0, attempts: 0, stock: '4', order: { state: 'completed_free' } });
    expect(providerRequests).toBe(0);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.goto(path);
    await expect(page.getByRole('link', { name: 'Save this order to your account', exact: true })).toBeVisible();
    const downloaded = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download my order data', exact: true }).click();
    const file = await (await downloaded).path();
    const body = require('node:fs').readFileSync(file, 'utf8');
    expect(body).toContain('free_completion');
    expect(body).toContain('completed_free');
    await page.getByRole('link', { name: 'Save this order to your account', exact: true }).click();
    await expect(page.getByText('Any fixed-term access runs from free completion. Saving this order to your account does not restart or extend it.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sign in to save this order', exact: true })).toBeVisible();
});

test('records unavailable free units and enforces body-only CSRF', async ({ page }) => {
    const path = await prepare(page);
    const review = await page.locator('input[name="review"]').inputValue();
    const rejected = await page.request.post(`${path}/payment/complete-free`, { form: { review } });
    expect(rejected.status()).toBe(419);
    expect(facts().completions).toBe(0);
    stock(0);
    await page.getByRole('button', { name: 'Complete free order', exact: true }).click();
    await expect(page.locator('[data-free-completion-summary]')).toContainText('Unavailable units: 1');
    expect(facts()).toMatchObject({ completions: 1, payments: 0, allocation: { awarded: 0, unavailable: 1 } });
});

test('minimizes a completed free order through the anonymous guest HTTP page', async ({ page }) => {
    expect(await page.context().cookies()).toEqual([]);
    const offHostRequests = [];
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname === '127.0.0.1' || url.protocol === 'data:') return route.continue();
        offHostRequests.push(url.origin);
        return route.abort();
    });
    const path = await prepare(page);
    await page.getByRole('button', { name: 'Complete free order', exact: true }).click();
    await expect(page.locator('[data-free-completion-summary]')).toBeVisible();
    await page.goto(path);
    const before = privacyFacts();
    expect(before.subject_erased).toBe(false);
    const beforeStock = facts().stock;
    expect((await page.request.post(`${path}/erase`, { form: {} })).status()).toBe(419);
    expect(privacyFacts()).toEqual(before);
    const stranger = await page.context().browser().newContext();
    try {
        const otherPage = await stranger.newPage();
        expect((await otherPage.goto(page.url())).status()).toBe(404);
        expect(privacyFacts()).toEqual(before);
    } finally {
        await stranger.close();
    }
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === `${path}/erase`);
    await page.getByRole('button', { name: 'Erase my guest access and non-required order details', exact: true }).click();
    expect((await response).status()).toBe(303);
    const after = privacyFacts();
    expect(after).toMatchObject({
        state: 'completed_free', contact_removed: true, delivery_removed: true, reader_id: null,
        billing: { name: 'Free Browser Buyer' }, subject_erased: true, claims: 0, tokens: 0, access_state: 'suppressed',
        completion_id: before.completion_id, completed_at: before.completed_at,
        policy_hash: before.policy_hash, retained_until: before.retained_until,
    });
    expect(facts()).toMatchObject({ completions: 1, payments: 0, attempts: 0, stock: beforeStock });
    expect((await page.goto(path)).status()).toBe(404);
    expect(offHostRequests).toEqual([]);
});
