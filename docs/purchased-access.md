# Purchased access

The commerce module awards Site-scoped reader access from confirmed **test** orders. Products share their access
offer across an org's sites; a purchase grants access only on the Site where the order was placed. Live checkout
remains refused while paid-data privacy (#203) and the remaining first-money gates are incomplete.

## Configure and freeze an offer

In **Storefront → Products**, set the shared entitlement name and explicitly choose permanent access or a whole
number of days (1–36,500). One day means exactly 24 hours in UTC. Clearing the entitlement clears its terms.
The same product on another Site uses the same name and terms, alongside that Site's own copy and images.
Catalogue, cart, quote and checkout show the offer; orders freeze the versioned terms with their purchase snapshot.
Editing a product later never changes an existing order's name or duration.

A historical entitlement name with no terms is unknown, not permanent. Existing catalogue entries remain visible
but cannot be added to or checked out from a cart until an owner configures their terms. Existing orders retain
their original snapshot and hold that entitlement for operator review. This slice has no action to assign terms
to those orders; changing today's product does not resolve that hold. An explicit owner resolution workflow is
tracked in [#281](https://github.com/adamgreenwell/kitsune/issues/281).

## Confirm and award

The fixed term starts at Kitsune's first durable captured-payment confirmation (`verified_at`), never at retry,
sign-in or claim time. Only positively allocated units qualify. Within one order, lines for the same entitlement
produce one pass: the latest end wins, permanent wins over fixed, and buying more units does not multiply days.
Separate orders keep independent sources; overlapping terms do not add time. The core resolver selects the latest
live end across sources, with permanent access taking precedence.

Payment confirmation atomically freezes a durable access manifest and its effects alongside payment and stock
custody. A pre-payment parent exists from order preparation. Migration initializes retained orders with an empty
parent, marking paid history for checked recovery. Recovery never recreates a missing parent or effect, and never
regenerates a grant after its completed effect. Financial holds prevent awards. A delayed pass whose original end
has passed is recorded expired without being granted; revoked sources remain revoked.

The narrow internal core producer method requires a real enclosing transaction and verifies the exact frozen
commerce work before and after the native audit callback. System-produced grants and claims have explicit null
audit actors. Staff and reader guards stay intact; ordinary grant, complimentary access and revocation permissions
are unchanged. Host `ReaderAccount` implementations need no additional methods.

## Claim a guest order

A guest follows the exact order's browser proof or secure one-use email link, signs in or registers, then explicitly
confirms **Save order to this account**. The returning account may have a different email. An email match alone never attaches
a purchase. Reading a link, signing in and opening the confirmation page are read-only with respect to the claim.

The whole order binds once to one freshly verified reader in the org. Concurrent different-reader claims cannot
both win. A retry by the same reader is harmless; there is no transfer action. The original guest invoice, owner
witness and financial snapshot stay unchanged. After claim, the reader can find the order in their account and
download its safe data; old guest browser and email proofs lose access. Sign-out removes reader access to that view.
The immutable claim and an independently checked access-work marker keep paid custody discoverable during export
and erasure, including with commerce disabled or stale. Partial corruption refuses the complete privacy operation.

**A guest's fixed-term clock runs before they claim.** Claiming after expiry cannot restart it. The order screen
shows per-pass processing, expiry, revocation or review status. These are historical processing results; a pass
awarded earlier may subsequently expire or be revoked.

## Recover pending access

On a paid order's admin detail, an owner can choose **Check purchased access**. An authenticated buyer can check only
their original order or explicitly claimed order. These actions use the saved reader and terms, never a recipient
or new expiry supplied by the caller. Unknown terms remain on hold.

The existing bounded command also discovers access work independently of Stripe checkout and refund work:

```sh
php artisan kitsune:payments-reconcile --org=<slug> --limit=100 --seconds=30
```

Already-confirmed access recovery needs no Stripe credential and makes no provider call. Bounded, fair selection
lets other orders progress past a missing reader or held pass. Payment webhooks may opportunistically progress
access after receipt processing; non-FPM hosts and interrupted requests use the command or an explicit action.
No always-on worker or external service is required. Disabling commerce removes runtime commands and actions;
retained export and erasure checks still protect its custody.

Paid and claimed orders cannot be erased until #203 supplies retention and minimization policy. Safe exports
contain frozen terms, the claim timestamp and per-pass results, excluding reader IDs, original owner proofs,
manifests, hashes and internal source identities. [Refunds and disputes](refunds-and-disputes.md) reconcile later
financial changes against these original per-order sources, preserving independent purchases and complimentary
access. The current financial access summary appears separately from the initial award history.
