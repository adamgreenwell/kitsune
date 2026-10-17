# Operating the commerce module

Commerce is optional and starts disabled after installation. Its admin screens select the org's currency and manage
products; a host can opt into its public catalogue and reader access badges. Prices and stock belong to the org,
while each site's listing connects its own content. See [ADR-040](decision-log.md#adr-040--commerce-is-a-module-entitlements-are-a-kernel-guard-and-v10-waits-for-both)
and [currency maintenance](commerce-currencies.md). Credential changes, passwordless server recovery and application-key
rotation are covered in [credential operations](credential-operations.md).

## Changing module state

Run these commands from the installed application's directory, using the same PHP version and environment as the
application. Arrange the change outside a deployment or another route-cache build: a concurrent `route:cache` could
publish routes for the state being replaced.

```sh
php artisan kitsune:module status
php artisan kitsune:module install kitsune/commerce
php artisan kitsune:module enable kitsune/commerce
```

Enable, disable and upgrade clear the application's configured route-cache file before changing their receipt.
Deletion is checked and read back. A failure refuses the receipt change; fix access to the cache directory and retry
the same command. Repeating an already completed enable, disable or upgrade also clears stale cached routes.
An upgrade may already have applied schema migrations before cache clearing fails; its unchanged receipt continues
to require an upgrade, so repair the filesystem problem and retry rather than editing the receipt.

