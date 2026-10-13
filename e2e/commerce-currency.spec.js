// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const PAGE = '/admin/golfdom/storefront/currency';

function commerce(code) {
    return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
        cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8',
    });
}

test('an owner selects currency and keeps navigation working outside content routes', async ({ page }) => {
    await page.goto('/admin/golfdom');
    await page.getByRole('link', { name: 'Catalogue currency', exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`${PAGE}$`));
    const select = page.getByLabel('Org currency', { exact: true });
    await expect(select).toHaveValue('');
    await expect(select).toHaveAttribute('aria-invalid', 'false');
    await expect(select.locator('option')).toHaveCount(156);
    await expect(select.locator('option[value="JPY"]')).toContainText('0 decimal places');
    await expect(select.locator('option[value="KWD"]')).toContainText('3 decimal places');
    await select.selectOption('JPY');
    await page.getByRole('button', { name: 'Save currency', exact: true }).click();
    await expect(page.getByText('Currency saved', { exact: true })).toBeVisible();
    await page.goto('/admin/golfdom-fr/storefront/currency');
    await expect(page.getByLabel('Org currency', { exact: true })).toHaveValue('JPY');
    for (const route of ['/admin/golfdom', '/admin/golfdom/roles']) {
        const response = await page.goto(route);
        expect(response.status()).toBe(200);
        await expect(page.getByRole('link', { name: 'Catalogue currency', exact: true }).first()).toBeVisible();
    }
    await page.goto(PAGE);
    await select.selectOption('USD');
    await page.getByRole('button', { name: 'Save currency', exact: true }).click();
    await expect(page.getByText('Currency saved', { exact: true })).toBeVisible();
    const audit = await new AxeBuilder({ page }).analyze();
    expect(audit.violations).toEqual([]);
});

test('existing product prices cannot be relabelled through the chooser', async ({ page }) => {
    commerce("$site=Kitsune\\Core\\Models\\Site::query()->withoutGlobalScopes()->where('slug','golfdom')->firstOrFail(); app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site); auth()->setUser(App\\Models\\User::query()->where('email','alpha@kitsune.test')->firstOrFail()); app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD'); app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->createSellable('E2E-COURSE', '2000', null, 'USD');");
    try {
        await page.goto(PAGE);
        await page.getByLabel('Org currency', { exact: true }).selectOption('EUR');
        await page.getByRole('button', { name: 'Save currency', exact: true }).click();
        await expect(page.getByText(/Currency could not be changed/)).toBeVisible();
        await expect(page.getByLabel('Org currency', { exact: true })).toHaveAttribute('aria-invalid', 'true');
        await page.reload();
        await expect(page.getByLabel('Org currency', { exact: true })).toHaveValue('USD');
    } finally {
        commerce("$site=Kitsune\\Core\\Models\\Site::query()->withoutGlobalScopes()->where('slug','golfdom')->firstOrFail(); app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site); auth()->setUser(App\\Models\\User::query()->where('email','alpha@kitsune.test')->firstOrFail()); $row=Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','E2E-COURSE')->firstOrFail(); app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->removeSellable($row->id);");
    }
});

test('another org has its own unset currency', async ({ browser }) => {
    const context = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-rival-auth.json') });
    try {
        const page = await context.newPage();
        const response = await page.goto('/admin/rival-golfdom/storefront/currency');
        expect(response.status()).toBe(200);
        await expect(page.getByLabel('Org currency', { exact: true })).toHaveValue('');
    } finally {
        await context.close();
    }
});

test('a content editor cannot open the currency chooser', async ({ browser }) => {
    const context = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-reader-auth.json') });
    try {
        const page = await context.newPage();
        await page.goto('/admin/golfdom');
        await expect(page.getByRole('link', { name: 'Catalogue currency', exact: true })).toHaveCount(0);
        const response = await page.goto(PAGE);
        expect(response.status()).toBe(403);
    } finally {
        await context.close();
    }
});
