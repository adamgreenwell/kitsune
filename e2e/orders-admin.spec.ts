const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const { artisan, php, literal } = require('./storefront-fixture');
const { callsMethod } = require('./select-control');
const { confirmTestOrder } = require('./paid-order-fixture');

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
        + "$ids=DB::table('commerce_orders')->where('org_id',$org->id)->pluck('id')->all();foreach(['commerce_privacy_holds','commerce_privacy_deliveries','commerce_privacy_transitions','commerce_order_privacy'] as $table){DB::table($table)->where('org_id',$org->id)->whereIn('order_id',$ids)->delete();}"
        + "foreach(['commerce_access_reviews','commerce_access_review_work','commerce_access_adjustment_effects','commerce_access_adjustment_work','commerce_financial_observations','commerce_financial_refunds','commerce_financial_work','commerce_financial_signals','commerce_access_effects','commerce_access_work','commerce_order_claims','commerce_refund_attempts','commerce_refund_obligations','commerce_order_allocations','commerce_payment_receipts','commerce_stripe_events','commerce_checkout_routing','commerce_checkout_attempts','commerce_order_access_tokens','commerce_order_lines','commerce_orders','commerce_retention_policy_activations','commerce_retention_policies','commerce_sales_policies','commerce_tax_rates','commerce_settings','commerce_listings','commerce_sellables'] as $table){DB::table($table)->where('org_id',$org->id)->delete();}"
        + "foreach(Kitsune\\Core\\Models\\Entry::withoutScopeBecause('Dedicated order browser product cleanup',fn($q)=>$q->withTrashed()->where('org_id',$org->id)->where('type_handle','product')->get()) as $entry){$entry->forceDelete();}"
        + "auth()->setUser($owner);$row=app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->createSellable('ADMIN-BOARD','2000',null,'USD');echo (string)$row->id;");
}

function fixtureOrder(siteSlug = SITE, reader: string | null = null) {
    return php(`$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('An exact admin browser fixture site',fn($q)=>$q->where('slug',${literal(siteSlug)})->firstOrFail());`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();'
        + '$reader=' + (reader === null ? 'null' : literal(reader)) + ';$reference=DB::transaction(function()use($site,$reader){$reference=bin2hex(random_bytes(16));$owner=$reader===null?hash(\'sha256\',random_bytes(32)):Kitsune\\Commerce\\Carts\\CartOwner::readerKey($reader);'
        + "$id=DB::table('commerce_orders')->insertGetId(['org_id'=>$site->org_id,'site_id'=>$site->id,'reference'=>$reference,'reader_id'=>$reader,'owner_key'=>$owner,"
        + "'contact_email'=>'admin-buyer@example.test','cart_ref'=>1,'cart_version'=>0,'request_key'=>hash('sha256',random_bytes(32)),'request_digest'=>hash('sha256','request'),'snapshot_hash'=>hash('sha256','snapshot'),"
        + "'active_cart_key'=>hash('sha256',$owner.\"\\0\".'1'.\"\\0\".'0'),'state'=>'prepared','mode'=>'test','currency'=>'USD','tax_mode'=>'exclusive',"
        + "'billing'=>json_encode(['name'=>'Historical Buyer','address'=>['line1'=>'10 Historical Ocean Road','country'=>'US']]),'delivery'=>null,'shipping'=>null,"
        + "'site_snapshot'=>json_encode(['name'=>'Original brand','base_url'=>'https://original.example.test']),"
        + "'quoted_item_net_minor'=>'2000','quoted_shipping_net_minor'=>'0','quoted_tax_minor'=>'200','quoted_total_minor'=>'2200','confirmed_total_minor'=>null,'created_at'=>'2026-10-14 10:00:00','cancelled_at'=>null]);"
        + "DB::table('commerce_order_lines')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$id,'sellable_ref'=>123,'listing_ref'=>456,'entry_ref'=>789,'sku'=>'HISTORICAL-BOARD','title'=>'Original title','quantity'=>1,'unit_price_minor'=>'2000','tax_class'=>'standard','tax_rate_parts'=>100000,'delivery_kind'=>'digital','net_minor'=>'2000','tax_minor'=>'200','gross_minor'=>'2200','entitlement_name'=>null,'offer_terms'=>null]);"
        + '$order=DB::table("commerce_orders")->where("id",$id)->firstOrFail();app(Kitsune\\Commerce\\Privacy\\PrivacyState::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\AccessFulfillment::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\AccessAdjustments::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\FinancialState::class)->prepare(DB::connection(),$order);return $reference;});echo $reference;');
}

