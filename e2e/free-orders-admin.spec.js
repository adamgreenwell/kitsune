// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { SITE, seed, clear, facts, prepare } = require('./free-order-fixture');
test.beforeEach(seed);
test.afterEach(clear);

test('corrects original free units with a reason and confirmation while preserving stock and dashboard navigation', async ({ page }) => {
    await prepare(page);
    await page.getByRole('button', { name: 'Complete free order', exact: true }).click();
    await expect(page.locator('[data-free-completion-summary]')).toBeVisible();
    const reference = facts().order.reference;
    await page.goto(`/admin/${SITE}/storefront/orders/${reference}`);
    await expect(page.getByText('Correct access from this free order', { exact: true })).toBeVisible();
    await page.waitForFunction(() => Boolean(document.querySelector('#free-access-reason')?._x_model));
    await page.getByRole('checkbox', { name: 'FREE-BROWSER — Unit 1', exact: true }).check();
    await page.getByLabel('Reason for this access correction', { exact: true }).fill('Withdraw this original free unit.');
    await page.getByRole('checkbox', { name: 'I confirm this correction to the selected original units.', exact: true }).check();
    await page.getByRole('button', { name: 'Remove selected access contributions', exact: true }).click();
    await expect(page.getByText('Free-order access correction saved and checked', { exact: true })).toBeVisible();
    expect(facts()).toMatchObject({ stock: '4', corrections: 1, payments: 0, attempts: 0 });
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.goto(`/admin/${SITE}`);
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
});
