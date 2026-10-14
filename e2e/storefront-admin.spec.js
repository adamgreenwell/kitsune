// @ts-check
const { test, expect } = require('@playwright/test');
const { selectValue, openSelect, callsMethod } = require('./select-control');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const {
    SITE_A, SITE_B, RIVAL_ORG, RIVAL_SITE, SETUP_SITE, IMAGE_A, IMAGE_B,
    artisan, seedProduct, resetStorefrontProducts, resetStorefrontSetup, setCurrency,
    storefrontState, setProductTypeEnabled,
} = require('./storefront-fixture');

const products = (site = SITE_A) => `/admin/${site}/storefront/products`;
const product = (id, site = SITE_A) => `${products(site)}/${id}/edit`;
const price = (page, currency = 'USD') => page.getByRole('textbox', { name: new RegExp(`^Price \\(${currency}\\)\\s*\\*?$`) });
const title = (page) => page.getByRole('textbox', { name: /^Title\s*\*?$/ });
const SKU = 'E2E-COURSE';

async function fillProduct(page, { sku = SKU, amount = '20.00', currency = 'USD', name = 'Brand A course' } = {}) {
    await page.getByRole('textbox', { name: /^SKU\s*\*?$/ }).fill(sku);
    await price(page, currency).fill(amount);
    await title(page).fill(name);
    await page.getByRole('textbox', { name: 'Slug', exact: true }).fill(name.toLowerCase().replace(/[^a-z0-9]+/g, '-'));
}

async function description(page, text) {
    const editor = page.locator('.tiptap').first();
    await editor.click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type(text);
}

async function chooseImage(page, name) {
    const search = await openSelect(page, /^Images/);
    await search.fill(name);
    const option = page.getByRole('option', { name }).first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click();
    await page.keyboard.press('Escape');
}

async function create(page, site = SITE_A) {
    await page.locator('form').getByRole('button', { name: 'Create', exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`${products(site)}/[1-9][0-9]*/edit$`), { timeout: 15_000 });
    return new URL(page.url()).pathname.match(/\/([1-9][0-9]*)\/edit$/)[1];
}

async function save(page) {
    await Promise.all([
        page.waitForResponse((response) => callsMethod(response, 'save')),
        page.getByRole('button', { name: 'Save changes', exact: true }).click(),
    ]);
    await expect(page.locator('.fi-no-notification').getByText('Saved', { exact: true })).toBeVisible({ timeout: 15_000 });
}

/** The response is the barrier: readback must not race the submission being tested. */
async function refusedSubmit(page, button = 'Create') {
    const response = page.waitForResponse((candidate) => callsMethod(candidate, button === 'Create' ? 'create' : 'save'));
    await page.locator('form').getByRole('button', { name: button, exact: true }).first().click();
    return response;
}