async function submit(page, method, label) {
    const button = page.getByRole('button', { name: label, exact: true });
    // The DOM alone can precede Livewire/Alpine event binding after sidebar navigation.
    await expect.poll(() => button.evaluate((element) => {
        const target = element.hasAttribute('wire:click') ? element : element.closest('form[wire\\:submit]');
        const bound = target as Element & { _x_attributeCleanups?: Record<string, unknown> };
        return Object.keys(bound?._x_attributeCleanups ?? {}).some((key) => key.startsWith('x-on:click') || key.startsWith('x-on:submit'));
    })).toBe(true);
    await Promise.all([
        page.waitForResponse((response) => callsMethod(response, method)),
        button.click(),
    ]);
}

async function configure(page) {
    // A sidebar navigation can render controls before Alpine binds wire:model. Filling earlier is overwritten.
    await page.waitForFunction(() => ['checkout-tax_mode', 'checkout-shipping_tax_mode', 'checkout-shipping-class', 'checkout-dispatch-name', 'checkout-address-line1']
        .every((id) => Boolean((document.getElementById(id) as HTMLInputElement & { _x_model?: unknown })?._x_model)));
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

    test('saves explicit international customs and mode-specific carrier choices across sites', async ({ page }) => {
        await page.goto(SETTINGS);
        await configure(page);
        await page.getByLabel('Enable configured international destinations', { exact: true }).check();
        await page.getByLabel('Allowed destination country codes (uppercase, two letters)', { exact: true }).fill('CA\nGB');
        await page.getByLabel('Allowed test carrier account IDs', { exact: true }).fill('a'.repeat(32));
        await page.getByLabel('Person responsible for customs information', { exact: true }).fill('Explicit Browser Certifier');
        await submit(page, 'saveSettings', 'Save checkout settings');
        await expect(page.getByRole('alert').filter({ hasText: 'The change could not be saved.' })).toBeVisible();
        await page.getByRole('checkbox', { name: 'The named person confirms', exact: false }).check();
        await submit(page, 'saveSettings', 'Save checkout settings');
        await expect(page.getByText('Checkout settings saved', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Configure delivery', exact: true }).click();
        await page.locator('#checkout-policy-class').fill('standard');
        await page.getByLabel('Delivery', { exact: true }).selectOption('physical');
        for (const [field, value] of Object.entries({ weight_grams: '500', length_mm: '300', width_mm: '200', height_mm: '100', tare_grams: '125', units_per_parcel: '3' })) {
            await page.locator(`#checkout-pack-${field}`).fill(value);
        }
        await page.getByLabel('Customs description', { exact: true }).fill('Printed book');
        await page.getByLabel('Manufacturing origin country code', { exact: true }).fill('GB');
        await page.getByLabel('Tariff number (6–12 digits)', { exact: true }).fill('490199');
        await submit(page, 'savePolicy', 'Save product delivery');
        await expect(page.getByText('Product delivery saved', { exact: true })).toBeVisible();
        await page.goto(`/admin/${SIBLING}/storefront/checkout`);
        await expect(page.getByLabel('Allowed test carrier account IDs', { exact: true })).toHaveValue('a'.repeat(32));
        await expect(page.getByLabel('Allowed live carrier account IDs', { exact: true })).toHaveValue('');
        await page.getByRole('button', { name: 'Configure delivery', exact: true }).click();
        await expect(page.getByLabel('Customs description', { exact: true })).toHaveValue('Printed book');
        await expect(page.getByLabel('Manufacturing origin country code', { exact: true })).toHaveValue('GB');
        await expect(page.getByLabel('Tariff number (6–12 digits)', { exact: true })).toHaveValue('490199');
        await expect(page.getByText('Duties are paid by the recipient;', { exact: false })).toBeVisible();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.goto(`/admin/${SIBLING}/roles`);
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

    test('renders safe owner recovery controls and clears a refused Session ID without changing unresolved custody', async ({ page }) => {
        const reference = fixtureOrder();
        const attempt = php(`$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact checkout recovery browser fixture',fn($q)=>$q->where('slug',${literal(SITE)})->firstOrFail());`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + `$order=app(Kitsune\\Commerce\\Orders\\OrderReaderData::class)->find(DB::connection(),$site->org_id,$site->id,${literal(reference)});`
            + '$record=app(Kitsune\\Commerce\\Orders\\OrderReaderData::class)->record(DB::connection(),$order);$reference=bin2hex(random_bytes(16));$now=Carbon\\CarbonImmutable::now("UTC")->startOfSecond();$expires=$now->addSeconds(1860);'
            + '$payload=Kitsune\\Commerce\\Payments\\CheckoutAttempts::payload($record,$reference,$expires->timestamp,"https://original.example.test");'
            + "DB::table('commerce_checkout_attempts')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$order->id,'reference'=>$reference,'active_order_id'=>$order->id,"
            + "'mode'=>'test','currency'=>'USD','account_id'=>'acct_browserFixture','api_version'=>Kitsune\\Commerce\\Payments\\StripeCheckout::API_VERSION,'credential_witness'=>str_repeat('a',64),'state'=>'uncertain',"
            + "'request_payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'request_hash'=>Kitsune\\Commerce\\Payments\\CheckoutAttempts::digest($payload),'order_hash'=>Kitsune\\Commerce\\Payments\\CheckoutReview::orderHash($record),"
            + "'session_id'=>null,'checkout_url'=>null,'reviewed_at'=>$now->format('Y-m-d H:i:s'),'expires_at'=>$expires->format('Y-m-d H:i:s'),"
            + "'lease_token'=>null,'lease_until'=>null,'created_at'=>$now->format('Y-m-d H:i:s'),'updated_at'=>$now->format('Y-m-d H:i:s')]);echo $reference;");
        await page.goto(`${ORDERS}/${reference}`);
        await expect(page.getByText(attempt, { exact: true })).toBeVisible();
        await expect(page.getByText('Checkout outcome needs checking', { exact: true })).toBeVisible();
        const input = page.getByRole('textbox', { name: 'Session ID (only if missing)', exact: true });
        await input.fill('not-a-session');
        const expire = page.getByRole('checkbox', { name: 'Expire the open Session after checking it', exact: true });
        await expire.check();
        await submit(page, 'recoverAttempt', 'Check Session');
        await expect(page.getByRole('alert').filter({ hasText: 'Checkout could not be completed.' })).toBeVisible();
        await expect(input).toHaveValue('');
        await expect(expire).not.toBeChecked();
        expect(await page.content()).not.toContain('not-a-session');
        expect(php(`echo DB::table('commerce_checkout_attempts')->where('reference',${literal(attempt)})->value('state');`)).toBe('uncertain');
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        await page.getByText('Recover with this checkout’s original Stripe account', { exact: true }).first().click();
        const form = page.locator('form[action$="/historical-recovery"]');
        await expect(form).toHaveAttribute('method', 'post');
        await form.locator('[name="session_id"]').fill('cs_test_browserFixture');
        const secret = 'sk_test_browserNeverSentToProvider';
        await form.locator('[name="password"]').fill(secret);
        await form.locator('[name="current_password"]').fill('wrong-owner-password');
        const [response] = await Promise.all([
            page.waitForResponse((reply) => reply.url().endsWith('/historical-recovery') && reply.request().method() === 'POST'),
            form.getByRole('button', { name: 'Recover with this checkout’s original Stripe account', exact: true }).click(),
        ]);
        expect(response.status()).toBe(303);
        await expect(page).toHaveURL(new RegExp(`${ORDERS}/${reference}$`));
        expect(await page.content()).not.toContain(secret);
        expect(await page.content()).not.toContain('wrong-owner-password');
        await page.getByText('Recover with this checkout’s original Stripe account', { exact: true }).first().click();
        await expect(page.locator('form[action$="/historical-recovery"] [name="password"]')).toHaveValue('');
        await expect(page.locator('form[action$="/historical-recovery"] [name="current_password"]')).toHaveValue('');
        expect(php(`echo DB::table('commerce_checkout_attempts')->where('reference',${literal(attempt)})->value('state');`)).toBe('uncertain');
        // A previously completed test attempt has no paid receipt yet. It keeps original-account recovery available.
        php(`DB::table('commerce_checkout_attempts')->where('reference',${literal(attempt)})->update(['state'=>'completed','active_order_id'=>null,'session_id'=>'cs_test_browserFixture']);`);
        await page.reload();
        await expect(page.getByRole('button', { name: 'Check Session', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Erase unpaid order', exact: true })).toHaveCount(0);
        await page.getByText('Recover with this checkout’s original Stripe account', { exact: true }).first().click();
        await expect(page.locator('form[action$="/historical-recovery"] [name="password"]')).toBeVisible();
        await page.goto(`/admin/${SITE}`);
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
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

    test('checks purchased access through the native owner action without clearing a financial hold', async ({ page }) => {
        const reference = fixtureOrder();
        confirmTestOrder(reference, 'owed', true);
        await page.goto(`${ORDERS}/${reference}`);
        const summary = page.getByRole('region', { name: 'Purchased access', exact: true });
        await expect(summary.getByText('The payment needs review before access can be awarded.', { exact: true })).toBeVisible();
        await submit(page, 'retryAccess', 'Check purchased access');
        await expect(page.getByText('Purchased access checked', { exact: true })).toBeVisible();
        await expect(summary.getByText('The payment needs review before access can be awarded.', { exact: true })).toBeVisible();
        const state = JSON.parse(php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();`
            + "echo json_encode(['state'=>DB::table('commerce_access_work')->where('order_id',$order->id)->value('state'),'category'=>DB::table('commerce_access_work')->where('order_id',$order->id)->value('category'),'effects'=>DB::table('commerce_access_effects')->where('order_id',$order->id)->count()]);"));
        expect(state).toEqual({ state: 'held', category: 'financial_hold', effects: 0 });
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        await page.goto(`/admin/${SITE}/roles`);
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
    });

    test('shows paid refund obligations and native owner recovery while retaining the paid order', async ({ page }) => {
        const reference = fixtureOrder();
        const fixture = confirmTestOrder(reference, 'failed');
        await page.goto(`${ORDERS}/${reference}`);
        await expect(page.locator('[data-payment-summary]').getByRole('heading', { name: 'Test payment confirmed', exact: true })).toBeVisible();
        await expect(page.getByText('Refund failed — still owed', { exact: true }).first()).toBeVisible();
        await expect(page.locator('[data-refund-owed]')).toHaveText('USD 22.00');
        await expect(page.getByRole('button', { name: 'Cancel unpaid order', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Erase unpaid order', exact: true })).toHaveCount(0);
        await expect(page.getByText(/^Payment and refund history is retained separately from account data\./)).toBeVisible();
        expect(await page.content()).not.toMatch(new RegExp(`${fixture.session}|${fixture.charge}|${fixture.intent}`));
        await page.getByText('Check or submit refund', { exact: true }).first().click();
        const form = page.locator('form[data-refund-recovery="refund_reconcile"]');
        await expect(form).toHaveAttribute('method', 'post');
        await expect(form.locator('[name="attempt_reference"]')).toHaveValue(fixture.refund);
        await expect(page.locator('form[data-refund-recovery="refund_retry"]')).toHaveCount(1);
        const secret = 'sk_test_browserNeverSentToProvider';
        await form.locator('[name="password"]').fill(secret);
        await form.locator('[name="current_password"]').fill('wrong-owner-password');
        const [response] = await Promise.all([
            page.waitForResponse((reply) => reply.url().endsWith('/historical-recovery') && reply.request().method() === 'POST'),
            form.getByRole('button', { name: 'Check or submit refund', exact: true }).click(),
        ]);
        expect(response.status()).toBe(303);
        await expect(page).toHaveURL(new RegExp(`${ORDERS}/${reference}$`));
        expect(await page.content()).not.toMatch(/sk_test_browserNeverSentToProvider|wrong-owner-password/);
        await page.getByText('Check or submit refund', { exact: true }).first().click();
        await expect(page.locator('form[data-refund-recovery="refund_reconcile"] [name="password"]')).toHaveValue('');
        expect(php(`echo DB::table('commerce_refund_attempts')->where('reference',${literal(fixture.refund)})->value('state');`)).toBe('failed');
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        await page.goto(`/admin/${SITE}/roles`);
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
    });

    test('saves a confirmed original-unit refund and retains its exact recovery identity when submission is unavailable', async ({ page }, testInfo) => {
        const reference = fixtureOrder();
        artisan(['kitsune:blueprint', 'apply', 'storefront', '--org=orders-admin-e2e', '--no-interaction']);
        php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();`
            + "$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('An exact refundable browser order site',fn($q)=>$q->whereKey($order->site_id)->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "auth()->setUser(App\\Models\\User::withoutScopeBecause('The browser fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail()));"
            + "$product=Kitsune\\Commerce\\Models\\Sellable::query()->firstOrFail();"
            + "app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->updateProduct($product->id,['sku'=>'ADMIN-BOARD','price_minor'=>'2000','stock'=>null],'USD',['title'=>'Current product title','slug'=>'refundable-board','status'=>'published','values'=>[]],[],true);"
            + "$listing=Kitsune\\Commerce\\Models\\Listing::query()->where('sellable_id',$product->id)->firstOrFail();"
            + "DB::table('commerce_order_lines')->where('order_id',$order->id)->update(['sellable_ref'=>$product->id,'listing_ref'=>$listing->id,'entry_ref'=>$listing->entry_id]);");
        confirmTestOrder(reference);
        const allocation = JSON.parse(php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();`
            + "echo json_encode(DB::table('commerce_order_allocations')->where('order_id',$order->id)->first(['awarded','unavailable']));"));
        expect(Number(allocation.awarded)).toBe(1);
        expect(Number(allocation.unavailable)).toBe(0);
        await page.goto(`${ORDERS}/${reference}`);
        await page.locator('[data-financial-action="request"] summary').click();
        const form = page.locator('[data-financial-action="request"] form');
        await page.waitForFunction(() => Boolean((document.querySelector('[data-financial-action="request"] input[type="number"]') as HTMLInputElement & { _x_model?: unknown })?._x_model));
        await form.getByRole('spinbutton').fill('1');
        await form.getByLabel('Reason for this adjustment', { exact: true }).fill('Browser owner requested the delivered unit');
        await form.getByRole('checkbox').check();
        page.once('dialog', (dialog) => dialog.accept());
        await submit(page, 'requestRefund', 'Save and submit refund');
        const saved = JSON.parse(php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();`
            + "$rows=DB::table('commerce_financial_refunds')->where('order_id',$order->id)->get(['reference','amount_minor','state','idempotency_key']);echo json_encode($rows);"));
        expect(saved).toHaveLength(1);
        expect(String(saved[0].amount_minor)).toBe('2200');
        expect(saved[0].idempotency_key).toBe(`kitsune-refund-${saved[0].reference}`);
        await page.reload();
        await expect(page.getByText('Refunds and payment reviews', { exact: true }).first()).toBeVisible();
        await page.getByText('Owner adjustment history and reasons', { exact: true }).click();
        await expect(page.getByText('Browser owner requested the delivered unit', { exact: true })).toBeVisible();
        await testInfo.attach('owner-refund-access-status', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
        await page.getByText('Recover with the original account key', { exact: true }).click();
        const recovery = page.locator('form[data-financial-recovery="financial_refund"]');
        await expect(recovery.locator('[name="attempt_reference"]')).toHaveValue(saved[0].reference);
        await expect(recovery.locator('[name="password"]')).toHaveValue('');
        await testInfo.attach('owner-refund-recovery', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
        await recovery.locator('[name="password"]').fill('sk_test_financialBrowserNeverSent');
        await recovery.locator('[name="current_password"]').fill('wrong-financial-owner-password');
        await Promise.all([
            page.waitForEvent('domcontentloaded'),
            page.waitForResponse((reply) => reply.url().endsWith('/historical-recovery') && reply.request().method() === 'POST'),
            recovery.getByRole('button').click(),
        ]);
        await expect(page).toHaveURL(new RegExp(`${ORDERS}/${reference}$`));
        const orderMain = page.getByRole('main');
        await expect(orderMain.getByRole('heading', { name: 'Orders', level: 1, exact: true })).toBeVisible();
        await expect(orderMain.getByRole('heading', { name: reference, level: 2, exact: true })).toBeVisible();
        expect(await page.content()).not.toMatch(/sk_test_financialBrowserNeverSent|wrong-financial-owner-password/);
        expect(php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();echo DB::table('commerce_financial_refunds')->where('order_id',$order->id)->count();`)).toBe('1');
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    });

    test('reviews missing historical access through original terms and audited replacements', async ({ page }, testInfo) => {
        const reader = php("$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact historical review browser Site',fn($q)=>$q->where('slug'," + literal(SITE) + ")->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "echo App\\Models\\Reader::createReader('review-'.bin2hex(random_bytes(6)).'@example.test',null,now())->id;");
        const reference = fixtureOrder(SITE, reader);
        artisan(['kitsune:blueprint', 'apply', 'storefront', '--org=orders-admin-e2e', '--no-interaction']);
        const line = php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ')->firstOrFail();'
            + "$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact historical review product Site',fn($q)=>$q->whereKey($order->site_id)->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "auth()->setUser(App\\Models\\User::withoutScopeBecause('Exact historical review fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail()));"
            + "$product=Kitsune\\Commerce\\Models\\Sellable::query()->firstOrFail();"
            + "app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->updateProduct($product->id,['sku'=>'ADMIN-BOARD','price_minor'=>'2000','stock'=>null],'USD',['title'=>'Current historical product','slug'=>'historical-review-product','status'=>'published','values'=>[]],[],true);"
            + "$listing=Kitsune\\Commerce\\Models\\Listing::query()->where('sellable_id',$product->id)->firstOrFail();"
            + "DB::table('commerce_order_lines')->where('order_id',$order->id)->update(['sellable_ref'=>$product->id,'listing_ref'=>$listing->id,'entry_ref'=>$listing->entry_id,'entitlement_name'=>'course.review','offer_terms'=>null]);"
            + "echo DB::table('commerce_order_lines')->where('order_id',$order->id)->value('id');");
        confirmTestOrder(reference);
        const confirmed = php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ")->firstOrFail();echo DB::table('commerce_payment_receipts')->where('order_id',$order->id)->value('verified_at');");
        const expectedExpiry = new Date(confirmed.replace(' ', 'T') + 'Z');
        expectedExpiry.setUTCDate(expectedExpiry.getUTCDate() + 30);
        const expiryText = expectedExpiry.toISOString().replace('T', ' ').replace('.000Z', ' UTC');
        await page.goto(`/admin/${SITE}`);
        await page.getByRole('link', { name: 'Orders', exact: true }).first().click();
        await page.goto(`${ORDERS}/${reference}`);
        const form = page.locator('form[wire\\:submit="reviewHistoricalAccess"]');
        await expect(form).toBeVisible();
        await page.waitForFunction(() => Boolean((document.getElementById('historical-access-line') as HTMLSelectElement & { _x_model?: unknown })?._x_model));
        await form.getByLabel('Original purchase line', { exact: true }).selectOption(line);
        await expect(form.locator('#historical-access-line option:checked')).toContainText('HISTORICAL-BOARD — course.review');
        await form.getByLabel('Review decision', { exact: true }).selectOption('award');
        await expect(form.getByText(expiryText, { exact: true })).toBeVisible();
        await form.getByLabel('Review reason', { exact: true }).fill('Reviewed original archived purchase');
        await form.getByLabel('Evidence reference', { exact: true }).fill('archive:browser-review');
        await submit(page, 'reviewHistoricalAccess', 'Record access review');
        await expect(page.getByText(/^The review could not be recorded\./)).toBeVisible();
        await form.getByRole('checkbox').check();
        await submit(page, 'reviewHistoricalAccess', 'Record access review');
        await expect(page.getByText('Historical access review recorded', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('archive:browser-review', { exact: false })).toBeVisible();
        const state = () => JSON.parse(php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ")->firstOrFail();echo json_encode(['reviews'=>DB::table('commerce_access_reviews')->where('order_id',$order->id)->count(),'grant'=>DB::table('entitlements')->where('source','commerce.order:'.$order->id)->first(['expires_at','revoked_at']),'original'=>DB::table('commerce_order_lines')->where('order_id',$order->id)->value('offer_terms')]);"));
        expect(state()).toMatchObject({ reviews: 1, grant: { expires_at: expiryText.replace(' UTC', ''), revoked_at: null }, original: null });
        await form.getByLabel('Review decision', { exact: true }).selectOption('none');
        await form.getByLabel('Review reason', { exact: true }).fill('Corrected after reviewing invoice evidence');
        await form.getByLabel('Evidence reference', { exact: true }).fill('archive:browser-correction');
        await form.getByRole('checkbox').check();
        await submit(page, 'reviewHistoricalAccess', 'Record access review');
        expect(state().reviews).toBe(2);
        expect(state().grant.revoked_at).not.toBeNull();
        await expect(page.getByText('course.review — Pass remains revoked', { exact: true })).toBeVisible();
        await testInfo.attach('historical-access-owner-correction', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
        php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ")->firstOrFail();"
            + "$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact minimized review fixture Site',fn($q)=>$q->whereKey($order->site_id)->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "auth()->setUser(App\\Models\\User::withoutScopeBecause('Exact minimized review fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail()));"
            + "$policy=app(Kitsune\\Commerce\\Privacy\\RetentionPolicies::class)->createAsOwner([],'Fixture invoice obligation','Minimize private historical review notes',Kitsune\\Commerce\\Privacy\\RetentionPolicySnapshot::CONFIRMATION_DAYS,1);"
            + "app(Kitsune\\Commerce\\Privacy\\OrderPrivacy::class)->adoptAsOwner($order->reference,$policy->version,'Explicit fixture adoption',true);"
            + "Carbon\\CarbonImmutable::setTestNow(Carbon\\CarbonImmutable::now('UTC')->addDays(2));app(Kitsune\\Commerce\\Privacy\\OrderPrivacy::class)->maintainOrder($order->reference);");
        await page.reload();
        await expect(page.getByText(/^Invoice personal data has expired\./)).toBeVisible();
        await page.waitForFunction(() => Boolean((document.getElementById('historical-access-line') as HTMLSelectElement & { _x_model?: unknown })?._x_model));
        await form.getByLabel('Original purchase line', { exact: true }).selectOption(line);
        await expect(form.locator('#historical-access-line option:checked')).toContainText('HISTORICAL-BOARD — course.review');
        await form.getByLabel('Review decision', { exact: true }).selectOption('award');
        await form.getByLabel('Whole days from original payment confirmation', { exact: true }).fill('60');
        const extendedExpiry = new Date(expectedExpiry);
        extendedExpiry.setUTCDate(extendedExpiry.getUTCDate() + 30);
        const extendedText = extendedExpiry.toISOString().replace('T', ' ').replace('.000Z', ' UTC');
        await expect(form.getByText(extendedText, { exact: true })).toBeVisible();
        await form.getByLabel('Review reason', { exact: true }).fill('Private notes after invoice expiry');
        await form.getByLabel('Evidence reference', { exact: true }).fill('archive:post-expiry-browser-private');
        await form.getByRole('checkbox').check();
        await submit(page, 'reviewHistoricalAccess', 'Record access review');
        expect(state().reviews).toBe(3);
        expect(state().grant.expires_at).toBe(extendedText.replace(' UTC', ''));
        expect(state().grant.revoked_at).toBeNull();
        const privateReceipt = JSON.parse(php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ")->firstOrFail();echo json_encode(DB::table('commerce_access_reviews')->where('order_id',$order->id)->orderByDesc('id')->first(['reason','evidence','actor_id','redacted_at']));"));
        expect(privateReceipt).toMatchObject({ reason: null, evidence: null, actor_id: null });
        expect(privateReceipt.redacted_at).not.toBeNull();
        expect(await page.content()).not.toContain('archive:post-expiry-browser-private');
        await testInfo.attach('historical-access-post-expiry-review', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.goto(`/admin/${SITE}/roles`);
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
    });

    test('adopts an explicit historical policy and handles legal and delivery holds after account erasure', async ({ page }, testInfo) => {
        const reader = php("$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact retention order fixture Site',fn($q)=>$q->where('slug'," + literal(SITE) + ")->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "$old=App\\Models\\Reader::findByEmail('retention-order-buyer@example.test');if($old!==null){$old->delete();}"
            + "echo App\\Models\\Reader::createReader('retention-order-buyer@example.test',null,now())->id;");
        const reference = fixtureOrder(SITE, reader);
        artisan(['kitsune:blueprint', 'apply', 'storefront', '--org=orders-admin-e2e', '--no-interaction']);
        php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ')->firstOrFail();'
            + "$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact physical retention fixture Site',fn($q)=>$q->whereKey($order->site_id)->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "auth()->setUser(App\\Models\\User::withoutScopeBecause('Exact retention fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail()));"
            + "$product=Kitsune\\Commerce\\Models\\Sellable::query()->firstOrFail();"
            + "app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->updateProduct($product->id,['sku'=>'ADMIN-BOARD','price_minor'=>'2000','stock'=>null],'USD',['title'=>'Current delivery product','slug'=>'retention-delivery','status'=>'published','values'=>[]],[],true);"
            + "$listing=Kitsune\\Commerce\\Models\\Listing::query()->where('sellable_id',$product->id)->firstOrFail();"
            + "$delivery=['name'=>'Essential Delivery Buyer','address'=>['line1'=>'42 Essential Delivery Road','line2'=>'','city'=>'Honolulu','region'=>'HI','postal_code'=>'96801','country'=>'US']];"
            + "$shipping=['id'=>'retention_rate','carrier'=>'Fixture carrier','service'=>'Ground','amountMinor'=>'500','currency'=>'USD','shipmentId'=>'retention_shipment','test'=>true,'taxClass'=>'shipping','taxMode'=>'inclusive','taxRateParts'=>0,'net'=>'500','tax'=>'0','gross'=>'500'];"
            + "DB::table('commerce_orders')->where('id',$order->id)->update(['delivery'=>json_encode($delivery),'shipping'=>json_encode($shipping),'quoted_shipping_net_minor'=>'500','quoted_total_minor'=>'2700']);"
            + "DB::table('commerce_order_lines')->where('order_id',$order->id)->update(['sellable_ref'=>$product->id,'listing_ref'=>$listing->id,'entry_ref'=>$listing->entry_id,'delivery_kind'=>'physical']);");
        confirmTestOrder(reference);
        const retention = '/admin/' + SITE + '/storefront/retention';
        const readyRetention = () => page.waitForFunction(() => ['retention-legal_basis', 'retention-reason', 'retention-clock', 'retention-duration', 'retention-order']
            .every((id) => Boolean((document.getElementById(id) as HTMLInputElement & { _x_model?: unknown })?._x_model)));
        await page.goto(retention);
        await readyRetention();
        await page.getByLabel('Billing name', { exact: true }).check();
        await page.getByLabel('Billing country', { exact: true }).check();
        await page.getByLabel('Retention obligation', { exact: true }).fill('Recorded historical invoice obligation');
        await page.getByLabel('Operator reason', { exact: true }).fill('Explicit historical adoption in browser fixture');
        await page.getByLabel('Retention clock', { exact: true }).selectOption('confirmation_days');
        await page.getByLabel('Number of days or years', { exact: true }).fill('30');
        await submit(page, 'createPolicy', 'Create policy version');
        const version = php("$org=Kitsune\\Core\\Models\\Org::query()->where('slug','orders-admin-e2e')->firstOrFail();echo DB::table('commerce_retention_policies')->where('org_id',$org->id)->max('version');");
        await page.getByLabel('Order reference', { exact: true }).fill(reference);
        await page.getByLabel('Policy version to adopt', { exact: true }).selectOption(version);
        await page.getByLabel('Reason for this order action', { exact: true }).fill('Reviewed this historical order against selected version');
        await submit(page, 'adoptPolicy', 'Adopt selected policy for this order');
        await expect(page.getByText('The order retention action could not complete. Review its scope, policy, holds and financial custody, then retry.', { exact: true })).toBeVisible();
        await page.getByLabel('I reviewed the selected order, policy and reason and confirm this change.', { exact: true }).check();
        await submit(page, 'adoptPolicy', 'Adopt selected policy for this order');
        await expect(page.getByText('Order retention action completed', { exact: true }).first()).toBeVisible();
        await page.getByLabel('Legal hold review date', { exact: true }).fill(new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10));
        await page.getByLabel('Reason for this order action', { exact: true }).fill('Manual legal hold for this original invoice');
        await page.getByLabel('I reviewed the selected order, policy and reason and confirm this change.', { exact: true }).check();
        await submit(page, 'placeHold', 'Place legal hold');
        await expect(page.getByLabel('Legal hold reference to release', { exact: true })).toHaveValue(/^[a-f0-9]{32}$/);
        const hold = await page.getByLabel('Legal hold reference to release', { exact: true }).inputValue();
        expect(hold).toMatch(/^[a-f0-9]{32}$/);
        php("$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact account erasure fixture Site',fn($q)=>$q->where('slug'," + literal(SITE) + ")->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();'
            + 'app(Kitsune\\Core\\Readers\\ReaderErasure::class)->erase(' + literal(reader) + ');');
        await page.goto(ORDERS + '/' + reference);
        await expect(page.locator('[data-privacy-summary]').getByText('Account and access ownership have been removed from this retained order.', { exact: true })).toBeVisible();
        await expect(page.getByText('Checkout completion alone does not confirm payment. Purchased access follows the current access check.', { exact: true })).toBeVisible();
        await expect(page.getByText(/^Payment and refund history is retained separately from account data\./)).toBeVisible();
        expect(await page.content()).not.toMatch(/You can still download your order data|purchased access is not granted yet/);
        await expect(page.getByText('Historical Buyer', { exact: true })).toBeVisible();
        await expect(page.getByText('US', { exact: true }).first()).toBeVisible();
        expect(await page.content()).not.toMatch(/admin-buyer@example\.test|10 Historical Ocean Road|42 Essential Delivery Road/);
        await testInfo.attach('minimized-retained-invoice', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
        await page.goto('/admin/' + SIBLING + '/storefront/retention');
        await readyRetention();
        await page.getByLabel('Order reference', { exact: true }).fill(reference);
        await submit(page, 'loadDelivery', 'Load essential fulfillment details');
        await expect(page.locator('[data-essential-delivery]')).toHaveCount(0);
        expect(await page.content()).not.toMatch(/admin-buyer@example\.test|42 Essential Delivery Road/);
        await page.goto(retention);
        await readyRetention();
        await page.getByLabel('Order reference', { exact: true }).fill(reference);
        await submit(page, 'loadDelivery', 'Load essential fulfillment details');
        const essential = page.locator('[data-essential-delivery]');
        await expect(essential).toContainText('admin-buyer@example.test');
        await expect(essential).toContainText('42 Essential Delivery Road');
        await testInfo.attach('essential-fulfillment-custody', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
        await page.getByLabel('Reason for this order action', { exact: true }).fill('Physical delivery was completed and contact details are no longer needed');
        await page.getByLabel('I reviewed the selected order, policy and reason and confirm this change.', { exact: true }).check();
        await submit(page, 'completeDelivery', 'Confirm physical delivery complete');
        await expect(essential).toHaveCount(0);
        await page.getByLabel('Legal hold reference to release', { exact: true }).fill(hold);
        await page.getByLabel('Reason for this order action', { exact: true }).fill('Legal review completed');
        await page.getByLabel('I reviewed the selected order, policy and reason and confirm this change.', { exact: true }).check();
        await submit(page, 'releaseHold', 'Release selected legal hold');
        const stored = JSON.parse(php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ')->firstOrFail();'
            + "echo json_encode(['delivery'=>DB::table('commerce_privacy_deliveries')->where('order_id',$order->id)->first(['state','payload','payload_hash']),"
            + "'hold'=>DB::table('commerce_privacy_holds')->where('reference'," + literal(hold) + ")->value('released_at'),'reader'=>DB::table('readers')->where('org_id',$order->org_id)->where('id'," + literal(reader) + ')->exists()]);'));
        expect(stored.delivery).toEqual({ state: 'complete', payload: null, payload_hash: null });
        expect(stored.hold).not.toBeNull();
        expect(stored.reader).toBe(false);
        expect(await page.content()).not.toMatch(/admin-buyer@example\.test|42 Essential Delivery Road/);
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    });
});
