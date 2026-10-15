# Test paid orders, stock and refunds

Live checkout remains disabled. This slice exercises captured-payment confirmation and refunds in Stripe test mode;
it does not establish real provider sandbox or production qualification or invoice retention.
[Purchased access](purchased-access.md) now consumes its confirmed payment and positively allocated units.

## Confirmation and availability

A signed event is a reconciliation signal. Kitsune retrieves the original account and expands the original Checkout
Session's PaymentIntent and latest Charge. Exact account, test mode, identity, currency, reviewed money and captured
amount must match. The browser return is read-only. A basic Session status check can show completed while the order
still awaits this stronger proof. Owners can use historical checkout recovery; operators can run bounded catch-up.

One transaction locks Org, Site and the shared product rows in deterministic order. It records the payment receipt,
allocation, stock movements and any refund obligation atomically. Duplicate events cannot allocate again. No stock
is reserved before capture, and null stock remains untracked. Allocation records availability awarded to the order;
it is not a claim that a parcel has shipped. Current price changes do not rewrite the accepted invoice.

If three units were ordered and two remain, two are awarded and one is refunded. Removed or unavailable products
receive no allocation. A cancelled legacy order discovered to have captured payment is recorded as paid and owed a
full refund, with no stock allocation. External refunds or a disputed Charge hold allocation for operator attention.

Item net and tax are apportioned across original units without changing invoice totals. Shipping net and shipping
tax are split by the original net value of physical lines; digital lines receive none. Stable line/unit order breaks
rounding ties. If all physical net values are zero, physical unit counts provide the deterministic fallback. Every
original minor unit remains accounted for. Available units win before unavailable units receive their exact shares.

## Owed money and recovery

Order detail and export show confirmed money, awarded/unavailable quantities, refund components, amount owed and
attempt history. Owed, unknown, pending, requires-action, failed, canceled and held do not mean money was refunded.
Even succeeded remains refreshable: Stripe can later report failure, reopening the obligation without restocking or
repeating allocation. Product editors see stock history without buyer or provider identifiers; financial recovery
remains owner-only.

Kitsune persists an immutable request and idempotency identity before refund I/O, against the original captured
Charge. An unknown POST may repeat identical parameters and the same key for less than 23 hours from the first
submission. Afterwards it may only adopt one exact matching provider refund from a bounded list or verified event
hint. A missing list result, multiple matches, conflicting IDs or external refund amounts require attention, never a
replacement key. A known Refund ID is retrieved directly. Provider calls hold no database transaction.

An owner may check or submit the existing refund using a transient test key for its original account and a fresh
local password. **Authorize another refund attempt** is available only after freshly verifying the previous attempt
failed or was canceled. It retains the prior receipt and permits one new identity; uncertain attempts cannot use it.
Keys/passwords travel in native body-only POSTs and never through Livewire state, history, URLs or exported data.

Provider amounts use the existing Stripe currency conversion, while invoices and obligations keep the org's ISO
minor units. Some partial refunds, such as a fraction of MGA's Stripe whole-unit representation, cannot be encoded
exactly. Such an obligation stays held and fully owed with no fabricated request or rounding. It needs an explicit
operator resolution before that currency can be used for live commerce.

## Bounded maintenance

```sh
php artisan kitsune:payments-reconcile --org=<slug> --limit=100 --seconds=30
php artisan kitsune:payments-reconcile --org=<slug> --limit=100 --seconds=30 --refresh-successful
```

The first command catches up old completed test checkouts and owed refunds; the second also refreshes successful
refunds for later failure. Durable claim counts prevent blocked old work monopolizing repeated bounded invocations.
The time budget stops starting new operations; an in-flight bounded provider request can finish after it. Module
installation/version, original account, active mode and credential witness must still agree. Explicit fresh-owner
historical recovery handles authorized key/mode changes without rewriting the original witness.

Under PHP-FPM, a verified inbox receipt commits and the response finishes before inline processing. After checkout
processing commits, one newly owed refund can progress. Other SAPIs use the explicit commands. No required cron,
reservation sweeper, service or always-on worker is introduced. Continue the seven-day verified-payload prune policy
in [the webhook guide](stripe-webhooks.md).

## Custody limits

Original invoice lines are immutable. Paid/refund records can be read and exported, including through disabled/stale
commerce custody, but initial-order erasure cannot remove them. Completed legacy attempts are also fenced until their
financial state is known. #203 must define retention and minimization before live activation; this slice invents no
statutory retention period. [Refund and dispute access handling](refunds-and-disputes.md) uses the frozen per-order
sources and immutable guest claims. Delivered-unit refunds and unavailable-unit obligations remain distinct;
external refunds need explicit owner attribution.
Retained financial and stock history refuses destructive rollback/uninstall.
