# Refunds and disputes

Commerce reconciles refunds and formal payment disputes against the confirmed purchase's original Stripe
account and mode. [Revoking live admission](live-stripe-admission.md) blocks new sales while this existing financial
lifecycle continues. It requires no always-on worker and preserves the original invoice, allocation, access offer
and guest claim.

## Refund delivered units

On an order's admin detail, an owner chooses **Refund delivered items**, selects quantities from the original
delivered units, supplies a reason and confirms the request. Kitsune includes each selected unit's original tax
and shipping allocation. Digital units have no shipping allocation; physical shipping and its tax were allocated
proportionally to the original physical items' net values. Current prices and product edits do not change the
refund amount. Owners and editors continue to adjust stock separately, with a reason and stock history.

Saving the request and suspending those units' access contributions commit together. Unknown submissions,
pending refunds and successful refunds keep those units excluded. A freshly verified failure or cancellation
releases them, restoring only the remaining original term. A new attempt after failure requires new owner intent;
an uncertain request keeps its original refund reference and idempotency key.

Remaining units keep their original offers. Within an order, the latest remaining end wins and permanent wins
over fixed-term access. Refunding the longest or permanent contribution can therefore shorten a pass without
removing it. Other orders and complimentary access are independent. A refund does not return stock.

## Attribute a Stripe-side refund

**Check refunds and disputes** retrieves the current financial state from the original Stripe account and mode.
A refund created outside Kitsune holds this order's access until an owner chooses **Attribute an external refund**,
selects its exact original delivered units, gives a reason and confirms. The selected units, including their
allocated tax and shipping, must equal the verified refund amount. Attribution sends no additional refund.
An amount Kitsune cannot represent with those original units stays on hold; matching an amount or email never
silently selects units.

An original payment whose allocation is held can instead offer **Refund remaining balance** after a fresh,
complete financial check. It refunds only the verified remaining capture and never creates delivered units or
access. Existing unavailable-item obligations retain their separate recovery controls. Conflicting, pending or
unknown financial work must be resolved before another payment request can proceed.

## Handle disputes and restore access

A formal dispute suspends this order's access while it needs a response, is under review or is lost. Only a fresh,
verified `won` state releases that dispute's blocker. Every other blocker must also clear. Payment inquiries are
shown separately; a funds-reinstated signal alone does not prove a win.

Restoration uses the exact source and revocation witness saved by commerce. An independent manual change,
missing grant or erased grant cannot be overwritten or recreated. Restoration needs the original reader to be
freshly verified. Fixed-term access still ends at the date derived from first payment confirmation; a failed
refund, late dispute win or delayed guest claim cannot restart that clock. Historical unknown terms remain held
for the separate owner workflow in [#281](https://github.com/adamgreenwell/kitsune/issues/281).

## Recover interrupted work

Owners can check financial state and purchased access from the order detail. **Recover with the original account
key** accepts a transient key for the original account and mode plus the owner's password. It does not replace
configured credentials or mint a new request. Secret inputs use a native form and are cleared after submission.

The bounded command discovers financial observations, saved refund requests and access adjustments separately:

```sh
php artisan kitsune:payments-reconcile --org=<slug> --limit=100 --seconds=30
```

Financial recovery makes bounded provider reads outside database transactions. Complete Charge, refund and
dispute identity and amount checks are required before applying observations. Events prompt a current-state
check; their arrival order does not establish financial truth. A signed unresolved financial signal prevents
access restoration while verification is unavailable. Already-verified source adjustments need no Stripe key.

An uncertain refund can retry its exact request only within the existing 23-hour admission window. Later
recovery adopts an exact matching provider receipt or remains held; it never guesses a new idempotency key.
Fair selection lets other orders progress past held work. No scheduler or separate service is mandatory.

## Read history and protect custody

The order shows its unchanged original totals, separate refund and dispute history, and current access results.
Adjustment reasons are owner-only. Buyer views and exports omit provider identifiers, credentials, raw payloads,
internal hashes and source witnesses. Financial custody is checked even with commerce disabled or stale; paid
orders remain protected from erasure pending #203's retention and minimization policy.
