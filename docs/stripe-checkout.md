# Stripe test checkout

The first Stripe slice opens **test-mode, hosted, card-only Checkout** for an owned prepared order. Stripe handles
the payment form. Kitsune keeps the exact reviewed order and a durable attempt before requesting a Stripe Session.
No card details enter Kitsune.

Live checkout remains unavailable until verified completion/replay (#198), stock/refund obligations (#199) and
pending/charged privacy (#203) are implemented. Entitlement products also need the offer and guest-claim policy
in #200. Setting a live key or switching an org to live mode does not enable live checkout.

## Configure an org

Enable commerce through its normal module lifecycle, select the org currency and configure products, destination
tax and any physical delivery policies described in [commerce operations](commerce-operations.md). Place the
existing `CartRoutes` host surface; its internal order routes now include the payment leaves. No additional host
route registration is required.

As an org owner, open **Credentials**, keep the org in test mode and set **Stripe secret key** to the operator's
own Stripe test secret. A missing, unreadable, wrong-mode or invalid key refuses checkout. Restricted keys and
custom Checkout domains are not part of this first contract. Credentials remain write-only. Server recovery uses
the existing hidden-input command:

```sh
php artisan kitsune:credentials:set commerce.stripe-secret-key --org=example --mode=test
```

Never place its value in the command line, a URL, a ticket or a committed file. See
[credential operations](credential-operations.md) for hidden/stdin input and application-key rotation.

## Review and open Checkout

Prepare an order from the site's cart, then open its test checkout page. **Review checkout** is a CSRF-protected
POST. It checks the order's current products, availability, stock, tax and mode; physical delivery is quoted again
outside database locks. A recovered guest can review the exact order without owning the original cart. A changed
purchase requires a fresh order rather than rewriting retained history or silently charging a different total.

The review lasts 15 minutes. Starting Checkout commits the displayed amounts for **about 30 minutes** and records
an exact expiry. Stripe requires at least 30 minutes from its remote Session creation, so the immutable request
allows at most one extra setup minute. A retry retains that timestamp; it never extends the commitment.

Kitsune calculates tax. Each Stripe line carries its exact extended gross amount once, and physical shipping is
a separate gross line, preserving integer rounding. Stripe automatic tax, adaptive pricing, promotion entry and
additional payment methods are not enabled by this request. Existing invoice/delivery fields are not copied to
Stripe; the hosted page collects the information its payment operation requires.

Zero-total completion is deferred with a clear message. Mixed free/paid lines are allowed when the final total is
positive. Provider minimums apply to that final amount; a refusal does not round it up, add a fee or waive a balance.
Encoding eligibility does not promise acceptance by a particular account, country or payment method.

The supported presentment list is checked against the pinned provider contract. ISK and UGX are encoded with
Stripe's required hundred-unit wire representation; MGA requires exact whole-unit gross amounts after tax and shipping.
Unrepresentable gross fractions and overflow refuse before a provider request. Three-decimal currencies are deferred
in this slice pending account-specific qualification. HUF/TWD use their ordinary two-decimal **charge** encoding;
their payout rules do not change checkout amounts.

## Uncertain, open and completed attempts

One order has at most one unresolved attempt. The attempt owns its unchanged request, API version, idempotency
identity, verified account, mode and credential witness. A timeout, interrupted process or failed local result
commit can leave it **creating** or **uncertain**. Use the same order's status/retry action; do not create a new
attempt merely because a browser did not receive a response. Concurrent requests observe a short operation lease.

An open Session may be resumed through an authorized POST that retrieves its current server state. **Expire test
checkout** first reconciles and then explicitly expires an open Session at Stripe. Returning from Stripe, using
its Back link, closing a tab or passing the local expiry time does not prove payment or expiry. GETs only display
local state; they make no provider call and never change payment, stock or access.

A basic Session status check records **completed** on the attempt. Until the expanded captured-payment graph is
verified, the order awaits confirmation. Verified webhook, catch-up or explicit historical recovery now records paid
test money, allocates stock once and creates exact unavailable-item refund obligations. See [paid orders](paid-orders.md).
Confirmed test orders now queue [purchased access](purchased-access.md) from their frozen terms and positively
allocated units. A guest must explicitly claim their order after signing in. A completed test order cannot
start another checkout; prepare a separate order when testing another purchase.

Creating, open, uncertain and completed attempts block cancellation and erasure, including whole-reader erasure, until they
are reconciled. This is an unresolved-operation fence, not financial invoice retention. Expired/rejected unpaid test
attempts can be erased through the existing checked workflow. Completed attempts and paid/refund custody are retained
until the paid-data policy in #203; absence of a local receipt does not prove an old completed Session was unpaid. Safe attempt summaries ship in
exports, including while commerce is disabled or stale; private Session URLs, request payloads, credential
witnesses, leases and raw provider responses do not.

## Operator recovery

An org owner can open the order's admin detail, select an attempt and reconcile it or request expiry. If the process
lost the Session ID, copy the **test Session ID** from Stripe's dashboard into the recovery form. Kitsune retrieves
it server-side and checks account, test mode, exact amount/currency, order and attempt metadata before storing it.
An arbitrary Session ID grants no ownership and cannot replace a different already-known Session.

An unidentified attempt is replayed only within 23 hours of its creation, inside Stripe's documented minimum
24-hour idempotency retention. After that, supply the known Session ID through operator recovery. Never mint a new
identity to discover whether the earlier operation succeeded. A code-less or ambiguous provider refusal can still
require operator investigation; lack of a known Session ID is not proof that no Session exists.

Changing the org mode, replacing a key, resealing it under another application key, changing accounts or upgrading
the pinned API version can refuse runtime recovery. The durable attempt remains; it is not reassigned to the new
account or deleted. Re-enable the same module and investigate through the original provider account. Historical
credential/event recovery now has a separate explicit owner action: expand **Recover with this checkout’s original
Stripe account**, provide a test key for the original account and your fresh Kitsune password. It retrieves only the
original Session, using a checked verified receipt when its ID was lost, or the ID you supply. The transient key is
discarded; no credential/witness is rewritten, no new Session is created and buyer recovery stays frozen. A different
account or API version is refused. Export remains available independently of runtime mode/credential eligibility.
See [verified Stripe events](stripe-webhooks.md) for receipt custody, replay and seven-day payload cleanup.

The concrete client pins `2026-09-30.endive`, uses TLS verification, refuses redirects, performs no automatic
network retry and caps requests/responses. Each operation has a 3-second connection and 10-second whole-request
limit; account verification and a Session operation are separate bounded calls. Expiring an unidentified Session
can require a reconciliation operation followed by expiry. No worker, Redis, Node runtime or separate service is
required for the default installation or test suite.

## Measured runtime boundary

Twenty formal fresh-process runs and one smoke used PHP 8.4.26 ARM64 CLI with disposable SQLite, enforced
1 CPU / 1 GiB limits and PHP capped at 128 MB. Network was disabled; provider calls were mocked. First checkout
took 37–74 ms without artificial delay. Six runs covered the maximum 20 product lines, including physical orders
with 20 parcels and 21 Stripe items. Peak PHP heap was 48.5 MiB and maximum process RSS 85.09 MiB; no OOM occurred.
All Stripe and Shippo callbacks ran outside database transactions.

One-second delays in each mock account/create call made first checkout take 2.057 seconds, with no transaction
held. These are simulated delays, not socket-timeout tests. Fixture boot/migrations/order/review took 0.60–1.21
seconds and do not describe ordinary HTTP startup. The measured checkout source predates the cancellation-only
history-snapshot fix. Real network behavior, provider eligibility, FPM capacity and throughput remain separate
qualification; the measurement container adds no runtime requirement.

Provider references: [Session creation](https://docs.stripe.com/api/checkout/sessions/create),
[idempotency](https://docs.stripe.com/api/idempotent_requests), [errors](https://docs.stripe.com/error-low-level),
[currency encoding and minimums](https://docs.stripe.com/currencies). Local mocks verify the protocol and failure
boundaries; they do not establish live-account eligibility or a real provider qualification.
