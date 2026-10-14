// @ts-check
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { artisan, php, literal } = require('./storefront-fixture');

const ORG = 'catalogue-e2e';
const SITE_A = 'catalogue-a';
const SITE_B = 'catalogue-b';
const EMPTY_SITE = 'catalogue-empty';
const RTL_SITE = 'catalogue-ar';
const NESTED_SITE = 'catalogue-nested';
const NESTED_PREFIX = 'catalogue/news/fr';
const RIVAL_ORG = 'catalogue-rival-e2e';
const RIVAL_SITE = 'catalogue-rival';
const ENTITLEMENT = 'course.catalogue';
const COURSE_A = 'Coastal course';
const COURSE_B = 'Mountain masterclass';
const PRIVATE_PRODUCT = 'Private image course';
const RIVAL_PRODUCT = 'Rival course';
const READER = { email: 'catalogue-reader@kitsune.test', password: 'catalogue-reader-e2e-passphrase' };
const RIVAL_READER = { email: READER.email, password: 'catalogue-rival-e2e-passphrase' };

/** An explicit fixture bootstrap before a site can supply its org context; no staff or reader session. */
function atCatalogueSite(site = SITE_A) {
    return `$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('a public catalogue browser fixture',fn($q)=>$q->where('slug',${literal(site)})->firstOrFail());`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);'
        + 'auth()->forgetUser();';
}