async function confirmAction(page, label) {
    await page.getByRole('button', { name: label, exact: true }).first().click();
    const dialog = page.getByRole('alertdialog').filter({ has: page.getByRole('heading', { name: label, exact: true }) });
    await expect(dialog.getByRole('heading', { name: label, exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
}

test.describe('Storefront product administration', () => {
    test.beforeEach(() => resetStorefrontProducts());
    test.afterEach(() => {
        setProductTypeEnabled(true);
        resetStorefrontProducts();
    });

    test('keeps native navigation working outside content routes and offers setup before currency selection', async ({ page }) => {
        resetStorefrontSetup();
        for (const route of [`/admin/${SITE_A}`, `/admin/${SITE_A}/roles`]) {
            const response = await page.goto(route);
            expect(response.status()).toBe(200);
            await expect(page.locator(`.fi-sidebar a[href$="${products()}"]`)).toBeVisible();
            await expect(page.locator(`.fi-sidebar a[href$="/admin/${SITE_A}/c/product"]`)).toContainText('Product content');
        }

        const response = await page.goto(products(SETUP_SITE));
        expect(response.status()).toBe(200);
        await expect(page.getByRole('link', { name: 'New Product', exact: true })).toHaveCount(0);
        await confirmAction(page, 'Set up Storefront');
        await expect(page.getByText('Storefront is set up', { exact: true })).toBeVisible();
        await page.goto(`/admin/${SETUP_SITE}/storefront/currency`);
        await expect(page.getByLabel('Org currency', { exact: true })).toHaveValue('');
        await page.getByLabel('Org currency', { exact: true }).selectOption('USD');
        await page.getByRole('button', { name: 'Save currency', exact: true }).click();
        await expect(page.getByText('Currency saved', { exact: true })).toBeVisible();
        await page.goto(`${products(SETUP_SITE)}/create`);
        await expect(price(page)).toBeVisible();
    });

    test('sells one $20 course through two brands with independent content, images and availability', async ({ page }) => {
        await page.goto(`${products()}/create`);
        await expect(page.getByText('Price and stock changes affect every site selling this product.', { exact: true })).toBeVisible();
        await expect(page.getByRole('switch', { name: 'Available on this site', exact: true })).not.toBeChecked();
        await fillProduct(page);
        await page.getByRole('combobox', { name: /^Stock tracking\s*\*?$/ }).selectOption('finite');
        await page.getByRole('textbox', { name: /^Stock quantity\s*\*?$/ }).fill('12');
        await description(page, 'Brand A teaches the course beside the sea.');
        await chooseImage(page, IMAGE_A);
        await page.locator('select[id$="status"]').selectOption('published');
        await page.getByRole('switch', { name: 'Available on this site', exact: true }).click();
        const id = await create(page);
        await page.reload();
        await expect(price(page)).toHaveValue('20.00');
        await expect(selectValue(page, /^Images/).first()).toContainText(IMAGE_A);

        await page.goto(products(SITE_B));
        const row = page.locator('.fi-ta-row').filter({ hasText: SKU });
        await expect(row).toContainText('USD 20.00');
        await expect(row).toContainText('No content on this site');
        await row.getByText(SKU, { exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${product(id, SITE_B)}$`));
        await expect(price(page)).toHaveValue('20.00');
        await expect(title(page)).toHaveValue('');
        await expect(page.getByRole('switch', { name: 'Available on this site', exact: true })).not.toBeChecked();
        await title(page).fill('Brand B masterclass');
        await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('brand-b-masterclass');
        await description(page, 'Brand B teaches the same course in the mountains.');
        await chooseImage(page, IMAGE_B);
        await save(page);
        await page.reload();
        await expect(title(page)).toHaveValue('Brand B masterclass');

        let stored = storefrontState();
        expect(stored.sellables).toHaveLength(1);
        expect(stored.sellables[0]).toMatchObject({ id, price_minor: '2000', stock: '12', currency: 'USD' });
        expect(stored.listings).toHaveLength(2);
        expect(new Set(stored.listings.map((listing) => listing.entry_id)).size).toBe(2);
        expect(stored.entries.map((entry) => entry.title)).toEqual(['Brand A course', 'Brand B masterclass']);
        expect(stored.listings.map((listing) => Boolean(listing.is_available))).toEqual([true, false]);
        expect(stored.relations.map((relation) => relation.target_entry_id)).toEqual(stored.media.map((media) => media.id));
        expect(stored.media.map((media) => media.visibility)).toEqual(['private', 'private']);

        // One browser save must add one coherent revision, containing that site's content and image choices.
        const before = stored.revisions.length;
        await price(page).fill('25.00');
        await title(page).fill('Brand B advanced masterclass');
        await save(page);
        stored = storefrontState();
        expect(stored.revisions).toHaveLength(before + 1);
        const revision = stored.revisions.at(-1);
        expect(revision.title).toBe('Brand B advanced masterclass');
        expect(Object.values(JSON.parse(revision.relation_state)).flat().map(String)).toContain(String(stored.media[1].id));
        await page.goto(product(id));
        await expect(price(page)).toHaveValue('25.00');
        await expect(title(page)).toHaveValue('Brand A course');
        await expect(page.getByRole('switch', { name: 'Available on this site', exact: true })).toBeChecked();
        await page.getByRole('link', { name: 'Content and history', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/admin/${SITE_A}/c/product/[1-9][0-9]*/edit$`));
        await expect(title(page)).toHaveValue('Brand A course');
    });

    test('keeps ordinary content grants separate from commerce authority and refuses foreign product URLs', async ({ browser, page }) => {
        const id = seedProduct();
        const original = storefrontState();
        const editor = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-reader-auth.json') });
        const rival = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-rival-auth.json') });
        try {
            const editing = await editor.newPage();
            expect((await editing.goto(`/admin/${SITE_A}`)).status()).toBe(200);
            await expect(editing.locator(`.fi-sidebar a[href$="${products()}"]`)).toHaveCount(0);
            expect((await editing.goto(`/admin/${SITE_A}/c/product/${original.entries[0].id}/edit`)).status()).toBe(200);
            await expect(title(editing)).toHaveValue('Existing course');
            for (const route of [products(), `${products()}/create`, product(id)]) {
                expect((await editing.goto(route)).status()).toBe(403);
            }

            const outsider = await rival.newPage();
            expect((await outsider.goto(products(RIVAL_SITE))).status()).toBe(200);
            expect((await outsider.goto(product(id, RIVAL_SITE))).status()).toBe(404);
            expect((await page.goto(product('0'))).status()).toBe(404);
            expect((await page.goto(product('1e2'))).status()).toBe(404);
            expect(storefrontState()).toEqual(original);
            expect(storefrontState(RIVAL_ORG).sellables).toEqual([]);
        } finally {
            await editor.close();
            await rival.close();
        }
    });

    for (const [currency, amount, minor, excess] of [['JPY', '20', '20', '20.1'], ['KWD', '20.001', '20001', '20.0001']]) {
        test(`passes exact ${currency} amounts through the native product form and refuses excess precision atomically`, async ({ page }) => {
            setCurrency(currency);
            await page.goto(`${products()}/create`);
            await fillProduct(page, { currency, amount: excess });
            await description(page, 'Content that must not be partially saved.');
            await chooseImage(page, IMAGE_A);
            const original = storefrontState();
            await refusedSubmit(page);
            await expect(page.getByText('Enter an exact nonnegative amount in the displayed currency, within its decimal precision and supported range.', { exact: true })).toBeVisible();
            expect(storefrontState()).toEqual(original);
            await price(page, currency).fill(amount);
            await create(page);
            expect(storefrontState().sellables[0]).toMatchObject({ currency, price_minor: minor });
        });
    }

    test('retains the currency shown when the form opened and refuses an old quote without partial writes', async ({ page }) => {
        await page.goto(`${products()}/create`);
        await fillProduct(page);
        await description(page, 'This USD form cannot quietly become KWD.');
        await chooseImage(page, IMAGE_A);
        setCurrency('KWD');
        const original = storefrontState();
        await refusedSubmit(page);
        await expect(page.getByText(/The product could not be saved/)).toBeVisible();
        expect(storefrontState()).toEqual(original);
        await page.reload();
        await expect(price(page, 'KWD')).toBeVisible();
        await fillProduct(page, { currency: 'KWD', amount: '20.001' });
        await create(page);
        expect(storefrontState().sellables[0]).toMatchObject({ currency: 'KWD', price_minor: '20001' });
    });

    test('refuses a stale form when its product type is disabled and leaves sibling-site editing available', async ({ page }) => {
        const id = seedProduct();
        await page.goto(product(id));
        await title(page).fill('A disabled type must keep its old title');
        const original = storefrontState();
        setProductTypeEnabled(false);
        try {
            await refusedSubmit(page, 'Save changes');
            expect(storefrontState()).toEqual(original);
            expect((await page.goto(product(id))).status()).toBe(403);
            expect((await page.goto(product(id, SITE_B))).status()).toBe(200);
        } finally {
            setProductTypeEnabled(true);
        }
    });

    test('refuses stale and cached product routes while commerce is disabled without deleting retained content', async ({ page }) => {
        const id = seedProduct();
        await page.goto(product(id));
        await title(page).fill('An old page must not bypass a disabled module');
        const original = storefrontState();
        artisan(['route:cache']);
        try {
            artisan(['kitsune:module', 'disable', 'kitsune/commerce', '--no-interaction']);
            const submitted = await refusedSubmit(page, 'Save changes');
            expect([403, 404]).toContain(submitted.status());
            expect(storefrontState()).toEqual(original);
            for (const route of [products(), `${products()}/create`, product(id)]) {
                expect([403, 404]).toContain((await page.goto(route)).status());
            }
            expect((await page.goto(`/admin/${SITE_A}`)).status()).toBe(200);
            await expect(page.locator(`.fi-sidebar a[href$="${products()}"]`)).toHaveCount(0);
            expect((await page.goto(`/admin/${SITE_A}/c/product/${original.entries[0].id}/edit`)).status()).toBe(200);
        } finally {
            artisan(['kitsune:module', 'enable', 'kitsune/commerce', '--no-interaction']);
            artisan(['route:clear']);
        }
    });

    test('removes a site listing before its shared product and keeps content and history', async ({ page }) => {
        const id = seedProduct();
        const original = storefrontState();
        await page.goto(products());
        await confirmAction(page, 'Remove product');
        await expect(page.getByText('The product could not be removed. Remove its listings on every site first.', { exact: true })).toBeVisible();
        expect(storefrontState()).toEqual(original);
        await page.goto(product(id));
        await confirmAction(page, "Remove this site's listing");
        await expect(page.getByText('Listing removed; site content was kept', { exact: true })).toBeVisible();
        expect(storefrontState().entries).toEqual(original.entries);
        expect(storefrontState().revisions).toEqual(original.revisions);
        await page.goto(products());
        await confirmAction(page, 'Remove product');
        await expect(page.getByText('Product removed', { exact: true })).toBeVisible();
        const stored = storefrontState();
        expect(stored.sellables).toEqual([]);
        expect(stored.listings).toEqual([]);
        expect(stored.entries).toEqual(original.entries);
        expect(stored.revisions).toEqual(original.revisions);
    });

    test('removes a disabled product type\'s listing from the index while preserving its content and history', async ({ page }) => {
        const id = seedProduct();
        const original = storefrontState();
        setProductTypeEnabled(false);
        try {
            expect((await page.goto(products())).status()).toBe(200);
            const row = page.locator('.fi-ta-row').filter({ hasText: SKU });
            await expect(row).toBeVisible();
            await expect(row.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
            expect((await page.goto(product(id))).status()).toBe(403);
            await page.goto(products());
            await confirmAction(page, "Remove this site's listing");
            await expect(page.getByText('Listing removed; site content was kept', { exact: true })).toBeVisible();
            let stored = storefrontState();
            expect(stored.listings).toEqual([]);
            expect(stored.sellables).toEqual(original.sellables);
            expect(stored.entries).toEqual(original.entries);
            expect(stored.revisions).toEqual(original.revisions);
            await expect(page.locator('.fi-ta-row').filter({ hasText: SKU })).toContainText('No content on this site');
            await confirmAction(page, 'Remove product');
            await expect(page.getByText('Product removed', { exact: true })).toBeVisible();
            stored = storefrontState();
            expect(stored.sellables).toEqual([]);
            expect(stored.entries).toEqual(original.entries);
            expect(stored.revisions).toEqual(original.revisions);
        } finally {
            setProductTypeEnabled(true);
        }
    });

    test('supports keyboard editing, accessible labels and a narrow phone viewport', async ({ page }) => {
        await page.goto(`${products()}/create`);
        const sku = page.getByRole('textbox', { name: /^SKU\s*\*?$/ });
        await sku.focus();
        await page.keyboard.type('E2E-KEYBOARD');
        await page.keyboard.press('Tab');
        await expect(price(page)).toBeFocused();
        await page.keyboard.type('0.00');
        await title(page).fill('Keyboard course');
        await expect(page.getByRole('combobox', { name: /^Stock tracking\s*\*?$/ })).toHaveValue('untracked');
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(sku).toBeInViewport();
        await create(page);
        expect(storefrontState().sellables[0]).toMatchObject({ sku: 'E2E-KEYBOARD', price_minor: '0', stock: null });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    });
});
