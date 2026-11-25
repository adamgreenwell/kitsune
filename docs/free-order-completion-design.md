# Free order completion (#288)

Merged through PR #290 at `a73ce4256e9e438b88e60125a8eb0b4cf05cc57a`, following
PR #289 (`ae444bf8801f364745a6c8181e4e9efba7af3a9f`). ADR-040 records the settled policy.

## Adam's settled choices

- Fixed-term access starts at first durable free completion.
- Award remaining stock and record unavailable quantities without a refund.
- Use the Org's explicitly configured retention version, anchored to free completion and labelled free.
- Owners may remove/restore exact original unit contributions with a reason, confirmation and audit;
  restoration keeps the original term and stock adjustments stay separate.

## Buyer and operator behavior

Review the prepared order on its existing Checkout page. If its complete final total is zero, the native
**Complete free order** action replaces the Stripe button. Current tax, shipping, price, listing identity
and offer terms must match that expiring review. Available quantities are allocated once under the shared
Org lock. A deleted/unpublished listing invalidates the review; a stock change alone can produce a partial
award. Free completion records no Stripe Session, payment or refund and needs no Stripe credentials.

The order shows its original completion instant, awarded and unavailable quantities. Guests explicitly
sign in/register and claim using secure order proof. Fixed terms include the time before a guest claims.
Owners see each awarded unit’s current retained/removed state and use the order's free-access controls
to select original unit numbers, enter a reason and confirm
removal or restoration. Those local effects commit atomically with the correction. Reload a stale form;
retrying a saved request cannot apply it twice. Restoring an expired unit does not add time. Stock stays
unchanged until its separate adjustment workflow is used.

Retention is the Org's explicit policy frozen at preparation. Missing policy remains held for owner
adoption; Kitsune supplies no legal default. Free guest minimization removes guest links, claims and
non-required personal data while retaining only allowed purchase and purpose-bound delivery fields.
Account erasure, holds, access-lifetime and quarantine/restore rules follow the existing privacy guide.
JSONL and CSV include distinct free-completion and unit-correction records; no payment is fabricated.

The completed order's guest erase form uses its exact unclaimed order proof, including in an anonymous
HTTP request. It requires neither a staff login nor console execution. The proof and Org/Site boundary
are checked again after the audit callback; losing proof or introducing a claim rolls back the entire
minimization. The producer audit has no invented reader actor. Rotating the committed order's ownership
key withdraws the old guest access without changing its free-completion receipt or frozen retention policy.

## Implementation boundaries

`CheckoutReview` revalidates ownership, original line identity, current price, tax configuration, purchase
terms, shipping selection and quote expiry. Zero-total reviews exclude only stock from the purchase-consent
fingerprint; the locked completion determines availability. Positive-total checkout keeps its original
full-stock review. Deleted or unpublished listings invalidate consent and cannot be silently replaced.

Use the existing Org-first `OrderLocks` transaction for the durable completion and shared stock decision. Free allocations have their own stock-history kind, `free_order`.
Provider-independent completion has its own immutable witness and timestamp. It must not create a
Stripe Session, PaymentIntent, Charge, provider payment receipt or refund. A zero-priced physical item
with positive shipping or tax still has a positive final total and belongs to ordinary paid checkout.

`CompletionFacts` distinguishes provider-confirmed `paid` orders from `completed_free` purchases.
`FreeOrders` validates the separate receipt; shared access and privacy consumers use the proper authority. Access must derive from frozen original offers and awarded units. Preserve exact-site guest
proof plus an explicit signed-in claim, never email matching. Retrying completion must not award stock
twice, restart a term or change the beneficiary, including after the original review expires.

`PrivacyState` freezes original checkout, financial and access commitments; `OrderPrivacy` handles
minimization, delivery/legal holds and final retirement. Extend their checked representation and negative
replay evidence to the free witness without inventing provider identifiers or rewriting historical paid
hashes. Preserve existing access promises independently of billing-field expiry. A physical allocation
does not prove delivery. Disabled-module reader and owner history still needs to validate retained data.

`OrderExportRecords` parents free allocations and exact unit corrections to a distinct free-completion
record in JSONL and CSV. Individual rows are yielded inside the existing complete-artifact byte bound.
Buyer and admin summaries distinguish free completion from paid or refunded records. Any owner access correction must
remain separate from financial refunds and stock adjustments.

