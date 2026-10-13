// @ts-check
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const SKELETON = path.join(__dirname, '..', 'skeleton');
const ORG = 'storefront-e2e';
const SITE_A = 'storefront-a';
const SITE_B = 'storefront-b';
const RIVAL_ORG = 'storefront-rival-e2e';
const RIVAL_SITE = 'storefront-rival';
const SETUP_SITE = 'storefront-setup';
const IMAGE_A = 'Brand A product image';
const IMAGE_B = 'Brand B product image';

/** PHP literals travel in an argv value, never through a shell. */
function literal(value) {
    return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function artisan(args) {
    return execFileSync('php', ['artisan', ...args], { cwd: SKELETON, encoding: 'utf8' });
}

function php(code) {
    return artisan(['tinker', `--execute=${code}`]).trim();
}

/** Explicit fixture-only bootstrap: a site is looked up before it can supply its org context. */
function atSite(site = SITE_A, actor = 'alpha@kitsune.test') {
    return `$site=Kitsune\\Core\\Models\\Site::query()->withoutGlobalScopes()->where('slug',${literal(site)})->firstOrFail();`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
        + `auth()->setUser(App\\Models\\User::query()->where('email',${literal(actor)})->firstOrFail());`;
}

/**
 * Dedicated orgs keep Storefront's adaptable product schema out of the older Blog/Golfdom fixtures.
 * Accounts are existing sessions; granting another site does not add another throttled sign-in.
 */
function seedStorefrontAdmin() {
    const definitions = [
        [ORG, 'Storefront browser fixture', [SITE_A, SITE_B], 'alpha@kitsune.test'],
        [RIVAL_ORG, 'Rival Storefront browser fixture', [RIVAL_SITE], 'rival@kitsune.test'],
        ['storefront-setup-e2e', 'Empty Storefront browser fixture', [SETUP_SITE], 'alpha@kitsune.test'],
    ];

    for (const [slug, name, sites, email] of definitions) {
        php(`$org=Kitsune\\Core\\Models\\Org::create(['slug'=>${literal(slug)},'name'=>${literal(name)}]);`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
            + `$owner=App\\Models\\User::query()->withoutGlobalScopes()->where('email',${literal(email)})->firstOrFail();`
            + '$owner->orgs()->syncWithoutDetaching([$org->id]);'
            + "$role=Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);"
            + '$role->assignTo($owner->id);'
            + `$slugs=[${sites.map(literal).join(',')}]; foreach($slugs as $slug) {`
            + "$site=Kitsune\\Core\\Models\\Site::create(['org_id'=>$org->id,'handle'=>$slug,'slug'=>$slug,'name'=>$slug,'locale'=>'en','base_url'=>'/'.$slug]);"
            + '$owner->sites()->syncWithoutDetaching([$site->id]); }');
    }

    for (const org of [ORG, RIVAL_ORG]) {
        const applied = artisan(['kitsune:blueprint', 'apply', 'storefront', `--org=${org}`, '--no-interaction']);
        if (! applied.includes(`Applied storefront 1.0.0 into ${org}.`)) {
            throw new Error(`Storefront fixture apply failed for ${org}:\n${applied}`);
        }
    }

    const again = artisan(['kitsune:blueprint', 'apply', 'storefront', `--org=${ORG}`, '--no-interaction']);
    if (! again.includes('already applied at this version')) {
        throw new Error(`Storefront fixture reapply was not a no-op:\n${again}`);
    }

    php(atSite() + "app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD');"
        + "$editor=App\\Models\\User::query()->withoutGlobalScopes()->where('email','copyeditor@kitsune.test')->firstOrFail();"
        + '$editor->orgs()->syncWithoutDetaching([$site->org_id]);'
        + `$ids=Kitsune\\Core\\Models\\Site::query()->whereIn('slug',[${literal(SITE_A)},${literal(SITE_B)}])->pluck('id')->all();`
        + '$editor->sites()->syncWithoutDetaching($ids);'
        + "$role=Kitsune\\Core\\Models\\Role::create(['handle'=>'product-content-editor','name'=>'Product content editor']);"
        + "foreach(['view','create','update'] as $action) {$role->grant(Kitsune\\Core\\Auth\\Permissions::forEntryType('product',$action));}"
        + '$role->assignTo($editor->id);');
    php(atSite(RIVAL_SITE, 'rival@kitsune.test') + "app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD');");

    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'kitsune-storefront-images-'));
    const source = path.join(directory, 'product.png');
    // A real PNG, through media intake and the private store; no image processor or external service is needed.
    fs.writeFileSync(source, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64'));
    try {
        php(atSite()
            + "$type=Kitsune\\Core\\Models\\EntryType::query()->where('org_id',$site->org_id)->where('handle','product_image')->firstOrFail();"
            + `foreach([${literal(IMAGE_A)},${literal(IMAGE_B)}] as $title) {`
            + `Kitsune\\Core\\Media\\MediaLibrary::store(${literal(source)},'product.png',$type,'private',$title);}`);
    } finally {
        fs.rmSync(directory, { recursive: true, force: true });
    }
}

