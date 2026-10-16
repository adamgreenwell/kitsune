// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { php, literal } = require('./storefront-fixture');
const ORG = 'live-stripe-e2e';
const SITE = 'live-stripe-main';
const ORDERS = `/admin/${SITE}/storefront/orders`;

function context() {
    return '$org=Kitsune\\Core\\Models\\Org::query()->where(\'slug\',' + literal(ORG) + ')->firstOrFail();'
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + '$site=Kitsune\\Core\\Models\\Site::query()->where(\'slug\',' + literal(SITE) + ')->firstOrFail();'
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);';
}
function setKey(tail = 'b') {
    php(context() + "Kitsune\\Core\\Credentials\\CredentialConsoleAuthority::run(app(Kitsune\\Core\\Tenancy\\Context::class),'set',Kitsune\\Commerce\\Payments\\StripeCheckout::SLOT,Kitsune\\Core\\Credentials\\CredentialMode::Live,null,"
        + "fn($proof)=>app(Kitsune\\Core\\Credentials\\CredentialWriter::class)->set(Kitsune\\Commerce\\Payments\\StripeCheckout::SLOT,Kitsune\\Core\\Credentials\\CredentialMode::Live,'sk_'.'live_'.str_repeat(" + literal(tail) + ",32),console:$proof));");
}
function reset() {
    php('$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate([\'slug\'=>' + literal(ORG) + "],['name'=>'Live Stripe browser fixture','currency'=>'USD']);"
        + "if($org->name!=='Live Stripe browser fixture'){throw new RuntimeException('Unexpected browser fixture Org.');}"
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + "$owner=App\\Models\\User::withoutScopeBecause('Exact live admission fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail());"
        + "$editor=App\\Models\\User::withoutScopeBecause('Exact live admission fixture editor',fn($q)=>$q->where('email','copyeditor@kitsune.test')->firstOrFail());"
        + '$owner->orgs()->syncWithoutDetaching([$org->id]);$editor->orgs()->syncWithoutDetaching([$org->id]);'
        + "$role=Kitsune\\Core\\Models\\Role::query()->where('is_owner',true)->first()??Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
        + '$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate([\'slug\'=>' + literal(SITE) + "],['handle'=>'live-main','name'=>'Live checkout fixture','locale'=>'en','base_url'=>'/live-stripe-main']);"
        + '$owner->sites()->syncWithoutDetaching([$site->id]);$editor->sites()->syncWithoutDetaching([$site->id]);'
        + "DB::table('commerce_stripe_admissions')->where('org_id',$org->id)->delete();");
    setKey();
    php(context() + "Kitsune\\Core\\Credentials\\CredentialConsoleAuthority::run(app(Kitsune\\Core\\Tenancy\\Context::class),'set',Kitsune\\Commerce\\Payments\\StripeWebhook::SLOT,Kitsune\\Core\\Credentials\\CredentialMode::Live,null,"
        + "fn($proof)=>app(Kitsune\\Core\\Credentials\\CredentialWriter::class)->set(Kitsune\\Commerce\\Payments\\StripeWebhook::SLOT,Kitsune\\Core\\Credentials\\CredentialMode::Live,'whsec_'.str_repeat('h',32),console:$proof));"
        + "Kitsune\\Core\\Credentials\\CredentialConsoleAuthority::run(app(Kitsune\\Core\\Tenancy\\Context::class),'switch',null,Kitsune\\Core\\Credentials\\CredentialMode::Live,null,"
        + "fn($proof)=>app(Kitsune\\Core\\Credentials\\CredentialWriter::class)->switchTo(Kitsune\\Core\\Credentials\\CredentialMode::Live,console:$proof));");
}
function state() {
    return JSON.parse(php(context() + "echo json_encode(DB::table('commerce_stripe_admissions')->where('org_id',$org->id)->first());"));
}
async function action(page, name, password = 'password') {
    const form = page.locator('[data-live-admission] form');
    await form.getByLabel('Your Kitsune password', { exact: true }).fill(password);
    const submitted = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/live-admission'));
    await form.getByRole('button', { name, exact: true }).click();
    expect((await submitted).status()).toBe(303);
    await expect(page).toHaveURL(new URL(ORDERS, page.url()).href);
}

test.beforeEach(reset);

test('requires separate password-confirmed admission with actual server account verification', async ({ page }) => {
    await page.goto(ORDERS);
    await expect(page.getByText('New live checkouts have not been admitted.', { exact: true })).toBeVisible();
    expect(state()).toBeNull();
    await action(page, 'Verify account and admit live checkouts', 'incorrect');
    await expect(page.getByText('Admission could not be updated.', { exact: false })).toBeVisible();
    expect(state()).toBeNull();
    await action(page, 'Verify account and admit live checkouts');
    await expect(page.getByText('New live checkouts are admitted for the verified Stripe account.', { exact: true })).toBeVisible();
    await expect(page.getByText('acct_LiveBrowser', { exact: true })).toBeVisible();
    expect(state().state).toBe('admitted');
    expect(await page.locator('#live-admission-password').inputValue()).toBe('');
    await expect(page.locator('body')).not.toContainText('private-fixture@example.test');
    const axe = await new AxeBuilder({ page }).analyze();
    expect(axe.violations).toEqual([]);
    await page.goto(`/admin/${SITE}`);
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
});

test('blocks new live checkouts on revocation and demands a fresh password for readmission', async ({ page }) => {
    await page.goto(ORDERS);
    await action(page, 'Verify account and admit live checkouts');
    const version = state().version;
    await action(page, 'Block new live checkouts');
    await expect(page.getByText('New live checkouts are blocked.', { exact: false })).toBeVisible();
    expect(state().state).toBe('revoked');
    expect(state().version).not.toBe(version);
    await action(page, 'Verify account and admit live checkouts');
    expect(state().state).toBe('admitted');
});

test('shows stale admission after a live API key replacement and verifies again', async ({ page }) => {
    await page.goto(ORDERS);
    await action(page, 'Verify account and admit live checkouts');
    const old = state().credential_witness;
    setKey('c');
    await page.reload();
    await expect(page.getByText('Admission needs confirmation again.', { exact: false })).toBeVisible();
    await action(page, 'Verify account and admit live checkouts');
    expect(state().state).toBe('admitted');
    expect(state().credential_witness).not.toBe(old);
});

test('requires native body CSRF and rejects a replayed admission version', async ({ page }) => {
    await page.goto(ORDERS);
    const token = await page.locator('[data-live-admission] input[name="_token"]').inputValue();
    const version = await page.locator('[data-live-admission] input[name="version"]').inputValue();
    const url = await page.locator('[data-live-admission] form').getAttribute('action');
    const invalid = await page.request.post(url, { form: { action: 'revoke', version, current_password: 'password' } });
    expect(invalid.status()).toBe(419);
    expect(state()).toBeNull();
    await action(page, 'Verify account and admit live checkouts');
    const admitted = state().version;
    const stale = await page.request.post(url, { form: { _token: token, action: 'revoke', version, current_password: 'password' }, maxRedirects: 0 });
    expect(stale.status()).toBe(303);
    expect(state().version).toBe(admitted);
});

test('refuses an editor and a foreign owner at the direct admission URL', async ({ browser }) => {
    for (const file of ['.playwright/admin-reader-auth.json', '.playwright/admin-rival-auth.json']) {
        const context = await browser.newContext({ storageState: file });
        const page = await context.newPage();
        const response = await page.goto(ORDERS);
        expect([403, 404]).toContain(response.status());
        await context.close();
    }
    expect(state()).toBeNull();
});
