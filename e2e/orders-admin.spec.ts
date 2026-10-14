const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const { php, literal } = require('./storefront-fixture');
const { callsMethod } = require('./select-control');

const SITE = 'orders-admin-main';
const SIBLING = 'orders-admin-other';
const SETTINGS = `/admin/${SITE}/storefront/checkout`;
const ORDERS = `/admin/${SITE}/storefront/orders`;

/** Dedicated fixture-only cleanup never touches another spec's customers, products or configuration. */
function resetFixture() {
    return php("$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate(['slug'=>'orders-admin-e2e'],['name'=>'Order admin browser fixture','currency'=>'USD']);"
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + "$owner=App\\Models\\User::query()->withoutGlobalScopes()->where('email','alpha@kitsune.test')->firstOrFail();"
        + "$editor=App\\Models\\User::query()->withoutGlobalScopes()->where('email','copyeditor@kitsune.test')->firstOrFail();"
        + '$owner->orgs()->syncWithoutDetaching([$org->id]);$editor->orgs()->syncWithoutDetaching([$org->id]);'
        + "$role=Kitsune\\Core\\Models\\Role::query()->where('is_owner',true)->first()??Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
        + `foreach([${literal(SITE)},${literal(SIBLING)}] as $slug){`
        + "$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate(['slug'=>$slug],['handle'=>$slug,'name'=>$slug,'locale'=>'en','base_url'=>'/'.$slug]);"
        + '$owner->sites()->syncWithoutDetaching([$site->id]);$editor->sites()->syncWithoutDetaching([$site->id]);}'
        + "foreach(['commerce_order_access_tokens','commerce_order_lines','commerce_orders','commerce_sales_policies','commerce_tax_rates','commerce_settings','commerce_sellables'] as $table){DB::table($table)->where('org_id',$org->id)->delete();}"
        + "auth()->setUser($owner);$row=app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->createSellable('ADMIN-BOARD','2000',null,'USD');echo (string)$row->id;");
}

function fixtureOrder(siteSlug = SITE) {
    return php(`$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('An exact admin browser fixture site',fn($q)=>$q->where('slug',${literal(siteSlug)})->firstOrFail());`
        + '$reference=bin2hex(random_bytes(16));$owner=hash(\'sha256\',random_bytes(32));'
        + "$id=DB::table('commerce_orders')->insertGetId(['org_id'=>$site->org_id,'site_id'=>$site->id,'reference'=>$reference,'reader_id'=>null,'owner_key'=>$owner,"
        + "'contact_email'=>'admin-buyer@example.test','cart_ref'=>1,'cart_version'=>0,'request_key'=>hash('sha256',random_bytes(32)),'request_digest'=>hash('sha256','request'),'snapshot_hash'=>hash('sha256','snapshot'),"
        + "'active_cart_key'=>hash('sha256',$owner.\"\\0\".'1'.\"\\0\".'0'),'state'=>'prepared','mode'=>'test','currency'=>'USD','tax_mode'=>'exclusive',"
        + "'billing'=>json_encode(['name'=>'Historical Buyer','address'=>['line1'=>'10 Historical Ocean Road','country'=>'US']]),'delivery'=>null,'shipping'=>null,"
        + "'site_snapshot'=>json_encode(['name'=>'Original brand','base_url'=>'https://original.example.test']),"
        + "'quoted_item_net_minor'=>'2000','quoted_shipping_net_minor'=>'0','quoted_tax_minor'=>'200','quoted_total_minor'=>'2200','confirmed_total_minor'=>null,'created_at'=>'2026-10-14 10:00:00','cancelled_at'=>null]);"
        + "DB::table('commerce_order_lines')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$id,'sellable_ref'=>123,'listing_ref'=>456,'entry_ref'=>789,'sku'=>'HISTORICAL-BOARD','title'=>'Original title','quantity'=>1,'unit_price_minor'=>'2000','tax_class'=>'standard','tax_rate_parts'=>100000,'delivery_kind'=>'digital','net_minor'=>'2000','tax_minor'=>'200','gross_minor'=>'2200','entitlement_name'=>null,'offer_terms'=>null]);echo $reference;");
}

async function submit(page, method, label) {
    await Promise.all([
        page.waitForResponse((response) => callsMethod(response, method)),
        page.getByRole('button', { name: label, exact: true }).click(),
    ]);
}

async function configure(page) {
    await page.locator('#checkout-tax_mode').selectOption('exclusive');
    await page.locator('#checkout-shipping_tax_mode').selectOption('inclusive');
    await page.getByLabel('Shipping tax class', { exact: true }).fill('shipping');
    await page.getByLabel('Dispatch name', { exact: true }).fill('Ocean dispatch');
    for (const [field, value] of Object.entries({ line1: '10 Ocean Road', city: 'Honolulu', region: 'HI', postal_code: '96801', country: 'US' })) {
        await page.locator(`#checkout-address-${field}`).fill(value);
    }
    await submit(page, 'saveSettings', 'Save checkout settings');
    await expect(page.getByText('Checkout settings saved', { exact: true })).toBeVisible();
}

