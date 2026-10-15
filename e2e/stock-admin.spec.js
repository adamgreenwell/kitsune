// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { callsMethod } = require('./select-control');
const { SITE_A, SITE_B, RIVAL_SITE, atSite, php, literal, seedProduct, resetStorefrontProducts, storefrontState } = require('./storefront-fixture');

const stock = (site = SITE_A, reference = '') => `/admin/${site}/storefront/stock${reference ? `/${reference}` : ''}`;
const products = (site = SITE_A) => `/admin/${site}/storefront/products`;
const editorState = path.join(__dirname, '..', '.playwright/admin-reader-auth.json');
const rivalState = path.join(__dirname, '..', '.playwright/admin-rival-auth.json');

async function adjust(page, quantity, reason) {
    await page.getByRole('combobox', { name: 'Stock tracking', exact: true }).selectOption('finite');
    await page.getByRole('textbox', { name: 'New stock quantity', exact: true }).fill(quantity);
    await page.getByRole('textbox', { name: 'Reason for adjustment', exact: true }).fill(reason);
    const response = page.waitForResponse((candidate) => callsMethod(candidate, 'adjustStock'));
    await page.getByRole('button', { name: 'Save adjustment', exact: true }).click();
    await response;
}

function movements(reference) {
    return JSON.parse(php(atSite() + `echo json_encode(DB::table('commerce_stock_movements')->where('org_id',$site->org_id)->where('sellable_ref',${literal(reference)})->orderBy('id')->get());`));
}

