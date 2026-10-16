// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const { php, literal } = require('./storefront-fixture');
const { callsMethod } = require('./select-control');

const ORG = 'retention-admin-e2e';
const SITE = 'retention-admin-main';
const SIBLING = 'retention-admin-other';
const RETENTION = '/admin/' + SITE + '/storefront/retention';

/** This disposable Org is owned by this spec; other Orgs' policy bytes are checked before and after reset. */
function resetFixture() {
    php("$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate(['slug'=>" + literal(ORG) + "],['name'=>'Retention browser fixture','currency'=>'USD']);"
        + "if($org->name!=='Retention browser fixture'){throw new RuntimeException('Unexpected fixture Org.');}"
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + "$owner=App\\Models\\User::withoutScopeBecause('Exact retention browser owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail());"
        + "$editor=App\\Models\\User::withoutScopeBecause('Exact retention browser editor',fn($q)=>$q->where('email','copyeditor@kitsune.test')->firstOrFail());"
        + '$owner->orgs()->syncWithoutDetaching([$org->id]);$editor->orgs()->syncWithoutDetaching([$org->id]);'
        + "$role=Kitsune\\Core\\Models\\Role::query()->where('is_owner',true)->first()??Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
        + 'foreach([' + literal(SITE) + ',' + literal(SIBLING) + '] as $slug){'
        + "$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate(['slug'=>$slug],['handle'=>$slug,'name'=>$slug,'locale'=>'en','base_url'=>'/'.$slug]);"
        + '$owner->sites()->syncWithoutDetaching([$site->id]);$editor->sites()->syncWithoutDetaching([$site->id]);}'
        + "$tables=['commerce_retention_policy_activations','commerce_retention_policies'];$other=[];foreach($tables as$table){$other[$table]=hash('sha256',json_encode(DB::table($table)->where('org_id','<>',$org->id)->orderBy('id')->get(),JSON_THROW_ON_ERROR));}"
        + "foreach($tables as$table){DB::table($table)->where('org_id',$org->id)->delete();}"
        + "foreach($tables as$table){if(hash('sha256',json_encode(DB::table($table)->where('org_id','<>',$org->id)->orderBy('id')->get(),JSON_THROW_ON_ERROR))!==$other[$table]){throw new RuntimeException('Foreign policy fixture changed.');}}");
}

function state() {
    return JSON.parse(php("$org=Kitsune\\Core\\Models\\Org::query()->where('slug'," + literal(ORG) + ')->firstOrFail();'
        + "echo json_encode(['versions'=>DB::table('commerce_retention_policies')->where('org_id',$org->id)->orderBy('version')->get(['version','clock','duration','timezone','billing_fields']),"
        + "'active'=>DB::table('commerce_retention_policy_activations')->where('org_id',$org->id)->value('policy_version')],JSON_THROW_ON_ERROR);"));
}

async function ready(page) {
    await page.waitForFunction(() => ['retention-legal_basis', 'retention-reason', 'retention-clock', 'retention-duration']
        .every((id) => typeof /** @type {any} */ (document.getElementById(id))._x_model?.set === 'function'));
}

async function action(page, method, label) {
    const button = page.getByRole('button', { name: label, exact: true }).first();
    await expect.poll(() => button.evaluate((element) => {
        const target = element.hasAttribute('wire:click') ? element : element.closest('form[wire\\:submit]');
        return Object.keys(/** @type {any} */ (target)?._x_attributeCleanups ?? {}).some((key) => key.startsWith('x-on:click') || key.startsWith('x-on:submit'));
    })).toBe(true);
    const reply = page.waitForResponse((response) => callsMethod(response, method));
    await button.click();
    expect((await reply).status()).toBe(200);
}

async function createPolicy(page, calendar = false) {
    await ready(page);
    await page.getByLabel('Billing name', { exact: true }).check();
    await page.getByLabel('Billing country', { exact: true }).check();
    await page.getByLabel('Retention obligation', { exact: true }).fill('Recorded browser invoice obligation');
    await page.getByLabel('Operator reason', { exact: true }).fill('Explicit browser operator choice');
    await page.getByLabel('Retention clock', { exact: true }).selectOption(calendar ? 'payment_year_end' : 'confirmation_days');
    if (calendar) {
        await expect(page.getByLabel('Calendar timezone', { exact: true })).toBeVisible();
        await expect.poll(() => page.getByLabel('Calendar timezone', { exact: true }).evaluate((element) => typeof /** @type {any} */ (element)._x_model?.set === 'function')).toBe(true);
        await page.getByLabel('Calendar timezone', { exact: true }).selectOption('America/New_York');
    }
    await page.getByLabel('Number of days or years', { exact: true }).fill(calendar ? '7' : '30');
    await action(page, 'createPolicy', 'Create policy version');
    await expect(page.getByText('Policy version created; activate it separately', { exact: true })).toBeVisible();
}

test.describe('Owner retention policy administration', () => {
    test.beforeEach(resetFixture);
    test.afterEach(resetFixture);

    test('opens outside content routes and activates an explicit immutable Org policy version', async ({ page }, testInfo) => {
        expect((await page.goto('/admin/' + SITE)).status()).toBe(200);
        await page.getByRole('link', { name: 'Data retention', exact: true }).first().click();
        await expect(page).toHaveURL(new RegExp(RETENTION + '$'));
        await expect(page.getByText('No retention policy is active for newly prepared orders.', { exact: true })).toBeVisible();
        await createPolicy(page, true);
        expect(state()).toEqual({ versions: [{ version: 1, clock: 'payment_year_end', duration: 7, timezone: 'America/New_York', billing_fields: '["name","address.country"]' }], active: null });
        page.once('dialog', (dialog) => dialog.accept());
        await action(page, 'activatePolicy', 'Activate for newly prepared orders');
        await expect(page.getByText('Policy activated for newly prepared orders', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Version 1 — Active for newly prepared orders', exact: true })).toBeVisible();
        await createPolicy(page);
        expect(state().active).toBe(1);
        await page.goto('/admin/' + SIBLING + '/storefront/retention');
        await expect(page.getByRole('heading', { name: 'Version 1 — Active for newly prepared orders', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Version 2 — Not active for newly prepared orders', exact: true })).toBeVisible();
        const audit = await new AxeBuilder({ page }).analyze();
        expect(audit.violations).toEqual([]);
        const screenshot = testInfo.outputPath('owner-retention-policy-versions.png');
        await page.screenshot({ path: screenshot, fullPage: true });
        await testInfo.attach('owner-retention-policy-versions', { path: screenshot, contentType: 'image/png' });
        await page.goto('/admin/' + SITE + '/roles');
        await expect(page.getByRole('link', { name: 'Data retention', exact: true }).first()).toBeVisible();
    });

    test('keeps retention controls out of the editor panel and refuses its direct route', async ({ browser }) => {
        const context = await browser.newContext({ storageState: path.join(__dirname, '..', '.playwright/admin-reader-auth.json') });
        try {
            const page = await context.newPage();
            expect((await page.goto('/admin/' + SITE)).status()).toBe(200);
            await expect(page.getByRole('link', { name: 'Data retention', exact: true })).toHaveCount(0);
            expect((await page.goto(RETENTION)).status()).toBe(403);
            expect(state()).toEqual({ versions: [], active: null });
        } finally {
            await context.close();
        }
    });
});