For a development checkout still reporting the same `dev-main` version, the existing same-version migration
upgrade gap is tracked in [#269](https://github.com/adamgreenwell/kitsune/issues/269). Do not uninstall/reinstall to
force these migrations when data exists. New-version upgrades and fresh installations use the module lifecycle;
a same-version receipt is not evidence that newly added order tables have been applied.

Routes reflect the new state on the next application boot. A process that has already booted keeps its providers
and route collection; restart persistent application workers through the host's normal procedure. On a fresh owner
session, check the dashboard and roles page as well as Products and the currency chooser. A disabled module contributes
no commerce resources. Old cached admin and catalogue URIs are refused unless the module is enabled,
version-matched and registered.

If the host uses route caching, rebuild it after the switch has completed:

```sh
php artisan route:cache
```

The cache command boots a fresh application to build routes for the new state. A later database failure after cache
deletion can leave the cache absent with the old receipt intact; a fresh application then generates the old state's
routes normally. Rebuild only after resolving the failed operation.

## Selecting currency and keeping catalogue data

An owner selects the org's supported currency at **Storefront → Catalogue currency**. The selection applies to every
site in the org. There is no default, and changing a selection never converts prices. Existing sellables and orders prevent
changing or clearing it, including while commerce is disabled. Remove their site listings, sales policies, sellables and retained orders explicitly
before selecting another currency. Order snapshots retain their currency witness independently of the catalogue. Currency metadata support does not promise payment-provider eligibility.

## Setting up Storefront

Open **Storefront → Products** as an org owner. Choose **Set up Storefront** to apply the module's registered
blueprint, then **Catalogue currency** to select a supported currency. The same blueprint can be applied from the CLI:

```sh
php artisan kitsune:blueprint apply storefront --org=<org-slug>
```

The blueprint creates ordinary org-owned **Product content** and **Product images** types, an optional rich-text
description and an optional image relation. Title and slug use the entry's native columns. It creates no roles or
permissions: owners already have authority, and ordinary content grants can be assigned through the existing roles
screen. Product images initially accept JPEG, PNG, GIF, WebP, AVIF and SVG; SVG still requires the installed sanitizer.
The org may adapt its types, fields and allowed formats. The Storefront receipt identifies its product type even
after an owner renames the handle. The product type must be enabled for the current site before creating or editing
a product there.

Applying again, merging a later declaration and reversing use the ordinary blueprint receipt and refusal rules.
A handle collision is a refusal, not permission to overwrite an existing type. Repair an interrupted blueprint
operation through its existing recovery workflow before retrying setup.

## Managing a product across sites

**New Product** saves one shared SKU, exact price and stock balance alongside the current site's native entry and
listing. The form names the currency it opened with. If the org's currency changes before the first save, that old
form refuses rather than relabelling the amount; reload and enter the price in the newly displayed currency.
Finite stock accepts a nonnegative whole number, including zero; untracked stock has no quantity limit.

The form warns that shared changes affect every site selling the product. Select a shared product on a
second site's Products screen to add that site's own content and listing. Its description, image selection and
availability are independent; editing its price or stock still changes the one shared record. **Content and history**
opens the ordinary entry editor and revisions. Editors with ordinary content grants can use that editor, while
Products and its commerce writes remain owner-only.

Availability starts off. An unavailable draft may leave adapted required content fields incomplete; making a
listing available requires published, complete content. One save commits the commerce rows, content, relations,
revision and audit records together. A refused save reports failure without a partial product or success redirect.
Selecting an image does not publish its bytes or change its privacy; existing media custody and delivery rules apply.
Availability alone does not install a host route or provide a checkout.

A host may wrap a product save in its own transaction. The save uses current ownership, schema, field, relation
and revision witnesses under its Org/Site locks, including an older MySQL/MariaDB snapshot. Ordinary catalogue
reads keep their consistent-read behavior. A schema, parent or authority change during the save refuses the entire
operation; repair the configuration or reload before retrying. Product edits preserve current stock, whose
reasoned adjustments remain separate. See ADR-040 and #291 for the measured regression and qualification.

Shared products can also offer [permanent or whole-day purchased access](purchased-access.md). Choose explicit
terms when setting an entitlement name. Existing named products with unknown terms cannot be purchased until
configured; existing orders keep their saved terms and any historical unknown terms stay on hold for review.

**Access entitlement** is optional and shared by every site selling the product. Enter a canonical name such as
`course.surf-101`; it is never inferred from the SKU, title or slug. The current reader's access is checked separately
on each site, so holding that name on one brand does not give its badge on another. Setting or changing this field
grants nothing, and clearing it hides the badge without revoking existing grants. Grant duration, fixed-term passes
and repeat purchases remain later order decisions. Use the existing owner entitlement workflow to grant or revoke
access independently of this metadata.

## Placing and reading the public catalogue

The host delegates the catalogue explicitly in its `routes/web.php`, before its catch-all:

```php
\Kitsune\Commerce\Catalogue\CatalogueRoutes::register();
```

The stock skeleton already calls this only when the optional package is present. A different host using a
core-only installation should add the call when it adds commerce, or guard it with `class_exists()` in the same
way. This first-party placement call is the fourth named exception in [CONTRIBUTING](../CONTRIBUTING.md) to the
pre-v1.2 public API prohibition; it offers no third-party extension contract or stability promise in 0.x.

A root site serves `/catalogue`; a site configured at `https://example.test/news/fr` serves
`https://example.test/news/fr/catalogue`. Its admin slug does not choose this address. Ordinary host routes and
panel routes retain precedence. The catalogue also refuses panel URL space, a mismatched prefix, a disabled product
type, an unfinished Storefront receipt or a module that is disabled, missing or version-mismatched. Rebuild route
caches and restart persistent workers after module changes as described above.

Guests may browse while reader accounts are off. When reader accounts are enabled, **Your account** links to the
site's existing account pages; a signed-in reader sees **You have access** only where a configured entitlement is
currently held on this site. That badge makes no promise about buying again. A product with no mapping has no badge.

Cards require an explicitly available listing and published, undeleted, complete current-site product content.
They show the title, up to 240 characters of plain description, the exact currency amount, stock state, and at most
one eligible public image in the author's order. A private image stays private; listing a product never publishes
its bytes. Missing or disabled image targets, files awaiting publication on a private disk, and fields classified
as personal are not shown. Additional adapted fields do not become public card data automatically.

Pages address 24 candidate slots, ordered by shared SKU and listing ID, with Previous/Next links and page numbers
from 1 through 10,000. Later schema edits can make a candidate incomplete and leave a page sparse or empty; the
page says so and keeps its navigation instead of scanning without a bound to fill it. There is no search, product
detail route, theme system, cart or checkout in this surface.

Every catalogue response, including a guest's page and an error, is `private, no-store` because access badges can
vary by reader. An unavailable catalogue or entitlement database produces a generic 503, never a false no-access
answer or an invitation to pay again. The operator log records SQLSTATE alone, without reader identifiers, grant
sources or product entitlement names.

## Removing listings and reversing the blueprint

**Remove this site's listing** leaves the site's content, images, history and the shared product intact. The Products
list offers this action even if the product content type is disabled, so retained listings can still be cleared.
**Remove product** refuses until its listings on every site have been removed; it then removes only the shared
commerce record. Content and revisions remain for an owner to keep or remove through their ordinary workflows.

Reversing Storefront removes only unused blueprint configuration under core's existing rules. Retained entries
(including trash), image files and field or type references can refuse reversal or retain configuration; follow the
command's refusal and retention report. It does not remove listings, sellables or the org's currency. Emptying
commerce records does not erase content, and reversing empty content configuration does not erase an orphan shared
product. Products remains accessible for owner cleanup without a usable product type. Retained shared products
still freeze currency and prevent commerce uninstall.

Renaming a recorded type is supported for product editing, but changes blueprint reversal's trust boundary.
Core then reports a **receipt-only** reverse: it clears the Storefront receipt and keeps the schema and data.
Product editing becomes unavailable without that finished receipt, and retrying setup may refuse a retained
`product_image` handle. Review the reverse outcome and reconcile the retained configuration before applying again;
the setup action does not adopt or overwrite it silently.

Disable preserves schema, content and catalogue records:

```sh
php artisan kitsune:module disable kitsune/commerce
```

Uninstall requires a disabled module and refuses while any module-owned table retains a row. Resolve retained data
through its owning workflow, take the host's normal backup, and stop concurrent catalogue writers before teardown.
Site content and the org's currency selection remain after an empty commerce uninstall. The lifecycle and its
partial-DDL recovery limits are recorded in [ADR-038](decision-log.md#adr-038--a-module-is-a-composer-package-core-keeps-a-receipt-for-and-the-kernel-is-a-record-and-a-refusal).


## Site carts and reader custody

The host may independently place `\Kitsune\Commerce\Carts\CartRoutes::register()` before its catch-all in
`routes/web.php`. The stock skeleton opts in when commerce is present. With both host surfaces placed, catalogue
cards offer **Add to cart** and each site has its own `/cart` page under its configured prefix. Cart pages remain
available to remove unavailable items even when Storefront's content type has been disabled.

Carts hold up to 20 product lines with quantities 1–99. **Add** keeps the higher existing/requested quantity;
quantity edits replace the value and refuse a stale browser revision. Cart contents reserve no stock and the
shown current price can change before checkout. Deleted, unpublished, unlisted or otherwise invalid listings
stay removable as unavailable items. Payments arrive in a later slice.

Anonymous carts are bound to an opaque browser secret, cached in the server session and recoverable through an
encrypted HttpOnly cookie for 30 days. Authentication sessions keep their normal shorter lifetime; losing one
does not by itself lose the cart. Reading a cart can refresh its recovery cookie, but never its database expiry. Reader sign-in adopts only the anonymous cart of
that same site, combines distinct products and keeps the higher duplicate quantity. An oversized merge keeps
both carts; remove items from the saved cart and revisit the page to retry. Sign-out retains the saved reader
cart and starts a fresh anonymous cart. Cart reads and unchanged retries do not extend the 30-day expiry.

Expired carts stop being usable at their UTC expiry; physical cleanup is separate. With commerce enabled, run
`php artisan kitsune:cart-prune --limit=100` from the application's directory. Each invocation removes at most
the chosen number of expired carts (maximum 1,000), rechecking expiry under the same write locks as mutations.
Run again for another bounded batch. Each cart is removed atomically; if a later cart refuses, earlier completed
removals remain and the command reports that boundary. No worker, external service or automatic schedule is required. Disabling
commerce retains the rows; re-enable it before using this maintenance command. Uninstall and migration rollback
refuse retained cart data until its owning cleanup/erasure workflow resolves it.

The existing reader export includes saved carts, including expired rows not yet pruned. Reader erasure removes
those carts and their lines before removing the account. These privacy operations run while commerce is disabled
or its receipt version is stale, without booting its provider. Restore a missing installed module package and
repair unreadable retained schema before retrying a refused export/erasure; a partial answer is never reported
as complete. Anonymous carts have no reader identity to export by reader key and leave through expiry cleanup.


## Configure and prepare orders

As an org owner, open **Storefront → Checkout settings**. Select whether catalogue prices include tax, and separately
whether Shippo's quoted fee is treated as including tax. Configure explicit destination rates for each product/shipping
tax class. Rates accept percentages with up to four decimal places; region codes take precedence over a country's
`*` fallback. No configured rate means unavailable, rather than zero. Kitsune applies one noncompound rate, rounding
half up once per complete line. These are operator rules, not a legal tax service or jurisdiction registry.

Assign a tax class and digital/physical policy to every shared product. Digital lines use the billing destination.
Physical lines and shipping use delivery, which defaults to billing but can be supplied separately. For physical
products, supply a dispatch name/address, packaged dimensions, weight per unit, packaging tare and units per parcel.
The calculator splits each SKU by that capacity, never combines different SKUs, and requests at most twenty parcels.
Remove a sales policy explicitly before deleting its shared product. Existing orders retain their independent history.

Set the **Shippo API token** on the existing Credentials page for the active test/live mode. The first quote client
supports domestic shipments in the org currency. It creates shipment/rate requests only; it purchases no labels and
performs no currency conversion. International customs support is tracked in [#271](https://github.com/adamgreenwell/kitsune/issues/271).
Tests use provider fixtures. A passing test or a saved key is not live carrier qualification or deployment evidence.

The host's existing `CartRoutes::register()` delegation includes `/cart/checkout` and `/cart/orders`, including each
site's path prefix. Public orders require the enabled, current module and a resolvable public site. Checkout collects
only contact email, billing name/address and optional delivery name/address. A quote is provisional for fifteen minutes;
it is stored in the server session, bounded to five quotes. Shipping I/O holds no database locks. Changed prices,
stock, policies, tax, mode or credentials require a fresh quote before an order can be prepared.

**Storefront → Orders** shows immutable prepared/cancelled order snapshots for the selected site. No payment has been
taken, no stock reserved and no entitlement granted. The buyer can cancel, download or erase an unpaid order; an owner
can inspect, cancel or erase through the guarded admin surface. Repeating the same submission returns its original order,
and a second prepared order for the same cart revision is refused until the first is cancelled. Cart pruning or later
catalogue changes do not rewrite the saved snapshot. Unknown access duration remains unknown.

## Guest recovery and order privacy

New order preparation requires working production email, including signed-in-reader orders. Guest recovery uses that mail; reader-owned orders remain available through sign-in. A `log`/`array` transport or placeholder sender refuses preparation;
local/testing environments may keep mail for fixtures. For guest orders, after the outermost commit, Kitsune schedules one email after
the response without a queue. A failed mail does not undo a committed order. The original browser can still access it
and request another link, subject to the shared mail/IP throttle. Resends replace the previous token.

A guest link lasts thirty minutes and works once. A scanner's GET consumes nothing: the secret is hashed into a bounded
session slot and the browser redirects to a clean URL; an explicit CSRF-protected confirmation grants thirty minutes
for that exact org, site and order. Never paste links into logs or support tickets. Configure proxy/access logs to redact
query strings on this link endpoint, as with core's reader links. The response uses private/no-store and no-referrer
headers. A sign-in or matching email never claims a guest order; signing out clears the session proof namespace.

Reader exports and account erasure include every retained reader-owned order across that org's sites, even with commerce
disabled or its receipt stale. Missing recorded storage, inconsistent children or unknown future financial states refuse
complete erasure rather than silently skipping rows. Guests download/erase through exact browser or email proof. Erasing
a prepared/cancelled order removes invoice/contact/delivery data, lines and links; these states have no financial retention
exemption. No statutory retention period is invented. The [paid-data privacy policy](paid-retention-and-privacy.md) controls financial custody, selected invoice survivors
and account unlinking for both original modes; no statutory period is supplied. Uninstall/rollback also refuse retained order/configuration
rows; export and explicit cleanup remain the operator's maintenance responsibility.

## Stripe checkout

Verified events have a [durable inbox and bounded replay](stripe-webhooks.md). Install the early request guard
before exposing the webhook URL. Full verified payloads expire after seven days; operators run bounded pruning
regularly for physical removal. Fresh-owner recovery can check an older purchase after a key or mode change using
a transient key for its original Stripe account and mode. Verified payments allocate shared stock and create
unavailable-unit refund obligations; see [paid orders](paid-orders.md).

Prepared orders offer [hosted card checkout](stripe-checkout.md), fresh buyer review, immutable accepted amounts,
durable retries, current server reconciliation and explicit expiry. Returning guests use exact order proof.
An unresolved attempt blocks cancellation and erasure until reconciliation; paid records follow their explicit
retention policy. Browser return alone never confirms captured payment.

Test mode is the default. Live keys and a mode switch do not admit sales: use the separate password-confirmed
owner action in [live admission](live-stripe-admission.md). Revocation stops new Session creation while existing
verified financial recovery continues. Provider and HTTPS qualification, real Org activation, deployment and
release are separate steps. Confirmed orders support [purchased access](purchased-access.md) and
[refund/dispute access handling](refunds-and-disputes.md). Owners refund original delivered units or attribute
external refunds without changing the invoice or automatically returning stock. Zero-total completion and
three-decimal provider currencies remain deferred.

## Shared stock adjustments

Use **Storefront → Stock** to adjust an existing product, including a change between finite and untracked stock.
A reason is required, the displayed prior balance must still match, and history records the actor and before/after
balance. Owners can adjust the org's products; members with view and update grants on the current Site's Storefront
product type can adjust its coherent listings. The shared balance affects all selling sites. This grants no access
to price, currency, credentials, checkout settings or orders. Ordinary product edits preserve existing stock; initial
product creation still accepts its opening balance. Historical ledger rows survive product removal.
