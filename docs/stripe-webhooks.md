# Verified Stripe events

Commerce receives signed snapshot events into a durable Org inbox, acknowledges the committed receipt, then checks
the original Stripe Session or financial graph. Strict test/live mode follows the original purchase. Current Org
signing keys verify ingress; a payload cannot choose its mode. Admission revocation blocks new sales while existing
verified lifecycle work continues. After a mode/key change, original-account owner recovery remains explicit.
See [live admission](live-stripe-admission.md), [paid orders](paid-orders.md) and [purchased access](purchased-access.md).

## Host and Stripe setup

The host's existing `CartRoutes::register()` opt-in includes `/cart/stripe/webhook`, under the configured Site prefix.
Register **one canonical URL per org** with Stripe; that ingress Site can differ from the order's recorded selling
Site. Do not create one endpoint per brand using one signing secret: Stripe assigns each endpoint its own secret.
Ordinary host/panel route precedence still applies. No URL is installed by enabling the module alone.

Before Laravel captures the request, add the following after Composer autoload and before application bootstrap,
as the skeleton's `public/index.php` now does:

```php
$request = \Kitsune\Core\Http\BoundedWebhookRequest::capture('/cart/stripe/webhook', 'Stripe-Signature');
if ($request instanceof \Symfony\Component\HttpFoundation\Response) {
    $request->send();
    return;
}
// Bootstrap the application as usual, then:
$app->handleRequest($request);
```

Existing installed hosts must make this edit before exposing the route. The handler refuses a request without the
early byte-capture receipt; a controller-only length check cannot bound Laravel's eager JSON allocation. The guard
reserves the suffix for every HTTP method, including encoded/prefixed/front-controller paths. It reads at most
256 KiB plus one byte, bounds the signature header to 4 KiB and 32 components, preserves exact raw bytes and leaves
JSON unparsed until signature verification. Unrelated routes retain ordinary framework capture.

In Stripe, choose **Your account**, **Snapshot**, API version **2026-09-30.endive**, and these event types:

- `checkout.session.completed`
- `checkout.session.expired`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `refund.created`
- `refund.updated`
- `refund.failed`

Under the org's test-mode Credentials, save **Stripe webhook signing secret**. During explicit signing-secret
rotation, place the old secret in **Previous Stripe webhook signing secret** and remove it when overlap ends.
Kitsune does not silently retain replaced secrets. Signing secrets have the same prefix in both modes; the selected
credential line and event's strict `livemode=false` check establish test eligibility. Live and Connect/context events,
unsupported snapshot versions, unknown/foreign attempts and tampered signatures are refused before raw custody.

Exclude this webhook URL and the admin `/storefront/orders/historical-recovery` POST from host/proxy/APM request-body
capture. Kitsune clears body bags, raw bytes, signature and query aliases before its own Site/auth/credential callbacks;
middleware/reporters running before dispatch belong to the host. Never log signature values, raw payloads or transient
recovery keys. The webhook route bypasses browser session/cookie and CSRF middleware; signatures authorize receipt.
The owner recovery POST retains panel authentication, Site context and an independent body-only CSRF check.

## Durable processing and replay

The ingress Site derives the org; event metadata supplies only opaque lookup hints. An org-scoped routing receipt
maps those hints to the recorded order Site. Existing #197 attempts are backfilled without changing their original
request or hash. Full order/attempt custody is checked independently before capture and processing. A verified
provider account GET and expanded Session GET must match the original account, mode, references, amount, currency and expiry.
Own-account events need not carry an account field. A browser return URL never grants payment authority.

Event identity is unique within org/account/mode. A duplicate payload cannot renew retention or rewrite the receipt;
a conflicting digest is refused. Different events for one Session share the attempt's lease and durable once-only payment/allocation
effects. Delivery order and event creation time do not sequence state; current authoritative Session state does.
Stripe does not promise byte-identical retry JSON. Kitsune's same-ID digest refusal is a conservative custody choice,
not a provider guarantee: if redelivery changes bytes, the first receipt stays available for operator replay.
An order admits at most 1,000 distinct receipts, matching its bounded history read. At the ceiling, duplicates remain
acknowledged and fresh identities are refused before insertion, so admission cannot make retained history unreadable.

Under PHP-FPM, Laravel finishes the empty 200 response before the termination callback starts bounded inline
processing. Other SAPIs retain a visible pending receipt for console replay. No queue or always-on worker is required.
Provider I/O happens outside transactions. Receipt and attempt leases last 30 seconds; an interrupted process leaves
durable evidence, and a later invocation can reclaim an expired lease. Pending, processing, processed, retryable and
blocked states have safe owner summaries; no payload or provider text appears there or in reader/guest exports.

Run as the trusted host operator, explicitly naming the org:

```sh
php artisan kitsune:stripe-replay --org=golfdom --limit=100 --seconds=30
php artisan kitsune:stripe-replay --org=golfdom --event=123 --limit=1 --seconds=30
php artisan kitsune:stripe-prune --org=golfdom --limit=100
```

