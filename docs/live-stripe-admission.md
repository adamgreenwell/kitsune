# Live Stripe admission

Live checkout is a separate, Org-wide owner decision. Keys and the current credential mode alone never create an
admission. Test mode remains the default. This feature is source support for real-money sales; it does not activate
an installed Org, qualify a provider account or deploy a release by itself.

## Admit new sales

1. Enable the current commerce module, choose the Org currency and configure products, tax, delivery and explicit
   invoice retention. Read the checkout, webhook, refund, access, privacy and export guides below first.
2. Qualify the operator's own Stripe account and public HTTPS host separately. Configure one canonical own-account
   snapshot webhook URL per Org at the pinned API version, `2026-09-30.endive`. Stripe Connect, organization keys,
   contextual events and custom Checkout domains remain outside this contract. Prove delivery, replay, paid stock,
   access, refund/dispute recovery, privacy and export on the intended installation before admitting sales.
3. In **Credentials**, install the live Stripe secret key and live webhook signing secret. An optional previous
   signing secret supports deliberate overlap. Switch the Org to live mode. These steps still admit no sales.
4. Open **Storefront → Orders → Live Stripe checkout**, enter your current Kitsune password, then choose
   **Verify account and admit live checkouts**. The server authenticates the owner afresh, reads `/v1/account` using
   the current live key outside database transactions, then independently authenticates and checks the same state
   before committing admission and its audit together.

The account must report `charges_enabled`, `payouts_enabled` and `details_submitted` as exactly true. This is an
implementation readiness rule, not an additional product choice or proof of webhook delivery, currency/card
eligibility, legal readiness or continued provider availability. Only the account ID survives the response.

The owner form uses a native body-only CSRF POST. Passwords never enter Livewire component state, a URL, a stored
admission or an audit payload. Stale form versions refuse rather than overwrite another owner's decision. Native
admission has no CLI shortcut. Server credential installation alone cannot supply owner admission.

## What invalidates admission

The admission binds one Org, verified account, pinned API version, current commerce version and the stored
revisions of the live API/current/previous webhook credentials. Replacing, removing, resealing or adding any of
those revisions requires fresh owner verification for new Session creation, even when the plaintext is unchanged.
A missing schema, disabled/stale module, wrong mode, unusable secret or mismatched account refuses new sales.

Switching to test mode makes an existing live admission inactive. Returning to live with exactly the same admitted
credential/module revisions can use that existing consent; switching modes never creates a missing admission or
undoes an explicit revocation. Revoke consent explicitly when the intention is to withdraw it across mode changes.

New live return URLs must use the Site's configured HTTPS origin. The browser's success/cancel return still cannot
confirm payment. No-cost completion remains deferred to #288; a zero final total produces the existing clear
refusal without a Session or a grant.

## Block sales without abandoning purchases

Choose **Block new live checkouts** and confirm your password. This changes the admission version even when there
was no prior admission, so an in-flight account verification cannot silently restore consent. It blocks every
subsequent Session-create POST, including a retry of an unknown create outcome. A remote creation already in flight
can still return a fact that Kitsune records; it must not be discarded as though no Session could exist.

Admission is checked before the durable create claim and again before provider creation after account discovery.
Recorded Session reads/expiry, paid receipts, stock allocation, unavailable-item refunds, selected-unit refunds,
disputes, purchased access, privacy and export never depend on a current admission. Their authority remains the
original account/mode and exact immutable local purchase graph. Revocation does not mutate those historical
records or their original API-key witness.

Current-mode automatic recovery keeps the original credential-witness rule. After an API key, account or Org mode
change, use the existing native original-account recovery form: a fresh owner supplies a transient key for that
purchase's original account **and mode**. It never installs the supplied key or changes current payment settings.
The key cannot authorize a different account, mode, Session, Charge, refund, dispute or purchase amount.

Webhook ingress uses only the URL-resolved Org's **current mode** signing keys; a payload never chooses a mode,
Org or account. Admission revocation alone leaves those keys available. After a mode switch, old-mode delivery is
refused; explicit original-mode owner recovery remains available. Stored verified inbox work retains its original
mode and may finish a locally verified terminal effect after owner recovery. Raw payload retention stays seven days;
paid-data erasure and replay tombstones continue to partition by original account and mode.

## Qualification and operations

Default tests use SQLite and fake HTTP behind the real bounded Stripe parser. They make no provider request and
activate no real Org. Supported-engine scope/credential fences and native browser forms are separate checks.
Record exact tested source, engine/browser outcomes and actual retries/skips. Account readiness and all local/CI
checks remain distinct from an authorized real provider/TLS rehearsal and an actual owner admission.

- [Checkout and immutable attempt recovery](stripe-checkout.md)
- [Verified webhook custody and replay](stripe-webhooks.md)
- [Paid confirmation, shortages and refunds](paid-orders.md)
- [Purchased access](purchased-access.md)
- [Selected refunds and disputes](refunds-and-disputes.md)
- [Paid order retention and privacy](paid-retention-and-privacy.md)
- [Commerce history exports](commerce-exports.md)
