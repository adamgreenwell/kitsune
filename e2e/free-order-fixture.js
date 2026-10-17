// @ts-check
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { php, literal } = require('./storefront-fixture');
const SITE = 'free-orders-main';
const ORG = 'free-orders-e2e';
const CART = `/${SITE}/cart`;

function context() {
    return `$org=Kitsune\\Core\\Models\\Org::query()->where('slug',${literal(ORG)})->firstOrFail();`
        + "if($org->name!=='Free orders browser fixture'){throw new RuntimeException('Unexpected free-order fixture Org.');}"
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + `$site=Kitsune\\Core\\Models\\Site::query()->where('slug',${literal(SITE)})->firstOrFail();`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);';
}

function seed() {
    php(`$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate(['slug'=>${literal(ORG)}],['name'=>'Free orders browser fixture','currency'=>'USD','settings'=>['reader_accounts'=>'sign-in']]);`
        + "if($org->name!=='Free orders browser fixture'){throw new RuntimeException('Unexpected free-order fixture Org.');}"
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
        + "$owner=App\\Models\\User::withoutScopeBecause('Exact free-order fixture owner',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail());"
        + '$owner->orgs()->syncWithoutDetaching([$org->id]);'
        + "$role=Kitsune\\Core\\Models\\Role::query()->where('is_owner',true)->first()??Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
        + `$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate(['slug'=>${literal(SITE)}],['handle'=>'free-main','name'=>'Free orders','locale'=>'en','base_url'=>'/${SITE}']);`
        + '$owner->sites()->syncWithoutDetaching([$site->id]);app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->setUser($owner);'
        + 'Kitsune\\Core\\Blueprints\\BlueprintApplier::apply(new Kitsune\\Commerce\\Blueprints\\StorefrontBlueprint);');
    clear();
    php(context()
        + "$owner=App\\Models\\User::query()->where('email','alpha@kitsune.test')->firstOrFail();auth()->setUser($owner);"
        + "$product=Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','FREE-BROWSER')->first();if($product===null){"
        + "$product=app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->createProduct(['sku'=>'FREE-BROWSER','price_minor'=>'0','stock'=>'5','entitlement_name'=>'course.free-browser','offer_terms'=>['version'=>1,'kind'=>'fixed','days'=>30]],'USD',['title'=>'Free browser course','slug'=>'free-browser-course','status'=>'published','values'=>[]],[],true);}"
        + "DB::table('commerce_sellables')->where('org_id',$org->id)->where('id',$product->id)->update(['price_minor'=>0,'stock'=>5]);"
        + "$setup=app(Kitsune\\Commerce\\Checkout\\CommerceSetupWriter::class);$setup->setSettings(['tax_mode'=>'exclusive','shipping_tax_mode'=>'exclusive','shipping_tax_class'=>'shipping']);"
        + "$setup->saveTaxRate(null,['country'=>'US','region'=>'*','tax_class'=>'standard','rate_parts'=>100000]);"
        + "$setup->setSalesPolicy($product->id,['tax_class'=>'standard','delivery_kind'=>'digital']);"
        + "$policies=app(Kitsune\\Commerce\\Privacy\\RetentionPolicies::class);if($policies->current(DB::connection(),$org->id)===null){$policy=$policies->createAsOwner(['name'],'Fixture retention purpose.','Free browser qualification.','confirmation_days',30);$policies->activateAsOwner($policy->version);}");
}

function clear() {
    php(context()
        + "DB::table('entitlements')->where('org_id',$org->id)->delete();"
        + "foreach(['commerce_free_access_corrections','commerce_free_access_work','commerce_free_completions','commerce_privacy_holds','commerce_privacy_deliveries','commerce_privacy_transitions','commerce_order_privacy','commerce_access_adjustment_effects','commerce_access_adjustment_work','commerce_financial_observations','commerce_financial_refunds','commerce_financial_work','commerce_access_effects','commerce_access_work','commerce_order_claims','commerce_order_access_tokens','commerce_order_allocations','commerce_order_lines','commerce_orders','commerce_carts','commerce_tax_rates','commerce_sales_policies','commerce_settings'] as $table){DB::table($table)->where('org_id',$org->id)->delete();}");
}

function stock(quantity) {
    php(context() + `DB::table('commerce_sellables')->where('org_id',$org->id)->where('sku','FREE-BROWSER')->update(['stock'=>${Number(quantity)}]);`);
}

function facts() {
    return JSON.parse(php(context() + "echo json_encode(['order'=>DB::table('commerce_orders')->where('org_id',$org->id)->latest('id')->first(['reference','state','confirmed_total_minor']),"
        + "'completions'=>DB::table('commerce_free_completions')->where('org_id',$org->id)->count(),'payments'=>DB::table('commerce_payment_receipts')->where('org_id',$org->id)->count(),'attempts'=>DB::table('commerce_checkout_attempts')->where('org_id',$org->id)->count(),"
        + "'stock'=>(string)DB::table('commerce_sellables')->where('org_id',$org->id)->where('sku','FREE-BROWSER')->value('stock'),'corrections'=>DB::table('commerce_free_access_corrections')->where('org_id',$org->id)->count(),"
        + "'allocation'=>DB::table('commerce_order_allocations')->where('org_id',$org->id)->first(['awarded','unavailable'])]);"));
}

async function prepare(page, quantity = 1) {
    await page.goto(`/${SITE}/catalogue`);
    await page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Free browser course', exact: true }) })
        .getByRole('button', { name: 'Add to cart', exact: true }).click();
    if (quantity !== 1) {
        await page.getByRole('spinbutton', { name: 'Quantity for Free browser course', exact: true }).fill(String(quantity));
        await page.getByRole('button', { name: 'Update quantity', exact: true }).click();
    }
    await page.getByRole('link', { name: 'Prepare an order', exact: true }).click();
    await page.getByLabel('Email for order access', { exact: true }).fill('free-browser-buyer@example.test');
    const billing = page.getByRole('group', { name: 'Billing details', exact: true });
    for (const [label, value] of Object.entries({ Name: 'Free Browser Buyer', 'Address line 1': '10 Free Street', City: 'Honolulu', 'State or region code': 'HI', 'Postal code': '96801', 'Country code (two uppercase letters)': 'US' })) {
        await billing.getByLabel(label, { exact: true }).fill(value);
    }
    await page.getByRole('button', { name: 'Review tax and delivery', exact: true }).click();
    await page.getByRole('button', { name: 'Prepare order', exact: true }).click();
    const path = new URL(page.url()).pathname;
    await page.getByRole('link', { name: 'Checkout', exact: true }).click();
    await page.getByRole('button', { name: 'Review checkout', exact: true }).click();
    return path;
}

module.exports = { SITE, CART, context, seed, clear, stock, facts, prepare };
