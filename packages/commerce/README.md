# kitsune/commerce

The optional first-party commerce module. It supplies org-shared products, exact integer prices and finite or
untracked stock, per-site listings, a supported currency chooser, owner-only product administration with an
adaptable Storefront blueprint, and an optional public catalogue with reader access badges. Carts, orders and
Stripe payments remain later slices. ADR-040 in the monorepo records the design and delivery order.

The module and core resolve at the same version (`kitsune/core: self.version`). Composer installing the
package does not register its provider: only the kernel may do that, after an explicit installation and
enablement. There is no Laravel package auto-discovery entry.

For a development checkout, use the root's opt-in `composer skeleton:commerce` setup. The stock skeleton
does not require commerce. Once package publication is available, a host adds `kitsune/commerce` at the
same version as its core, then runs:

```sh
php artisan kitsune:module install kitsune/commerce
php artisan kitsune:module enable kitsune/commerce
php artisan kitsune:module status
```

Install leaves the module disabled. Enable takes effect when the next application boots. Disable preserves
data and schema; it does not unload a provider from requests or long-running processes already in flight.
Restart those processes after changing the switch.

The module lifecycle owns its migrations for `commerce_sellables` and `commerce_listings`; ordinary
`artisan migrate` does not run them. Core owns the module receipt and the org's currency configuration.
Sellables declare and enforce Org scope; listings declare and enforce Site scope, with reference-coherence
guards. The module manifest names both scoped models. A retained sellable prevents changing or clearing
the org's currency even while commerce is disabled.

Open **Storefront → Products** as an org owner, apply **Set up Storefront**, and select **Catalogue currency**.
One save writes the shared price and stock together with the current site's native content, relations and listing.
The form warns that shared changes affect every selling site. Storefront creates ordinary org-owned product
content and image types, without new roles or permission subjects. Content editors retain the ordinary entry
editor; commerce writes require an owner. Removal and blueprint reversal keep retained data under their existing
refusal rules. See [`docs/commerce-operations.md`](../../docs/commerce-operations.md) for the workflow and boundaries.

The optional **Access entitlement** field names one entitlement for the shared product. Its badge is checked for
the current reader on each site independently. Naming, changing or clearing it grants or revokes nothing and
does not choose access duration or repeat-purchase policy.

A host opts into the public catalogue by placing this call before its catch-all in `routes/web.php`:

```php
\Kitsune\Commerce\Catalogue\CatalogueRoutes::register();
```

The stock skeleton calls it only when the optional package is present. It delegates `/catalogue` under each
site's configured host and path prefix; installation or enablement alone claims no URL. Ordinary host and panel
routes retain precedence, and disabled, missing or stale module receipts refuse the page. This first-party
placement call is a named exception to the pre-v1.2 public API prohibition, without a third-party extension
contract or a stability promise in 0.x.

The module-owned page shows 24 candidate slots at a time, ordered by SKU and listing ID, with Previous/Next links.
Current schema checks can leave a page sparse rather than scan without a bound. Cards expose a bounded plain
description, exact price, stock state and at most one eligible public image; private image URLs and arbitrary
adapted fields stay out of the public projection. Reader badges use one bounded batch check. Responses are
`private, no-store`, and a database failure gives a generic 503. Product detail routes, general theming and
checkout are outside this read surface.

Commerce reserves table names beginning with `commerce_`. Uninstall inspects every such table on the
receipt's connection, past org/site context and soft deletion, and refuses if any row remains. This includes
future tables and retained invoice records. Read failures refuse too. It never exports or deletes records
on the operator's behalf. On MySQL/MariaDB servers that fold table names (`lower_case_table_names=1` or `2`),
uninstall currently requires an ASCII connection prefix. A non-ASCII prefix refuses teardown even when empty:
the server's Unicode identifier mapping differs from both PHP and SQL lowercasing. Disable keeps the data and
schema; do not guess a different prefix or bypass this refusal to remove retained data.

Uninstall is destructive maintenance: disable commerce, stop and restart its writers, export what must be
kept, and take a recoverable backup before running `kitsune:module uninstall kitsune/commerce`. The existence
check does not serialize teardown against running writes. A failed multi-statement migration on MySQL or
MariaDB can leave partial DDL; a retry is not evidence that the schema is repaired. Restore or inspect and
repair that state before enabling again.

Local path resolution is not a public package release. The publication prerequisites and separate artifact
checks are in [`docs/commerce-publication.md`](../../docs/commerce-publication.md) in the monorepo; no mirror,
Packagist registration, tag or release is created by installation.


The host can separately opt into carts with `\Kitsune\Commerce\Carts\CartRoutes::register()` before its
catch-all. Catalogue cards then offer Add to cart. Carts are database-backed and site-specific, use current
catalogue values, reserve no stock, and retain at most 20 lines with quantities 1–99. Reader sign-in merges the
same site's anonymous cart using the higher duplicate quantity. Saved reader carts survive sign-out; signed-out
sessions start fresh. Guest ownership can recover through an encrypted HttpOnly cookie after the shorter login session expires.
Both expire 30 days after an item change, with bounded `kitsune:cart-prune --limit=100`
maintenance while commerce is enabled. Checkout remains a later slice. Reader export/erasure includes retained
saved carts even with commerce disabled; see the operator workflow in `docs/commerce-operations.md`.