test.describe('shared stock administration', () => {
    test.beforeEach(() => resetStorefrontProducts());
    test.afterEach(() => resetStorefrontProducts());

    test('loads navigation outside content routes and preserves current stock on an already loaded product form', async ({ page }) => {
        const id = seedProduct();
        for (const url of [`/admin/${SITE_A}`, `/admin/${SITE_A}/roles`]) {
            expect((await page.goto(url)).status()).toBe(200);
            await expect(page.locator(`.fi-sidebar a[href$="${stock()}"]`)).toBeVisible();
        }
        await page.goto(stock(SITE_A, id));
        await adjust(page, '5', 'Opening finite stock count');
        await expect(page.locator('[data-stock-balance]')).toHaveText('5');
        await page.goto(`${products()}/${id}/edit`);
        await expect(page.getByRole('combobox', { name: /^Stock tracking/ })).toBeDisabled();
        await expect(page.getByRole('textbox', { name: /^Stock quantity/ })).toBeDisabled();
        await expect(page.locator('#fi-main-content').getByRole('link', { name: 'Stock and history', exact: true })).toHaveAttribute('href', new RegExp(`${stock(SITE_A, id)}$`));

        php(atSite() + `app(Kitsune\\Commerce\\Inventory\\StockWriter::class)->adjust(${literal(id)},'5','2','Another committed stock count');`);
        await page.getByRole('textbox', { name: /^Title/ }).fill('Updated product content');
        const saved = page.waitForResponse((candidate) => callsMethod(candidate, 'save'));
        await page.getByRole('button', { name: 'Save changes', exact: true }).click();
        await saved;
        await expect(page.getByRole('textbox', { name: /^Stock quantity/ })).toHaveValue('2');
        expect(storefrontState().sellables[0].stock).toBe('2');
        expect(movements(id)).toHaveLength(2);
    });

    test('lets product editors adjust stock and read history while keeping financial administration owner-only', async ({ browser, page }) => {
        const id = seedProduct();
        const entry = storefrontState().entries[0];
        // Give the second brand its own listing while preserving shared price and stock.
        php(atSite(SITE_B) + `app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->updateProduct(${literal(id)},['sku'=>'E2E-COURSE','price_minor'=>'2000','stock'=>null],'USD',['title'=>'Second brand course','slug'=>'second-brand-course','status'=>'draft','values'=>[]]);`);
        const editor = await browser.newContext({ storageState: editorState });
        try {
            const editing = await editor.newPage();
            expect((await editing.goto(`/admin/${SITE_A}`)).status()).toBe(200);
            await expect(editing.locator(`.fi-sidebar a[href$="${stock()}"]`)).toBeVisible();
            await expect(editing.locator(`.fi-sidebar a[href$="${products()}"]`)).toHaveCount(0);
            expect((await editing.goto(`/admin/${SITE_A}/c/product/${entry.id}/edit`)).status()).toBe(200);
            for (const leaf of ['products', 'currency', 'checkout', 'orders']) {
                expect((await editing.goto(`/admin/${SITE_A}/storefront/${leaf}`)).status()).toBe(403);
            }
            for (const leaf of ['credentials', 'entitlements']) {
                expect((await editing.goto(`/admin/${SITE_A}/${leaf}`)).status()).toBe(403);
            }
            await editing.goto(stock(SITE_A, id));
            await adjust(editing, '7', 'Counted <7> available units');
            await expect(editing.locator('[data-stock-balance]')).toHaveText('7');
            await expect(editing.getByRole('cell', { name: 'Counted <7> available units', exact: true })).toBeVisible();
            await expect(editing.getByRole('textbox', { name: 'Reason for adjustment', exact: true })).toHaveValue('');
            await expect(editing.getByText('Price (USD)', { exact: true })).toHaveCount(0);
            expect(movements(id)).toHaveLength(1);
            await page.goto(stock(SITE_B, id));
            await expect(page.locator('[data-stock-balance]')).toHaveText('7');
            await expect(page.getByRole('cell', { name: 'Counted <7> available units', exact: true })).toBeVisible();
        } finally {
            await editor.close();
        }
    });

    test('requires a reason and refuses an old balance until it is explicitly reloaded', async ({ browser, page }) => {
        const id = seedProduct();
        await page.goto(stock(SITE_A, id));
        await adjust(page, '5', 'Initial stock count');
        const editor = await browser.newContext({ storageState: editorState });
        try {
            const editing = await editor.newPage();
            await editing.goto(stock(SITE_A, id));
            await expect(editing.getByRole('textbox', { name: 'Reason for adjustment', exact: true })).toHaveJSProperty('required', true);
            await adjust(page, '4', 'One unit damaged');
            await adjust(editing, '9', 'Incoming stock on stale form');
            await expect(editing.getByText(/^Stock could not be adjusted\./)).toBeVisible();
            expect(storefrontState().sellables[0].stock).toBe('4');
            expect(movements(id)).toHaveLength(2);
            await editing.getByRole('button', { name: 'Reload current balance', exact: true }).click();
            await expect(editing.getByRole('textbox', { name: 'New stock quantity', exact: true })).toHaveValue('4');
            await expect(editing.getByRole('textbox', { name: 'Reason for adjustment', exact: true })).toHaveValue('');
            await adjust(editing, '9', 'Incoming stock after recount');
            await expect(editing.locator('[data-stock-balance]')).toHaveText('9');
            expect(movements(id)).toHaveLength(3);
        } finally {
            await editor.close();
        }
    });

    test('refuses foreign org and unlisted sibling-site product references for editors', async ({ browser, page }) => {
        const id = seedProduct();
        const sibling = seedProduct('SIBLING-ONLY', SITE_B);
        const editor = await browser.newContext({ storageState: editorState });
        const rival = await browser.newContext({ storageState: rivalState });
        try {
            const editing = await editor.newPage();
            expect((await editing.goto(stock())).status()).toBe(200);
            await expect(editing.getByText('E2E-COURSE', { exact: true })).toBeVisible();
            await expect(editing.getByText('SIBLING-ONLY', { exact: true })).toHaveCount(0);
            expect((await editing.goto(stock(SITE_A, sibling))).status()).toBe(404);
            expect((await editing.goto(stock(SITE_B, sibling))).status()).toBe(200);
            const outsider = await rival.newPage();
            expect((await outsider.goto(stock(RIVAL_SITE, id))).status()).toBe(404);
            for (const malformed of ['0', '01', '1e2', '9223372036854775808']) {
                expect((await page.goto(stock(SITE_A, malformed))).status()).toBe(404);
            }
            expect(movements(id)).toEqual([]);
        } finally {
            await editor.close();
            await rival.close();
        }
    });
});
