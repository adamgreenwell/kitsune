// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { php, literal } = require('./storefront-fixture');
const { confirmTestOrder } = require('./paid-order-fixture');

const ORG = 'exports-admin-e2e';
const SITE = 'exports-admin-main';
const SIBLING = 'exports-admin-other';
const FOREIGN = 'exports-rival-main';
const ORDERS = '/admin/' + SITE + '/storefront/orders';

/** Only these two disposable Orgs and their exact synthetic order IDs belong to this spec. */
function resetFixture() {
    for (const [slug, name, sites, email] of [
        [ORG, 'Export browser fixture', [SITE, SIBLING], 'alpha@kitsune.test'],
        ['exports-rival-e2e', 'Rival export browser fixture', [FOREIGN], 'rival@kitsune.test'],
    ]) {
        php('$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate([\'slug\'=>' + literal(slug) + '],[\'name\'=>' + literal(name) + ",'currency'=>'USD']);"
            + 'if($org->name!==' + literal(name) + "){throw new RuntimeException('Unexpected export fixture Org.');}"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
            + "$owner=App\\Models\\User::withoutScopeBecause('Exact export fixture owner',fn($q)=>$q->where('email'," + literal(email) + ')->firstOrFail());'
            + "$editor=App\\Models\\User::withoutScopeBecause('Exact export fixture editor',fn($q)=>$q->where('email','copyeditor@kitsune.test')->firstOrFail());"
            + '$owner->orgs()->syncWithoutDetaching([$org->id]);$editor->orgs()->syncWithoutDetaching([$org->id]);'
            + "$role=Kitsune\\Core\\Models\\Role::query()->where('is_owner',true)->first()??Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
            + 'foreach([' + sites.map(literal).join(',') + "] as $slug){$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate(['slug'=>$slug],['handle'=>$slug,'name'=>$slug,'locale'=>'en','base_url'=>'/'.$slug]);"
            + '$owner->sites()->syncWithoutDetaching([$site->id]);$editor->sites()->syncWithoutDetaching([$site->id]);}'
            + "$ids=DB::table('commerce_orders')->where('org_id',$org->id)->pluck('id')->all();"
            + "foreach(['commerce_access_reviews','commerce_access_review_work','commerce_privacy_holds','commerce_privacy_deliveries','commerce_privacy_transitions','commerce_order_privacy','commerce_access_adjustment_effects','commerce_access_adjustment_work','commerce_financial_observations','commerce_financial_refunds','commerce_financial_work','commerce_access_effects','commerce_access_work','commerce_order_claims','commerce_refund_attempts','commerce_refund_obligations','commerce_order_allocations','commerce_payment_receipts','commerce_stripe_events','commerce_checkout_routing','commerce_checkout_attempts','commerce_order_access_tokens','commerce_order_lines','commerce_orders'] as$table){DB::table($table)->where('org_id',$org->id)->whereIn($table==='commerce_orders'?'id':'order_id',$ids)->delete();}"
            + "foreach(['commerce_retention_policy_activations','commerce_retention_policies'] as$table){DB::table($table)->where('org_id',$org->id)->delete();}");
    }
}

function seedOrder(siteSlug) {
    return php("$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact synthetic export Site',fn($q)=>$q->where('slug'," + literal(siteSlug) + ')->firstOrFail());'
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();'
        + '$reference=DB::transaction(function()use($site){$reference=bin2hex(random_bytes(16));$owner=hash(\'sha256\',random_bytes(32));$now=now()->format(\'Y-m-d H:i:s\');'
        + "$id=DB::table('commerce_orders')->insertGetId(['org_id'=>$site->org_id,'site_id'=>$site->id,'reference'=>$reference,'reader_id'=>null,'owner_key'=>$owner,"
        + "'contact_email'=>'private-export-buyer@example.test','cart_ref'=>1,'cart_version'=>0,'request_key'=>hash('sha256',random_bytes(32)),'request_digest'=>hash('sha256','export request'),'snapshot_hash'=>hash('sha256','export snapshot'),"
        + "'active_cart_key'=>hash('sha256',$owner.\"\\0\".'1'.\"\\0\".'0'),'state'=>'prepared','mode'=>'test','currency'=>'USD','tax_mode'=>'exclusive',"
        + "'billing'=>json_encode(['name'=>'Private Export Buyer','address'=>['line1'=>'42 Private Export Lane','city'=>'Honolulu','region'=>'HI','postal_code'=>'96801','country'=>'US']]),'delivery'=>null,'shipping'=>null,"
        + "'site_snapshot'=>json_encode(['name'=>'Frozen export brand','base_url'=>'https://export-fixture.example.test']),'quoted_item_net_minor'=>'2000','quoted_shipping_net_minor'=>'0','quoted_tax_minor'=>'200','quoted_total_minor'=>'2200','confirmed_total_minor'=>null,'created_at'=>$now,'cancelled_at'=>null]);"
        + "DB::table('commerce_order_lines')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$id,'sellable_ref'=>123,'listing_ref'=>456,'entry_ref'=>789,'sku'=>'EXPORT-COURSE','title'=>'Frozen export course','quantity'=>1,'unit_price_minor'=>'2000','tax_class'=>'standard','tax_rate_parts'=>100000,'delivery_kind'=>'digital','net_minor'=>'2000','tax_minor'=>'200','gross_minor'=>'2200','entitlement_name'=>null,'offer_terms'=>null]);"
        + '$order=DB::table("commerce_orders")->where("id",$id)->firstOrFail();app(Kitsune\\Commerce\\Privacy\\PrivacyState::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\AccessFulfillment::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\AccessAdjustments::class)->prepare(DB::connection(),$order);app(Kitsune\\Commerce\\Payments\\FinancialState::class)->prepare(DB::connection(),$order);return $reference;});echo $reference;');
}

