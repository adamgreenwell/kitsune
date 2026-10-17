// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const { callsMethod } = require('./select-control');
const { SITE_A, SITE_B, atSite, php, literal, resetStorefrontProducts, storefrontState } = require('./storefront-fixture');

const products = (site = SITE_A) => `/admin/${site}/storefront/products`;
const edit = (id, site = SITE_A) => `${products(site)}/${id}/edit`;
const mapping = (page) => page.getByRole('textbox', { name: 'Access entitlement', exact: true });
const kind = (page) => page.getByRole('combobox', { name: /^Purchased access/ });
const days = (page) => page.getByRole('textbox', { name: /^Access days/ });
const title = (page) => page.getByRole('textbox', { name: /^Title\s*\*?$/ });

/** Livewire binds native text controls through Alpine's x-model; a visible unbound form is not ready for typing. */
async function ready(page) {
    const sku = page.getByRole('textbox', { name: /^SKU\s*\*?$/ });
    await expect(sku).toBeVisible();
    await expect.poll(() => sku.evaluate((input) => typeof /** @type {any} */ (input)._x_model?.set === 'function')).toBe(true);
}

async function setMapping(page, name) {
    await mapping(page).fill(name);
    await mapping(page).press('Tab');
    if (name !== '') await expect(kind(page)).toBeVisible();
}

async function chooseFixed(page, count) {
    await kind(page).selectOption('fixed');
    await expect(days(page)).toBeVisible();
    await expect.poll(() => days(page).evaluate((input) => typeof /** @type {any} */ (input)._x_model?.set === 'function')).toBe(true);
    await days(page).fill(count);
}

async function save(page, success = true) {
    const response = page.waitForResponse((candidate) => callsMethod(candidate, 'save'));
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await response).status()).toBe(200);
    if (success) await expect(page.locator('.fi-no-notification').getByText('Saved', { exact: true })).toBeVisible();
}

function storedTerms() {
    const row = storefrontState().sellables[0];
    return row.offer_terms === null ? null : JSON.parse(row.offer_terms);
}

/**
 * Only unpaid orders created by this spec; never erase financial custody to make a fixture reset succeed.
 * Org-level financial signals have no order ID and remain untouched by this order-specific cleanup.
 */
function cleanup() {
    php(atSite() + "$ids=DB::table('commerce_order_lines')->where('org_id',$site->org_id)->where('sku','like','ACCESS-OFFER-%')->pluck('order_id')->unique()->all();"
        + "if(DB::table('commerce_orders')->where('org_id',$site->org_id)->whereIn('id',$ids)->whereNotIn('state',['prepared','cancelled'])->exists()){throw new RuntimeException('Refusing financial fixture deletion.');}"
        + "foreach(['commerce_privacy_holds','commerce_privacy_deliveries','commerce_privacy_transitions','commerce_order_privacy','commerce_access_adjustment_effects','commerce_access_adjustment_work','commerce_financial_observations','commerce_financial_refunds','commerce_financial_work','commerce_access_effects','commerce_access_work','commerce_order_claims'] as $table){DB::table($table)->where('org_id',$site->org_id)->whereIn('order_id',$ids)->delete();}"
        + "DB::table('commerce_orders')->where('org_id',$site->org_id)->whereIn('id',$ids)->delete();"
        + "DB::table('commerce_carts')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_sales_policies')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_tax_rates')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_settings')->where('org_id',$site->org_id)->delete();");
    resetStorefrontProducts();
}