/** Dedicated organisations keep catalogue rows and reader grants out of every older spec's counts. */
function seedPublicCatalogue() {
    for (const [slug, sites, password] of [
        [ORG, [SITE_A, SITE_B, EMPTY_SITE, RTL_SITE, NESTED_SITE], READER.password],
        [RIVAL_ORG, [RIVAL_SITE], RIVAL_READER.password],
    ]) {
        php(`$org=Kitsune\\Core\\Models\\Org::create(['slug'=>${literal(slug)},'name'=>${literal(slug)},'settings'=>['reader_accounts'=>'sign-in']]);`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
            + `foreach([${sites.map(literal).join(',')}] as $slug){`
            + `$locale=$slug===${literal(RTL_SITE)}?'ar':'en';`
            + `$prefix=$slug===${literal(NESTED_SITE)}?${literal(NESTED_PREFIX)}:$slug;`
            + "Kitsune\\Core\\Models\\Site::create(['handle'=>$slug,'slug'=>$slug,'name'=>$slug,'locale'=>$locale,'base_url'=>'/'.$prefix]);}"
            + `App\\Models\\Reader::createReader(${literal(READER.email)},Illuminate\\Support\\Facades\\Hash::make(${literal(password)}),now());`);
        if (slug === ORG) {
            php(`$org=Kitsune\\Core\\Models\\Org::query()->where('slug',${literal(ORG)})->firstOrFail();`
                + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);'
                + "$owner=App\\Models\\User::withoutScopeBecause('the saved browser owner joins this fixture org',fn($q)=>$q->where('email','alpha@kitsune.test')->firstOrFail());"
                + '$owner->orgs()->syncWithoutDetaching([$org->id]);'
                + "$role=Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);$role->assignTo($owner->id);"
                + '$owner->sites()->syncWithoutDetaching(Kitsune\\Core\\Models\\Site::query()->pluck(\'id\')->all());');
        }
        const applied = artisan(['kitsune:blueprint', 'apply', 'storefront', `--org=${slug}`, '--no-interaction']);
        if (! applied.includes(`Applied storefront 1.0.0 into ${slug}.`)) {
            throw new Error(`Public catalogue fixture apply failed for ${slug}:\n${applied}`);
        }
    }

    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'kitsune-catalogue-images-'));
    const source = path.join(directory, 'catalogue.png');
    fs.writeFileSync(source, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64'));
    try {
        php(atCatalogueSite()
            + "app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD');"
            + "$type=Kitsune\\Core\\Models\\EntryType::query()->where('org_id',$site->org_id)->where('handle','product_image')->firstOrFail();"
            + `foreach(['Coastal public image','Mountain public image','Unpublished private image'] as $title){`
            + `$image=Kitsune\\Core\\Media\\MediaLibrary::store(${literal(source)},'catalogue.png',$type,'private',$title);`
            + "if($title!=='Unpublished private image'){Kitsune\\Core\\Media\\MediaVisibility::makePublic($image);}}"
            + '$images=Kitsune\\Core\\Models\\Entry::query()->where(\'entry_type_id\',$type->id)->pluck(\'id\',\'title\');'
            + '$writer=app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class);'
            + `$shared=$writer->createProduct(['sku'=>'CAT-000','price_minor'=>'2000','stock'=>'12','entitlement_name'=>${literal(ENTITLEMENT)}],'USD',`
            + `['title'=>${literal(COURSE_A)},'slug'=>'coastal-course','status'=>'published','values'=>['product_description'=>'<p>Learn beside the sea.</p>']],`
            + "['product_images'=>[$images['Coastal public image']]],true);"
            + `$writer->createProduct(['sku'=>'CAT-001','price_minor'=>'1250','stock'=>null],'USD',['title'=>${literal(PRIVATE_PRODUCT)},'slug'=>'private-image-course','status'=>'published','values'=>[]],`
            + "['product_images'=>[$images['Unpublished private image']]],true);"
            + "for($i=0;$i<24;$i++){$writer->createProduct(['sku'=>sprintf('CAT-%03d',$i+10),'price_minor'=>'3500','stock'=>$i===23?'0':null],'USD',"
            + "['title'=>sprintf('Catalogue item %02d',$i+1),'slug'=>sprintf('catalogue-item-%02d',$i+1),'status'=>'published','values'=>[]],[],true);}"
            + "foreach(['unavailable','draft','trashed'] as $state){$row=$writer->createProduct(['sku'=>'CAT-900-'.strtoupper($state),'price_minor'=>'9900','stock'=>null],'USD',"
            + "['title'=>'Hidden '.$state.' course','slug'=>'hidden-'.$state,'status'=>'published','values'=>[]],[],$state!=='unavailable');"
            + "$entry=$row->listings()->firstOrFail()->entry()->firstOrFail();if($state==='draft'){$entry->status='draft';$entry->save();}if($state==='trashed'){$entry->delete();}}"
            + `$sibling=Kitsune\\Core\\Models\\Site::query()->where('slug',${literal(SITE_B)})->firstOrFail();`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($sibling);'
            + `$writer->updateProduct($shared->id,['sku'=>'CAT-000','price_minor'=>'2000','stock'=>'12'],'USD',['title'=>${literal(COURSE_B)},'slug'=>'mountain-masterclass','status'=>'published','values'=>['product_description'=>'<p>Learn in the mountains.</p>']],`
            + "['product_images'=>[$images['Mountain public image']]],true);"
            + `$rtl=Kitsune\\Core\\Models\\Site::query()->where('slug',${literal(RTL_SITE)})->firstOrFail();`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($rtl);'
            + "$writer->updateProduct($shared->id,['sku'=>'CAT-000','price_minor'=>'2000','stock'=>'12'],'USD',['title'=>'درس ساحلي','slug'=>'coastal-ar','status'=>'published','values'=>['product_description'=>'<p>تعلّم بجانب البحر.</p>']],['product_images'=>[$images['Coastal public image']]],true);"
            + `$nested=Kitsune\\Core\\Models\\Site::query()->where('slug',${literal(NESTED_SITE)})->firstOrFail();`
            + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($nested);'
            + "$writer->updateProduct($shared->id,['sku'=>'CAT-000','price_minor'=>'2000','stock'=>'12'],'USD',['title'=>'Nested course','slug'=>'nested-course','status'=>'published','values'=>[]],[],true);");
        php(atCatalogueSite(RIVAL_SITE)
            + "app(Kitsune\\Commerce\\Catalogue\\OrgCurrency::class)->set('USD');"
            + `app(Kitsune\\Commerce\\Catalogue\\ProductWriter::class)->createProduct(['sku'=>'CAT-000','price_minor'=>'2000','stock'=>null,'entitlement_name'=>${literal(ENTITLEMENT)}],'USD',`
            + `['title'=>${literal(RIVAL_PRODUCT)},'slug'=>'rival-course','status'=>'published','values'=>[]],[],true);`);
    } finally {
        fs.rmSync(directory, { recursive: true, force: true });
    }
    resetCatalogueEntitlements();
}