function seedFixture() {
    resetFixture();
    const references = [seedOrder(SITE), seedOrder(SITE), seedOrder(SIBLING), seedOrder(FOREIGN)];
    for (const reference of references) {
        confirmTestOrder(reference);
        php("$order=DB::table('commerce_orders')->where('reference'," + literal(reference) + ')->firstOrFail();'
            + "$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('Exact export policy fixture Site',fn($q)=>$q->whereKey($order->site_id)->firstOrFail());"
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + "$email=$site->slug==='exports-rival-main'?'rival@kitsune.test':'alpha@kitsune.test';auth()->setUser(App\\Models\\User::withoutScopeBecause('Exact export fixture operator',fn($q)=>$q->where('email',$email)->firstOrFail()));"
            + '$policies=app(Kitsune\\Commerce\\Privacy\\RetentionPolicies::class);'
            + "$versions=$policies->versionsAsOwner();$policy=$versions[0]??$policies->createAsOwner(['name','address.country'],'Recorded fixture invoice obligation','Explicit export browser selection','confirmation_days',30);"
            + "app(Kitsune\\Commerce\\Privacy\\OrderPrivacy::class)->adoptAsOwner($order->reference,$policy->version,'Explicit export fixture adoption',true);");
    }
    return references;
}

async function downloadPage(page) {
    const [download, response] = await Promise.all([
        page.waitForEvent('download'),
        page.waitForResponse((reply) => reply.url().endsWith('/storefront/orders/export') && reply.request().method() === 'POST'),
        page.locator('[data-order-export]').getByRole('button', { name: 'Download this page', exact: true }).click(),
    ]);
    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(response.headers()['referrer-policy']).toBe('no-referrer');
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    return { body: fs.readFileSync(await download.path(), 'utf8'), filename: download.suggestedFilename(), cursor: response.headers()['x-kitsune-next-cursor'] };
}

/** Parse actual downloaded bytes with independent native consumers, including quoted CSV cells. */
function downloadRecords(format, body) {
    if (format === 'jsonl') return body.trim().split('\n').map((line) => JSON.parse(line));
    const [columns, ...rows] = JSON.parse(execFileSync('php', ['-r',
        '$rows=[];while(($row=fgetcsv(STDIN,null,",",chr(34),""))!==false){$rows[]=$row;}echo json_encode($rows,JSON_THROW_ON_ERROR);',
    ], { input: body, encoding: 'utf8' }));
    expect(columns).toEqual(expect.arrayContaining(['schema', 'type', 'order_reference', 'profile', 'next_cursor', 'billing_field', 'billing_value']));
    return rows.map((row) => {
        expect(row).toHaveLength(columns.length);
        return Object.fromEntries(columns.map((column, index) => [column, row[index]]));
    });
}