Limits accept 1–1000 rows. Replay's time budget accepts 1–300 seconds and stops starting rows when reached; one already
started account/Session operation may run up to its two bounded 10-second provider calls beyond that budget. There
are no automatic network retries. A durable claim count orders eligible receipts by fewest previous attempts, then
ID, so blocked old receipts cannot monopolize each bounded batch. Active leases are skipped. A nonzero replay exit
reports retryable/blocked/pending work in its selected batch; successful batches do not claim the whole org is clear.
An exhausted row/time budget may leave additional rows; invoke again until the inbox is clear.

Every replay requires commerce enabled with a matching installation receipt and a valid current Org credential mode. Provider
reconciliation of unresolved attempts additionally requires the original current-mode credential witness. A
replacement/reseal or changed mode blocks that automated provider path. Owners can use
the explicit historical recovery form with a transient original-mode key and fresh local password while the org remains in
another mode. It proves the original account, retrieves only the same Session and rechecks the complete durable graph
and current ownership around provider I/O. It never installs the key, changes the old witness or creates a Session.
After historical recovery verifies a completed or expired attempt, normal stored-event replay can finish its local
receipt bookkeeping without reading an old key or contacting Stripe; its exact terminal binding cannot be regressed.
A check returning open or uncertain leaves the unresolved fence and original-witness restriction in force. The
historical action retrieves only: it does not expire an open Session or create another one.
If module disable/staleness prevents the command from being registered, restore an enabled matching installation
before runtime replay. An API version change requires an explicit migration policy; this slice refuses it.

## Seven-day custody and erasure

Raw verified bytes are stored privately as text, with a fixed `received_at + 7 days` expiry. The minimal immutable
envelope contains provider event/session IDs, exact order/attempt routing, account/mode/API, payload digest and receipt
timestamps. It holds no customer name, email, address, item copy or bearer Checkout URL. Processing and replay never
select raw bytes, even before expiry: they retrieve the authoritative Session from the checked envelope.

Run `kitsune:stripe-prune` regularly, repeating bounded batches as needed. It physically clears expired bytes from
pending, failed/blocked and processed receipts while keeping dedup/replay evidence, and works after an org mode switch.
An idle install cannot perform wall-clock physical deletion without an operator or host scheduler executing cleanup.
Disabling commerce also removes its console commands; re-enable a matching installation for pruning, or use the
checked privacy workflow for terminal order erasure. No raw payload is exposed by the disabled-module privacy adapter.

Terminal test-order erasure atomically clears raw bytes, Session/order/attempt correlations and routing receipts,
retaining only a minimal dedup tombstone. Redelivery cannot restore erased order data. Pending/uncertain attempts still
fence cancellation and erasure. Retained inbox/routing rows refuse module rollback or uninstall; disabling preserves
custody. Paid records follow their [explicit retention policy](paid-retention-and-privacy.md); original mode supplies no invoice-retention exemption.

## Verification limits

Local fixtures verify signature bytes, timestamp/size/depth bounds, both scope boundaries, original-account recovery,
deduplication, out-of-order events, raw expiry/erasure and bounded replay through production handlers. A real process
kill during provider I/O exercises both committed leases and later replay on disposable SQLite. Provider HTTP is
mocked; no account, webhook destination, payment, live mode or public host is activated by these tests.

The measured PHP-FPM acknowledgement and memory results are appended to ADR-040 after qualification. They establish
response sequencing on the measured local FPM host, not real Stripe eligibility, network timeout enforcement,
production throughput or a new service requirement. [Stripe webhook guidance](https://docs.stripe.com/webhooks),
[Event object](https://docs.stripe.com/api/events/object), and the
[official PHP signature verifier](https://github.com/stripe/stripe-php/blob/master/lib/WebhookSignature.php) are the
provider references; seven days and the implementation budgets are Kitsune policy.

## Paid confirmation and refund subjects

A completed #198 test attempt without a paid receipt is re-fetched before stock effects; its old label is not
captured-payment proof. Even processed old inbox receipts need explicit catch-up using
`php artisan kitsune:payments-reconcile --org=<slug> --limit=100 --seconds=30`. This command also reconciles owed
refunds. Add `--refresh-successful` to check successful refunds for later provider failure. Claims are selected
fairly across old confirmations and refund work; the time limit stops starting new work, not an in-flight request.

Refund events retain their own Refund ID and local refund-attempt reference alongside the original Session/account
correlation. They cannot route by an arbitrary amount or email. Current provider GET state determines the refund
result; the event snapshot is only a signal. A verified Refund ID remains a recovery hint after a failed GET or raw
payload pruning, and prevents a list miss from authorizing another POST. Successful refunds can later fail and
reopen the reimbursement obligation, without repeating stock allocation. Conflicting hints require operator attention.

After the FPM receipt is processed and committed, one newly owed refund can progress outside transactions.
Non-FPM hosts use explicit bounded commands. No always-on worker is required. See [refund recovery](paid-orders.md).

The same maintenance command independently discovers [purchased access](purchased-access.md), including paid
orders whose earlier webhook has already finished. Frozen access work can progress without a Stripe key or another
provider call. FPM follow-up may attempt that work after the paid transaction commits; an interrupted follow-up
leaves durable pending work for a later command. [Refund and dispute handling](refunds-and-disputes.md) additionally
captures minimal signed financial signals before acknowledgement. Unresolved signals veto access restoration;
current complete Charge/refund/dispute reads establish the later financial state. Source adjustments are
discoverable independently of completed initial grants and provider credentials.
