const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const { php, literal } = require('./storefront-fixture');
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
        + "foreach(['commerce_access_effects','commerce_access_work','commerce_order_claims','commerce_refund_attempts','commerce_refund_obligations','commerce_order_allocations','commerce_payment_receipts','commerce_stripe_events','commerce_checkout_routing','commerce_checkout_attempts','commerce_order_access_tokens','commerce_order_lines','commerce_orders','commerce_sales_policies','commerce_tax_rates','commerce_settings','commerce_sellables'] as $table){DB::table($table)->where('org_id',$org->id)->delete();}"
        + "auth()->setUser($owner);$row=app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->createSellable('ADMIN-BOARD','2000',null,'USD');echo (string)$row->id;");
}

function fixtureOrder(siteSlug = SITE) {
    return php(`$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('An exact admin browser fixture site',fn($q)=>$q->where('slug',${literal(siteSlug)})->firstOrFail());`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();'
        + '$reference=DB::transaction(function()use($site){$reference=bin2hex(random_bytes(16));$owner=hash(\'sha256\',random_bytes(32));'
        + "$id=DB::table('commerce_orders')->insertGetId(['org_id'=>$site->org_id,'site_id'=>$site->id,'reference'=>$reference,'reader_id'=>null,'owner_key'=>$owner,"
        + "'contact_email'=>'admin-buyer@example.test','cart_ref'=>1,'cart_version'=>0,'request_key'=>hash('sha256',random_bytes(32)),'request_digest'=>hash('sha256','request'),'snapshot_hash'=>hash('sha256','snapshot'),"
        + "'active_cart_key'=>hash('sha256',$owner.\"\\0\".'1'.\"\\0\".'0'),'state'=>'prepared','mode'=>'test','currency'=>'USD','tax_mode'=>'exclusive',"
        + "'billing'=>json_encode(['name'=>'Historical Buyer','address'=>['line1'=>'10 Historical Ocean Road','country'=>'US']]),'delivery'=>null,'shipping'=>null,"
        + "'site_snapshot'=>json_encode(['name'=>'Original brand','base_url'=>'https://original.example.test']),"
        + "'quoted_item_net_minor'=>'2000','quoted_shipping_net_minor'=>'0','quoted_tax_minor'=>'200','quoted_total_minor'=>'2200','confirmed_total_minor'=>null,'created_at'=>'2026-10-14 10:00:00','cancelled_at'=>null]);"
        + "DB::table('commerce_order_lines')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$id,'sellable_ref'=>123,'listing_ref'=>456,'entry_ref'=>789,'sku'=>'HISTORICAL-BOARD','title'=>'Original title','quantity'=>1,'unit_price_minor'=>'2000','tax_class'=>'standard','tax_rate_parts'=>100000,'delivery_kind'=>'digital','net_minor'=>'2000','tax_minor'=>'200','gross_minor'=>'2200','entitlement_name'=>null,'offer_terms'=>null]);"
        + '$order=DB::table("commerce_orders")->where("id",$id)->firstOrFail();app(Kitsune\\Commerce\\Payments\\AccessFulfillment::class)->prepare(DB::connection(),$order);return $reference;});echo $reference;');
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
        await expect(page.getByText('Test checkout outcome needs checking', { exact: true })).toBeVisible();
        const input = page.getByRole('textbox', { name: 'Session ID (only if missing)', exact: true });
        await input.fill('not-a-session');
        const expire = page.getByRole('checkbox', { name: 'Expire the open test Session after checking it', exact: true });
        await expire.check();
        await submit(page, 'recoverAttempt', 'Check test Session');
        await expect(page.getByRole('alert').filter({ hasText: 'Test checkout could not be completed.' })).toBeVisible();
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
        await expect(page.getByRole('button', { name: 'Check test Session', exact: true })).toHaveCount(0);
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
        await expect(page.getByText(/^Cancellation and erasure are unavailable for this paid test order\./)).toBeVisible();
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
});
