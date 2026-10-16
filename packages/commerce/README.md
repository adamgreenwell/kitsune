# kitsune/commerce

The optional first-party commerce module. It supplies org-shared products, exact integer prices and finite or
untracked stock, per-site listings, a supported currency chooser, owner-only product administration with an
adaptable Storefront blueprint, an optional public catalogue with reader access badges, and saved site carts.
It also prepares immutable unpaid orders with tax and delivery quotes; Stripe payments follow. ADR-040 in the monorepo records the design and delivery order.

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

The module lifecycle owns its catalogue, cart, calculator and order migrations; ordinary
`artisan migrate` does not run them. Core owns the module receipt and the org's currency configuration.
Sellables declare and enforce Org scope; listings declare and enforce Site scope, with reference-coherence
guards. The module verifier checks every model declaration. A retained sellable or order prevents changing or clearing
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
maintenance while commerce is enabled. The same host delegation adds checkout and order pages beneath `/cart`. Reader export/erasure includes retained
saved carts even with commerce disabled; see the operator workflow in `docs/commerce-operations.md`.


Owners configure **Storefront → Checkout settings** before orders can be prepared. Select explicit tax treatment
for catalogue prices and shipping fees, add country/region rates for each tax class (including explicit zero rates),
and assign each shared SKU a tax class and digital/physical delivery policy. An exact region code wins over the
country's `*` fallback. Physical lines require a dispatch address and configured parcel dimensions, unit weight,
tare and capacity. Parcels split within each SKU; Kitsune does not guess how different SKUs pack together.

Physical checkout uses an encrypted **Shippo API token** for the org's active test/live credential mode. It requests
domestic shipment rates in the org's exact currency, with at most twenty parcels; there is no FX conversion,
customs declaration or label purchase. Missing rates/configuration or provider failures refuse checkout. Shippo
and working email are optional commerce capabilities and do not change core's installation resource floor.

`/cart/checkout` collects an email, billing name/address and optional separate delivery details. A server-session
quote lasts fifteen minutes, with at most five saved quotes. Carrier I/O runs outside database locks; cart,
price, stock, tax, packing and credential fingerprints are checked afterwards and at preparation. Tax rounds
half up once per complete line using exact minor units, for inclusive or exclusive prices.

Prepared orders preserve their own currency witness, product references, names, quantities, tax, quoted amounts,
and bounded invoice/delivery details. They can be cancelled, exported or erased, and are visible under
**Storefront → Orders** and `/cart/orders` for their current owner. Exact submission replay returns the original
snapshot; one prepared order claims an owned cart revision. No payment, stock reservation/decrement, access grant,
legal invoice number or confirmed amount is created by preparation. Confirmed test payments, stock allocation,
refund/dispute recovery and source-bound purchased access are separate verified workflows. Live checkout remains disabled.

Guests receive a one-use email link lasting thirty minutes. GET does not consume it; an explicit confirmation
POST grants thirty minutes of access to that exact order/site. The original browser's cart ownership also permits
access. Guest email matches never attach orders to a reader. Reader export/erasure includes orders even while
commerce is disabled; proof-backed guest export downloads the saved snapshot, while erasure removes the complete unpaid order and its links. Production
order preparation, including signed-in readers, refuses a mail transport that only logs or keeps messages. No queue worker is required.


### Paid order retention and privacy

The retention slice (#203) adds the owner-only **Data retention** page to create immutable, explicitly configured
policies and activate one for newly prepared orders. Payment confirmation starts the recorded clock: whole UTC
days, or whole years after the payment year ends in a frozen IANA timezone. There is no legal default. Existing
orders keep their recorded version until an owner explicitly adopts a selected replacement.

Only selected billing name/address fields can remain with an invoice after account erasure. Essential contact and
delivery details for an unfinished physical order stay in a separate purpose-bound hold until owner-confirmed
completion. A review date does not release a delivery, financial or legal hold. Account erasure withdraws reader
links, guest proofs and access sources; it does not rewrite money, tax, verified payment or refund history.

Preview a bounded batch with `php artisan kitsune:commerce-retention --org=<slug> --limit=100`; add `--apply` to
perform its eligible work. This installed-data command remains available when commerce is disabled or its runtime
receipt is stale. Each invocation independently checks retained custody and scope. No scheduler or worker is required.

See [the retention guide](../../docs/paid-retention-and-privacy.md) for checked minimization, final purge, replay
suppression and quarantined backup restoration. Local cleanup does not delete provider records or backups. The
slice is merged through #285; live checkout acceptance remains separate.

### Commerce history exports

The #202 slice adds native owner-only JSONL/CSV downloads on the selected Site's **Orders** page and the cold
`kitsune:commerce-export --org=<slug> --format=jsonl|csv --limit=100` command. The CLI optionally accepts an exact
`--site=<handle>` and remains available with installed commerce disabled or stale. Each checked artifact contains
at most 100 orders and 16 MiB, with a completion record and scoped continuation cursor. Financial history is the
default; `--include-billing` or the owner's checkbox adds only permitted retained paid-invoice fields. Delivery
holds, email, secrets and raw provider custody are excluded. Original prices, units, tax, refunds and access come
from checked historical records, independently of the current catalogue and providers.

See [the export guide](../../docs/commerce-exports.md) for the versioned linked-record layout, reader/guest boundary,
spreadsheet guarding, page consistency and portability limits. The full local gate, native export suite and
owner-browser checks passed in the review candidate; review and merge remain pending. This does not enable live
checkout, supply an importer or replace quarantined backup erasure replay.
