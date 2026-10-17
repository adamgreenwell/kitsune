// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;
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
    test('owners browse removed-product history with exact filters and keyboard accessible mobile controls', async ({ page }) => {
        const id = seedProduct('E2E-REMOVED-HISTORY');
        await page.goto(stock(SITE_A, id));
        await adjust(page, '7', 'Counted <7> retained units');
        // Checked product removal preserves this immutable stock movement.
        php(atSite() + `auth()->forgetUser();$writer=app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class);foreach(Kitsune\\Commerce\\Models\\Listing::query()->where('sellable_id',${literal(id)})->get() as $listing){$writer->removeListing($listing->id);}$writer->removeSellable(${literal(id)});`);
        const before = movements(id);
        for (const url of [`/admin/${SITE_A}`, `/admin/${SITE_A}/roles`]) {
            expect((await page.goto(url)).status()).toBe(200);
            await expect(page.locator(`.fi-sidebar a[href$="${stock(SITE_A, 'history')}"]`)).toBeVisible();
        }
        expect((await page.goto(stock(SITE_A, 'history'))).status()).toBe(200);
        await page.getByRole('textbox', { name: 'Exact historical SKU', exact: true }).fill('E2E-REMOVED-HISTORY');
        await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
        const table = page.locator('[data-retained-stock-history]');
        await expect(table.getByRole('cell', { name: 'Counted <7> retained units', exact: true })).toBeVisible();
        await expect(table.getByRole('cell', { name: id, exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save adjustment', exact: true })).toHaveCount(0);
        expect(storefrontState().sellables).toEqual([]);
        expect(movements(id)).toEqual(before);
        await page.getByRole('textbox', { name: 'Historical product reference', exact: true }).fill('9223372036854775807');
        await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
        await expect(table).toContainText('No retained stock movements match');
        await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
        await expect(page.getByRole('textbox', { name: 'Exact historical SKU', exact: true })).toHaveValue('');
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        const sku = page.getByRole('textbox', { name: 'Exact historical SKU', exact: true });
        await sku.focus();
        await page.keyboard.type('E2E-REMOVED-HISTORY');
        await page.keyboard.press('Tab');
        await expect(page.getByRole('textbox', { name: 'Historical product reference', exact: true })).toBeFocused();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(movements(id)).toEqual(before);
    });

    test('retained history refuses editors and never resolves planted foreign product references', async ({ browser, page }) => {
        const id = seedProduct('E2E-HISTORY-LOCAL');
        const foreign = seedProduct('E2E-HISTORY-FOREIGN', RIVAL_SITE, 'rival@kitsune.test');
        php(atSite() + `app(Kitsune\\Commerce\\Inventory\\StockWriter::class)->adjust(${literal(id)},null,'2','Local history only');DB::table('commerce_stock_movements')->where('org_id',$site->org_id)->where('sellable_ref',${literal(id)})->update(['sellable_ref'=>${literal(foreign)}]);`);
        const editor = await browser.newContext({ storageState: editorState });
        const rival = await browser.newContext({ storageState: rivalState });
        try {
            const editing = await editor.newPage();
            expect((await editing.goto(`/admin/${SITE_A}`)).status()).toBe(200);
            await expect(editing.locator(`.fi-sidebar a[href$="${stock(SITE_A, 'history')}"]`)).toHaveCount(0);
            expect((await editing.goto(stock(SITE_A, 'history'))).status()).toBe(403);
            const outsider = await rival.newPage();
            expect((await outsider.goto(stock(RIVAL_SITE, 'history'))).status()).toBe(200);
            await outsider.getByRole('textbox', { name: 'Historical product reference', exact: true }).fill(foreign);
            await outsider.getByRole('button', { name: 'Apply filters', exact: true }).click();
            await expect(outsider.locator('[data-retained-stock-history]')).not.toContainText('Local history only');
            await page.goto(stock(SITE_A, 'history'));
            await page.getByRole('textbox', { name: 'Historical product reference', exact: true }).fill(foreign);
            await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
            await expect(page.locator('[data-retained-stock-history]')).toContainText('E2E-HISTORY-LOCAL');
            await expect(page.locator('[data-retained-stock-history]')).not.toContainText('E2E-HISTORY-FOREIGN');
        } finally {
            await editor.close();
            await rival.close();
        }
    });

    test('pages retained stock history and applies an inclusive UTC day without changing the ledger', async ({ page }) => {
        const id = seedProduct('E2E-HISTORY-PAGES');
        php(atSite() + `app(Kitsune\\Commerce\\Inventory\\StockWriter::class)->adjust(${literal(id)},null,'2','Historical page fixture');$row=(array)DB::table('commerce_stock_movements')->where('org_id',$site->org_id)->where('sellable_ref',${literal(id)})->first();unset($row['id']);foreach(range(1,27) as $i){DB::table('commerce_stock_movements')->insert(array_replace($row,['reference'=>bin2hex(random_bytes(16)),'created_at'=>'2026-10-16 23:59:59','reason'=>'Historical day row '.$i]));}`);
        const before = movements(id);
        await page.goto(stock(SITE_A, 'history'));
        await page.getByRole('textbox', { name: 'Exact historical SKU', exact: true }).fill('E2E-HISTORY-PAGES');
        await page.getByLabel('From date (UTC)', { exact: true }).fill('2026-10-16');
        await page.getByLabel('Through date (UTC)', { exact: true }).fill('2026-10-16');
        await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
        const table = page.locator('[data-retained-stock-history]');
        await expect(table.locator('tbody tr')).toHaveCount(25);
        await expect(table).not.toContainText('Historical page fixture');
        await page.getByRole('button', { name: 'Next »', exact: true }).click();
        await expect(table.locator('tbody tr')).toHaveCount(2);
        await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
        await expect(table.locator('tbody tr')).toHaveCount(25);
        expect(movements(id)).toEqual(before);
    });

});