test.describe('Owner checkout and order administration', () => {
    test.beforeEach(() => resetFixture());
    test.afterEach(() => resetFixture());

    test('renders settings outside content routes and saves real tax and parcel configuration', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);
        await page.getByRole('link', { name: 'Checkout settings', exact: true }).first().click();
        await expect(page).toHaveURL(new RegExp(`${SETTINGS}$`));
        await configure(page);
        await page.locator('#checkout-tax-country').fill('US');
        await page.locator('#checkout-tax-region').fill('*');
        await page.locator('#checkout-tax-tax_class').fill('standard');
        await page.getByLabel('Tax percentage', { exact: true }).fill('7.3125');
        await submit(page, 'saveTaxRate', 'Save tax rule');
        await expect(page.getByText('Tax rule saved', { exact: true })).toBeVisible();
        await expect(page.getByText('US / * — standard — 7.3125%', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Configure delivery', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Delivery for ADMIN-BOARD', exact: true })).toBeVisible();
        await page.locator('#checkout-policy-class').fill('standard');
        await page.getByLabel('Delivery', { exact: true }).selectOption('physical');
        for (const [field, value] of Object.entries({ weight_grams: '500', length_mm: '300', width_mm: '200', height_mm: '100', tare_grams: '125', units_per_parcel: '3' })) {
            await page.locator(`#checkout-pack-${field}`).fill(value);
        }
        await submit(page, 'savePolicy', 'Save product delivery');
        await expect(page.getByText('Product delivery saved', { exact: true })).toBeVisible();
        await page.reload();
        await page.getByRole('button', { name: 'Configure delivery', exact: true }).click();
        await expect(page.getByLabel('Item weight (g)', { exact: true })).toHaveValue('500');
        await expect(page.getByLabel('Items per parcel', { exact: true })).toHaveValue('3');
        const state = JSON.parse(php("$org=Kitsune\\Core\\Models\\Org::query()->where('slug','orders-admin-e2e')->firstOrFail();echo json_encode(['rate'=>DB::table('commerce_tax_rates')->where('org_id',$org->id)->value('rate_parts'),'kind'=>DB::table('commerce_sales_policies')->where('org_id',$org->id)->value('delivery_kind')]);"));
        expect(state).toEqual({ rate: 73125, kind: 'physical' });
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        await page.goto(`/admin/${SITE}/roles`);
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
    });

    test('refuses a stale settings tab and retains the current server choices', async ({ page, context }) => {
        await page.goto(SETTINGS);
        await configure(page);
        const stale = await context.newPage();
        try {
            await stale.goto(SETTINGS);
            await page.locator('#checkout-tax_mode').selectOption('inclusive');
            await submit(page, 'saveSettings', 'Save checkout settings');
            await stale.locator('#checkout-shipping_tax_mode').selectOption('exclusive');
            await submit(stale, 'saveSettings', 'Save checkout settings');
            await expect(stale.getByRole('alert').filter({ hasText: 'The change could not be saved.' })).toBeVisible();
            await stale.reload();
            await expect(stale.locator('#checkout-tax_mode')).toHaveValue('inclusive');
            await expect(stale.locator('#checkout-shipping_tax_mode')).toHaveValue('inclusive');
        } finally {
            await stale.close();
        }
    });

    test('opens the actual native detail route and cancels then erases an unpaid historical order', async ({ page }) => {
        const reference = fixtureOrder();
        await page.goto(ORDERS);
        await page.getByRole('link', { name: reference, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORDERS}/${reference}$`));
        await expect(page.getByText('Historical Buyer', { exact: true })).toBeVisible();
        await expect(page.getByText('Original title', { exact: true })).toBeVisible();
        await expect(page.getByText('Not charged', { exact: true })).toBeVisible();
        await expect(page.getByText('Test mode', { exact: true })).toBeVisible();
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        page.once('dialog', (dialog) => dialog.accept());
        await submit(page, 'cancelOrder', 'Cancel unpaid order');
        await expect(page.getByText('Order cancelled', { exact: true })).toBeVisible();
        await expect(page.getByText('Cancelled', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Cancel unpaid order', exact: true })).toHaveCount(0);
        page.once('dialog', (dialog) => dialog.accept());
        await submit(page, 'eraseOrder', 'Erase unpaid order');
        await expect(page).toHaveURL(new RegExp(`${ORDERS}$`));
        await expect(page.getByText('No orders on this site yet.', { exact: true })).toBeVisible();
    });

    test('rejects editor settings and sibling-site or malformed direct order URLs', async ({ page, browser }) => {
        const reference = fixtureOrder(SIBLING);
        for (const target of [`${ORDERS}/${reference}`, `${ORDERS}/1`, `${ORDERS}/${'A'.repeat(32)}`]) {
            const response = await page.goto(target);
            expect(response.status()).toBe(404);
        }
        const editor = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-reader-auth.json') });
        try {
            const editorPage = await editor.newPage();
            await editorPage.goto(`/admin/${SITE}`);
            await expect(editorPage.getByRole('link', { name: 'Checkout settings', exact: true })).toHaveCount(0);
            await expect(editorPage.getByRole('link', { name: 'Orders', exact: true })).toHaveCount(0);
            expect((await editorPage.goto(SETTINGS)).status()).toBe(403);
            expect((await editorPage.goto(ORDERS)).status()).toBe(403);
        } finally {
            await editor.close();
        }
    });
});