## Coverage

The tests cover exact zero versus positive shipping/tax, changed and expired reviews, deleted products, both Org/Site
boundaries, other-browser and guest authority, duplicate and concurrent submissions, shared stock between
sibling Sites, original permanent/fixed terms, erasure and delivery holds, disabled-module exports and
audit-callback corruption. They exercise real outer commits on supported engines and native buyer/admin
journeys. The default suite remains SQLite and service-free; the completion itself makes no payment
provider call. Physical quote review keeps the already configured carrier-quote boundary.

PR #289's merged implementation does not activate any real Org. Issue #287 remains open for separately
authorized real provider/HTTPS qualification; #288 does not require a live Stripe admission.

## Initial candidate qualification (2026-10-16, `b5c132c`)

The targeted native suite passed 375 tests / 2,106 assertions on MySQL 8.4.7 and MariaDB 10.6.28,
and 374 / 2,101 on PostgreSQL 18.6. All 32 new real-commit lifecycle cases and three independent-process
free-order races passed without exclusions on each engine. The neighboring paid-stock, financial, access,
privacy and export probes retain 12 existing driver exclusions on MySQL/MariaDB and 13 on PostgreSQL.
All 77 application data tables were empty afterward; the owned containers and volumes were removed.
The 754 checked source files stayed unchanged during that run. Subsequent edits supplied the claim view’s
explicit free-order flag, updated the migration-count assertion and added a free claim-page browser check;
the native-tested completion, stock, access and privacy source stayed unchanged. The affected claim/module
PHP checks passed 12 tests / 407 assertions.

The final native buyer/admin browser run passed all 29 checks without retries, skips or flakes, including
the new free completion, guest claim-page wording, request protection, owner unit correction and Axe
checks, plus existing paid checkout, claim, refund, retention and access-offer journeys. All 18 protected
runtime paths were restored exactly.

Full `composer ci` passed on PHP 8.5.10 / SQLite: 10,080 tests / 53,396 assertions, with 50 existing
explicit driver/condition skips. Configured PHPStan and root Pint passed; skeleton Pint and
`git diff --check` passed afterward. The checked source hashes/modes matched around the complete gate.
Only documentation of these results changed afterward. PR CI and maintainer merge remain separate gates.

## Unit-state review follow-through

Automated review identified that identical unchecked unit checkboxes hid which original units were
valid restoration targets after a reload. The checked correction projection now exposes exact removed
unit numbers; the owner view labels every awarded unit as retained or removed. The browser journey
removes one of two units, reloads, checks both distinct states, restores the removed unit and reloads
again, while checking that stock and provider records remain unchanged. The original correction chain,
terms and stock rules are unchanged.

The reviewed candidate passed full `composer ci` on PHP 8.5.10 / SQLite: 10,080 tests / 53,398 assertions,
with the same 50 existing explicit driver/condition skips. PHPStan, root/skeleton Pint, whitespace and
frozen-source checks passed. Fresh native qualification passed 375 tests / 2,108 assertions each on
MySQL 8.4.7 and MariaDB 10.6.28, and 374 / 2,103 on PostgreSQL 18.6. All 35 free-order cases passed
without exclusions on each engine. The 749 checked source files stayed unchanged throughout native
qualification; all 77 application data tables were empty afterward and owned containers/volumes were
removed. The expanded native browser regression run again passed all 29 checks without retries, skips
or flakes and restored all 18 protected runtime paths exactly. Only documentation of these results
changed after the frozen-source gates. Fresh PR CI and maintainer review/merge remain separate gates.

## Verified merge (2026-10-16 America/New_York)

[Final CI](https://github.com/adamgreenwell/kitsune/actions/runs/38988829642) passed all 14 normal checks at
`a6d9ad549a9892e43ca056fd438aaf22cc13415a`, with only the expected CLA skip. Both full browser runs passed
390 tests. The unit-state review thread was resolved and the final merge check found no conflicts.
PR #290 merged at `a73ce4256e9e438b88e60125a8eb0b4cf05cc57a`; #288 is closed. Real provider qualification
remains on #287. The cart-parent follow-through is #272. Its separately measured product/schema path is addressed by the #291
candidate; ADR-040 preserves both the original failed preflight-only experiment and the complete write correction.
