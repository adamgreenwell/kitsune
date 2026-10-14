# Operating the commerce module

Commerce is optional and starts disabled after installation. Its admin screens select the org's currency and manage
products; public catalogue pages follow in #192. Prices and stock belong to the org, while each site's listing
connects its own content. See [ADR-040](decision-log.md#adr-040--commerce-is-a-module-entitlements-are-a-kernel-guard-and-v10-waits-for-both)
and [currency maintenance](commerce-currencies.md).

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

Routes reflect the new state on the next application boot. A process that has already booted keeps its providers
and route collection; restart persistent application workers through the host's normal procedure. On a fresh owner
session, check the dashboard and roles page as well as Products and the currency chooser. A disabled module contributes
neither commerce resource, and old cached commerce URIs are refused unless the module is enabled, version-matched and registered.

If the host uses route caching, rebuild it after the switch has completed:

```sh
php artisan route:cache
```

The cache command boots a fresh application to build routes for the new state. A later database failure after cache
deletion can leave the cache absent with the old receipt intact; a fresh application then generates the old state's
routes normally. Rebuild only after resolving the failed operation.

## Selecting currency and keeping catalogue data

An owner selects the org's supported currency at **Storefront → Catalogue currency**. The selection applies to every
site in the org. There is no default, and changing a selection never converts prices. Existing sellables prevent
changing or clearing it, including while commerce is disabled. Remove their site listings and sellables explicitly
before selecting another currency. Currency metadata support does not promise payment-provider eligibility.

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

The form warns that price and stock changes affect every site selling the product. Select a shared product on a
second site's Products screen to add that site's own content and listing. Its description, image selection and
availability are independent; editing its price or stock still changes the one shared record. **Content and history**
opens the ordinary entry editor and revisions. Editors with ordinary content grants can use that editor, while
Products and its commerce writes remain owner-only.

Availability starts off. An unavailable draft may leave adapted required content fields incomplete; making a
listing available requires published, complete content. One save commits the commerce rows, content, relations,
revision and audit records together. A refused save reports failure without a partial product or success redirect.
Selecting an image does not publish its bytes or change its privacy; existing media custody and delivery rules apply.
Availability alone does not create a public page in this slice.

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