/** Clear only this spec's products, through the same listing/sellable writers; retain the media fixtures. */
function resetStorefrontProducts() {
    for (const [org, actor] of [[ORG, 'alpha@kitsune.test'], [RIVAL_ORG, 'rival@kitsune.test']]) {
        php(`$org=Kitsune\\Core\\Models\\Org::query()->where('slug',${literal(org)})->firstOrFail();`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
            + 'auth()->forgetUser();'
            + '$writer=app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class);'
            + 'foreach(Kitsune\\Core\\Models\\Site::query()->get() as $site) {'
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
            + 'foreach(Kitsune\\Commerce\\Models\\Listing::query()->get() as $row) {$writer->removeListing($row->id);}}'
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
            + 'foreach(Kitsune\\Commerce\\Models\\Sellable::query()->get() as $row) {$writer->removeSellable($row->id);}'
            + "foreach(Kitsune\\Core\\Models\\Entry::query()->withoutGlobalScopes()->withTrashed()->where('org_id',$org->id)->where('type_handle','product')->get() as $entry) {$entry->forceDelete();}"
            + `auth()->setUser(App\\Models\\User::query()->where('email',${literal(actor)})->firstOrFail());`
            + "app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD');");
    }
}

function setCurrency(currency) {
    php(atSite() + `app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set(${literal(currency)});`);
}

/** A retry starts before setup again; this org deliberately has no products, media or assigned blueprint roles. */
function resetStorefrontSetup() {
    const applied = php(atSite(SETUP_SITE) + "echo Kitsune\\Core\\Models\\Blueprint::query()->where('handle','storefront')->exists()?'yes':'no';");
    if (applied === 'yes') {
        artisan(['kitsune:blueprint', 'reverse', 'storefront', '--org=storefront-setup-e2e', '--no-interaction']);
    }
    php(atSite(SETUP_SITE) + 'app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set(null);');
}

function seedProduct(sku = 'E2E-COURSE', site = SITE_A, actor = 'alpha@kitsune.test') {
    return php(atSite(site, actor)
        + `echo (string) app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->createProduct(['sku'=>${literal(sku)},'price_minor'=>'2000','stock'=>null],'USD',`
        + "['title'=>'Existing course','slug'=>'existing-course','status'=>'draft','values'=>[]])->id;");
}

/** Read actual persisted state, including coherent revisions, rather than the form's local state. */
function storefrontState(org = ORG) {
    return JSON.parse(php(`$org=Kitsune\\Core\\Models\\Org::query()->where('slug',${literal(org)})->firstOrFail();`
        + '$entries=DB::table(\'entries\')->where(\'org_id\',$org->id)->where(\'type_handle\',\'product\')->orderBy(\'id\')->get();'
        + '$ids=$entries->pluck(\'id\')->all();'
        + "echo json_encode(['currency'=>$org->currency,"
        + "'sellables'=>DB::table('commerce_sellables')->where('org_id',$org->id)->orderBy('id')->get()->map(function($r){$r->id=(string)$r->id;$r->price_minor=(string)$r->price_minor;$r->stock=$r->stock===null?null:(string)$r->stock;return $r;}),"
        + "'listings'=>DB::table('commerce_listings')->where('org_id',$org->id)->orderBy('site_id')->get(),"
        + "'entries'=>$entries,'relations'=>DB::table('entry_relations')->whereIn('source_entry_id',$ids)->orderBy('source_entry_id')->orderBy('ordering')->get(),"
        + "'revisions'=>DB::table('entry_revisions')->whereIn('entry_id',$ids)->orderBy('id')->get(),"
        + "'media'=>DB::table('media_files')->join('entries','entries.id','=','media_files.entry_id')->where('entries.org_id',$org->id)->orderBy('entries.id')->get(['entries.id','entries.title','media_files.visibility']),"
        + "'audits'=>DB::table('audit_log')->where('org_id',$org->id)->where('action','like','commerce.%')->count()]);"));
}

function setProductTypeEnabled(enabled, site = SITE_A) {
    const state = JSON.parse(php(atSite(site)
        + "$type=Kitsune\\Core\\Models\\EntryType::query()->where('org_id',$site->org_id)->where('handle','product')->firstOrFail();"
        + `Kitsune\\Core\\Models\\EntryTypeAvailability::query()->updateOrCreate(['entry_type_id'=>$type->id,'scope_type'=>'site','scope_id'=>$site->id],['is_enabled'=>${enabled ? 'true' : 'false'}]);`
        + "echo json_encode(['enabled'=>Kitsune\\Core\\Models\\EntryTypeAvailability::isEnabledFor($type,$site),'type'=>(string)$type->id,'resolved'=>Kitsune\\Commerce\\Catalogue\\ProductType::current()?->id]);"));
    if (state.enabled !== enabled || (enabled ? String(state.resolved) !== state.type : state.resolved !== null)) {
        throw new Error(`Storefront fixture type availability did not resolve: ${JSON.stringify(state)}`);
    }
}

module.exports = {
    ORG, SITE_A, SITE_B, RIVAL_ORG, RIVAL_SITE, SETUP_SITE, IMAGE_A, IMAGE_B,
    artisan, php, literal, atSite, seedStorefrontAdmin, resetStorefrontProducts,
    setCurrency, resetStorefrontSetup, seedProduct, storefrontState, setProductTypeEnabled,
};
