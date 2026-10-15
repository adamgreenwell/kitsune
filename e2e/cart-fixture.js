// @ts-check
const { php, literal } = require('./storefront-fixture');
const { SITE_A } = require('./public-catalogue-fixture');

const ORGS = ['catalogue-e2e', 'catalogue-rival-e2e'];

function atCartSite(site = SITE_A) {
    return `$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('a public cart browser fixture',fn($q)=>$q->where('slug',${literal(site)})->firstOrFail());`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();';
}

/**
 * Explicit fixture teardown below the model: every browser has its own opaque session owner, so there is deliberately
 * no production API to erase all of those carts. The exact two fixture orgs fence this reset; FK cascades remove lines.
 */
function resetCartFixtures() {
    php(`$ids=Kitsune\\Core\\Models\\Org::query()->whereIn('slug',[${ORGS.map(literal).join(',')}])->pluck('id')->all();`
        + "if(count($ids)!==2){throw new RuntimeException('The cart fixture orgs are missing.');}"
        + "DB::table('commerce_carts')->whereIn('org_id',$ids)->delete();");
    setCartCourse();
}

function setCartCourse({ price = '2000', stock = '12', available = true } = {}) {
    // Explicit fixture stock reset below the production write boundary. Manual runtime changes require staff,
    // a reviewed balance and a reason through StockWriter; this setup intentionally has no signed-in actor.
    php(atCartSite()
        + "$row=Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','CAT-000')->firstOrFail();"
        + `app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->updateSellable($row->id,$row->sku,${literal(price)},$row->stock,$row->currency);`
        + `DB::table('commerce_sellables')->where('org_id',$site->org_id)->where('id',$row->id)->update(['stock'=>${stock === null ? 'null' : `Kitsune\\Commerce\\Money\\MinorUnits::integer(${literal(stock)})`}]);`
        + '$listing=$row->listings()->firstOrFail();'
        + `app(Kitsune\\Commerce\\Catalogue\\CatalogueWriter::class)->setAvailability($listing->id,${available ? 'true' : 'false'});`);
}

/** Read persisted evidence without exposing the random guest secret or any saved reader identifier. */
function cartStorage() {
    return JSON.parse(php(atCartSite()
        + "echo json_encode(['stock'=>(string)Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','CAT-000')->value('stock'),"
        + "'carts'=>DB::table('commerce_carts')->where('org_id',$site->org_id)->get(['id','site_id','version','expires_at','created_at','updated_at']),"
        + "'lines'=>DB::table('commerce_cart_lines')->where('org_id',$site->org_id)->orderBy('id')->get(['cart_id','listing_id','quantity'])]);"));
}

function expireCartFixtures() {
    php(atCartSite() + "DB::table('commerce_carts')->where('org_id',$site->org_id)->update(['expires_at'=>now('UTC')->subMinute()->format('Y-m-d H:i:s')]);");
}

/** Cookie names are configuration, never credentials; browser tests retain no raw cookie value in logs. */
function cartCookieNames() {
    return JSON.parse(php("echo json_encode(['remember'=>Kitsune\\Core\\Readers\\ReaderSessions::EPHEMERAL,'session'=>config('session.cookie')]);"));
}

module.exports = { resetCartFixtures, setCartCourse, cartStorage, expireCartFixtures, cartCookieNames };