/** The browser never grants itself access: the fixture acts as a trusted producer, outside the request. */
function resetCatalogueEntitlements() {
    // `forget` erases this reader's grants across the whole org, so one site per org is enough.
    for (const site of [SITE_A, RIVAL_SITE]) {
        php(atCatalogueSite(site)
            + `$reader=App\\Models\\Reader::findByEmail(${literal(READER.email)});if($reader===null){throw new RuntimeException('The catalogue fixture reader is missing.');}`
            + 'app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->forget($reader->id);');
    }
    for (const source of ['e2e.catalogue:1', 'e2e.catalogue:2']) {
        grantCatalogueEntitlement(source);
    }
}

function grantCatalogueEntitlement(source, site = SITE_A, entitlement = ENTITLEMENT) {
    return php(atCatalogueSite(site)
        + `$reader=App\\Models\\Reader::findByEmail(${literal(READER.email)});`
        + `echo app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->grant($reader->id,${literal(entitlement)},${literal(source)},null)->name;`);
}

function catalogueCourseId() {
    return php(atCatalogueSite() + "echo (string) Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','CAT-000')->firstOrFail()->id;");
}

/** Restore metadata through its native write door even if the browser assertion fails. */
function setCatalogueEntitlementName(name) {
    php(atCatalogueSite()
        + "$row=Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','CAT-000')->firstOrFail();"
        + `app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->updateSellable($row->id,$row->sku,$row->price_minor,$row->stock,$row->currency,${name === null ? 'null' : literal(name)});`);
}

function revokeCatalogueEntitlement(source, site = SITE_A) {
    return php(atCatalogueSite(site)
        + `$reader=App\\Models\\Reader::findByEmail(${literal(READER.email)});`
        + `echo app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->revoke($reader->id,${literal(ENTITLEMENT)},${literal(source)})?'revoked':'unchanged';`);
}

function setCatalogueProductType(enabled, site = SITE_A) {
    php(atCatalogueSite(site)
        + "$type=Kitsune\\Core\\Models\\EntryType::query()->where('org_id',$site->org_id)->where('handle','product')->firstOrFail();"
        + `Kitsune\\Core\\Models\\EntryTypeAvailability::query()->updateOrCreate(['entry_type_id'=>$type->id,'scope_type'=>'site','scope_id'=>$site->id],['is_enabled'=>${enabled ? 'true' : 'false'}]);`);
}

function setCatalogueReaderMode(mode, site = SITE_A) {
    php(atCatalogueSite(site) + `$site->settings=['reader_accounts'=>${literal(mode)}];$site->save();`);
}

/** Only these fixtures' media paths, used to check rendered HTML discloses no private delivery URL. */
function catalogueMedia() {
    return JSON.parse(php(atCatalogueSite()
        + "echo json_encode(DB::table('media_files')->join('entries','entries.id','=','media_files.entry_id')->where('entries.org_id',$site->org_id)->get(['entries.title','media_files.visibility','media_files.path']));"));
}

module.exports = {
    SITE_A, SITE_B, EMPTY_SITE, RTL_SITE, NESTED_PREFIX, RIVAL_SITE, ENTITLEMENT,
    COURSE_A, COURSE_B, PRIVATE_PRODUCT, RIVAL_PRODUCT, READER, RIVAL_READER,
    seedPublicCatalogue, resetCatalogueEntitlements, grantCatalogueEntitlement,
    revokeCatalogueEntitlement, setCatalogueProductType, setCatalogueReaderMode,
    catalogueCourseId, setCatalogueEntitlementName, catalogueMedia, artisan, php,
};