test.describe('Owner commerce financial exports', () => {
    let references;
    test.beforeEach(() => { references = seedFixture(); });
    test.afterEach(resetFixture);

    test('downloads both formats with financial data by default and only explicitly permitted billing', async ({ page }, testInfo) => {
        expect((await page.goto('/admin/' + SITE)).status()).toBe(200);
        await page.getByRole('link', { name: 'Orders', exact: true }).first().click();
        await expect(page.getByRole('heading', { name: 'Orders', level: 1, exact: true })).toBeVisible();
        const form = page.locator('[data-order-export]');
        await expect(form).toHaveAttribute('method', 'post');
        await expect(form.getByRole('checkbox')).not.toBeChecked();
        for (const format of ['jsonl', 'csv']) {
            await form.getByLabel('File format', { exact: true }).selectOption(format);
            await form.getByRole('checkbox').uncheck();
            const financial = await downloadPage(page);
            expect(financial.filename).toMatch(new RegExp('\\.' + format + '$'));
            expect(financial.body).toContain(references[0]);
            expect(financial.body).toContain(references[1]);
            expect(financial.body).not.toMatch(new RegExp(references[2] + '|' + references[3]));
            expect(financial.body).not.toMatch(/Private Export Buyer|private-export-buyer@example\.test|42 Private Export Lane|owner_key|token_hash/);
            const records = downloadRecords(format, financial.body);
            expect(records[0].type).toBe('manifest');
            expect((records[0].data ?? records[0]).profile).toBe('financial');
            expect(records.filter((row) => row.type === 'order').map((row) => row.order_reference)).toEqual(references.slice(0, 2));
            expect(records.at(-1).type).toBe('complete');
            expect(String((records.at(-1).data ?? records.at(-1)).count)).toBe('2');
            expect(records.filter((row) => row.type === 'billing')).toEqual([]);
            await testInfo.attach('financial-history.' + format, { body: financial.body, contentType: format === 'jsonl' ? 'application/x-ndjson' : 'text/csv' });
            await form.getByRole('checkbox').check();
            const billing = await downloadPage(page);
            expect(billing.body).toContain('Private Export Buyer');
            expect(billing.body).not.toMatch(/private-export-buyer@example\.test|42 Private Export Lane/);
            const billingRecords = downloadRecords(format, billing.body);
            expect((billingRecords[0].data ?? billingRecords[0]).profile).toBe('financial_with_billing');
            expect(billingRecords.filter((row) => row.type === 'billing').map((row) => (row.data ?? row).billing_field))
                .toEqual(['name', 'address.country', 'name', 'address.country']);
            await testInfo.attach('selected-billing-history.' + format, { body: billing.body, contentType: format === 'jsonl' ? 'application/x-ndjson' : 'text/csv' });
        }
        await expect(page.getByRole('heading', { name: 'Orders', level: 1, exact: true })).toBeVisible();
        await expect(form).toBeVisible();
        await form.scrollIntoViewIfNeeded();
        await expect(form.getByRole('button', { name: 'Download this page', exact: true })).toBeVisible();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await testInfo.attach('owner-financial-export-form', { body: await form.screenshot(), contentType: 'image/png' });
        await testInfo.attach('owner-financial-export-page', { body: await page.screenshot(), contentType: 'image/png' });
        await page.goto('/admin/' + SITE + '/roles');
        await expect(page.getByRole('link', { name: 'Orders', exact: true }).first()).toBeVisible();
    });

    test('continues exact pages and rejects cross-Site cursor reuse or query-only opt-in', async ({ page }) => {
        await page.goto(ORDERS);
        const form = page.locator('[data-order-export]');
        await form.getByLabel('Orders per download', { exact: true }).fill('1');
        const first = await downloadPage(page);
        expect(first.body).toContain(references[0]);
        expect(first.body).not.toContain(references[1]);
        expect(first.cursor).toBeTruthy();
        expect(downloadRecords('jsonl', first.body).at(-1).data.next_cursor).toBe(first.cursor);
        await form.getByLabel('Continuation cursor', { exact: true }).fill(first.cursor);
        const second = await downloadPage(page);
        expect(second.body).toContain(references[1]);
        expect(second.body).not.toContain(references[0]);
        expect(downloadRecords('jsonl', second.body).at(-1).data.next_cursor).toBeNull();
        const token = await form.locator('[name="_token"]').inputValue();
        const cross = await page.request.post('/admin/' + SIBLING + '/storefront/orders/export', { form: { _token: token, cursor: first.cursor, limit: '1', format: 'jsonl' } });
        expect(cross.status()).toBe(503);
        expect(cross.headers()['content-disposition']).toBeUndefined();
        const query = await page.request.post(ORDERS + '/export?include_billing=1', { form: { _token: token, format: 'jsonl', limit: '100' } });
        expect(query.status()).toBe(200);
        expect(await query.text()).not.toContain('Private Export Buyer');
        const foreign = await page.request.post('/admin/' + FOREIGN + '/storefront/orders/export', { form: { _token: token, format: 'jsonl' } });
        expect(foreign.status()).toBe(404);
        expect(foreign.headers()['content-disposition']).toBeUndefined();
    });

    test('refuses an editor native download and leaves owner-only controls out of its panel', async ({ browser }) => {
        const context = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-reader-auth.json') });
        try {
            const page = await context.newPage();
            expect((await page.goto('/admin/' + SITE)).status()).toBe(200);
            await expect(page.getByRole('link', { name: 'Orders', exact: true })).toHaveCount(0);
            const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
            const response = await page.request.post(ORDERS + '/export', { form: { _token: token, format: 'jsonl', include_billing: '1' } });
            expect(response.status()).toBe(403);
            expect(response.headers()['content-disposition']).toBeUndefined();
            expect(await response.text()).not.toContain(references[0]);
        } finally {
            await context.close();
        }
    });
});