test.describe('shared access offer administration', () => {
    test.beforeEach(cleanup);
    test.afterEach(cleanup);

    test('creates whole-day terms, shares them across brands, then chooses permanent access and clears the mapping', async ({ page }) => {
        for (const url of [`/admin/${SITE_A}`, `/admin/${SITE_A}/roles`]) {
            expect((await page.goto(url)).status()).toBe(200);
            await expect(page.locator(`.fi-sidebar a[href$="${products()}"]`)).toBeVisible();
        }
        await page.goto(`${products()}/create`);
        await ready(page);
        await page.getByRole('textbox', { name: /^SKU\s*\*?$/ }).fill('ACCESS-OFFER-CREATED');
        await page.getByRole('textbox', { name: /^Price \(USD\)/ }).fill('20.00');
        await title(page).fill('Coastal access course');
        await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('coastal-access-course');
        await setMapping(page, 'course.access-offer');
        await chooseFixed(page, '45');
        const created = page.waitForResponse((candidate) => callsMethod(candidate, 'create'));
        await page.locator('form').getByRole('button', { name: 'Create', exact: true }).first().click();
        expect((await created).status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`${products()}/[1-9][0-9]*/edit$`));
        const id = new URL(page.url()).pathname.match(/\/([1-9][0-9]*)\/edit$/)[1];
        expect(storedTerms()).toEqual({ version: 1, kind: 'fixed', days: 45 });

        await page.goto(edit(id, SITE_B));
        await ready(page);
        await expect(kind(page)).toHaveValue('fixed');
        await expect(days(page)).toHaveValue('45');
        await title(page).fill('Mountain access course');
        await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('mountain-access-course');
        await chooseFixed(page, '60');
        await save(page);
        expect(storefrontState().sellables).toHaveLength(1);
        expect(storefrontState().listings).toHaveLength(2);
        expect(storedTerms()).toEqual({ version: 1, kind: 'fixed', days: 60 });

        await page.goto(edit(id));
        await ready(page);
        await expect(days(page)).toHaveValue('60');
        await expect(title(page)).toHaveValue('Coastal access course');
        await kind(page).selectOption('permanent');
        await expect(days(page)).toHaveCount(0);
        await save(page);
        expect(storedTerms()).toEqual({ version: 1, kind: 'permanent' });
        await page.goto(edit(id, SITE_B));
        await ready(page);
        await expect(kind(page)).toHaveValue('permanent');
        await setMapping(page, '');
        await expect(kind(page)).toHaveCount(0);
        await save(page);
        expect(storefrontState().sellables[0].entitlement_name).toBeNull();
        expect(storedTerms()).toBeNull();
    });

    test('requires explicit terms for old mapped products and keeps the buyer’s prepared duration frozen', async ({ page, browser }) => {
        const id = php(atSite() + "echo app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->createProduct(['sku'=>'ACCESS-OFFER-LEGACY','price_minor'=>'2000','stock'=>null,'entitlement_name'=>'course.legacy-offer'],'USD',"
            + "['title'=>'Legacy access course','slug'=>'legacy-access-course','status'=>'published','values'=>[]],[],true)->id;");
        php(atSite() + '$setup=app(Kitsune\\Commerce\\Checkout\\CommerceSetupWriter::class);'
            + "$setup->setSettings(['tax_mode'=>'exclusive','shipping_tax_mode'=>'exclusive','shipping_tax_class'=>'shipping']);"
            + "$setup->saveTaxRate(null,['country'=>'US','region'=>'*','tax_class'=>'standard','rate_parts'=>200000]);"
            + `$setup->setSalesPolicy(${literal(id)},['tax_class'=>'standard','delivery_kind'=>'digital']);`);
        const guest = await browser.newContext();
        try {
            const buyer = await guest.newPage();
            await buyer.goto(`/${SITE_A}/catalogue`);
            const course = buyer.getByRole('article').filter({ has: buyer.getByRole('heading', { name: 'Legacy access course', exact: true }) });
            await expect(course).toContainText('Access offer unavailable');
            await expect(course.getByRole('button', { name: 'Add to cart', exact: true })).toBeDisabled();

            await page.goto(edit(id));
            await ready(page);
            await expect(kind(page)).toHaveValue('');
            await expect(kind(page)).toHaveJSProperty('required', true);
            expect(storedTerms()).toBeNull();
            await chooseFixed(page, '30.0');
            await save(page, false);
            await expect(page.getByText('Enter a whole number of days from 1 to 36,500.', { exact: true })).toBeVisible();
            expect(storedTerms()).toBeNull();
            await days(page).fill('30');
            await save(page);
            expect(storedTerms()).toEqual({ version: 1, kind: 'fixed', days: 30 });

            await buyer.reload();
            await expect(course).toContainText('30 days from order confirmation');
            await course.getByRole('button', { name: 'Add to cart', exact: true }).click();
            await expect(buyer).toHaveURL(new RegExp(`/${SITE_A}/cart$`));
            await expect(buyer.getByText('30 days from order confirmation', { exact: true })).toBeVisible();
            await buyer.getByRole('link', { name: 'Prepare an order', exact: true }).click();
            await buyer.getByLabel('Email for order access', { exact: true }).fill('access-offer-buyer@kitsune.test');
            const billing = buyer.getByRole('group', { name: 'Billing details', exact: true });
            for (const [label, value] of [['Name', 'Access Buyer'], ['Address line 1', '1 Saved Street'], ['City', 'Honolulu'],
                ['State or region code', 'HI'], ['Postal code', '96801'], ['Country code (two uppercase letters)', 'US']]) {
                await billing.getByLabel(label, { exact: true }).fill(value);
            }
            await buyer.getByRole('button', { name: 'Review tax and delivery', exact: true }).click();
            await expect(buyer.getByRole('heading', { name: 'Review your order', exact: true })).toBeVisible();
            await expect(buyer.getByText('30 days from order confirmation', { exact: true })).toBeVisible();
            await buyer.getByRole('button', { name: 'Prepare order', exact: true }).click();
            await expect(buyer).toHaveURL(new RegExp(`/${SITE_A}/cart/orders/[a-f0-9]{32}$`));

            await kind(page).selectOption('permanent');
            await expect(days(page)).toHaveCount(0);
            await save(page);
            await buyer.reload();
            await expect(buyer.getByText('30 days from order confirmation', { exact: true })).toBeVisible();
            await expect(buyer.getByText('Permanent access', { exact: true })).toHaveCount(0);
            const catalogue = await guest.newPage();
            await catalogue.goto(`/${SITE_A}/catalogue`);
            await expect(catalogue.getByRole('article')).toContainText('Permanent access');
        } finally {
            await guest.close();
        }
    });
});
