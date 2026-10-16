# Paid order retention and privacy

The #203 implementation is ready for review. Live checkout remains disabled. The operator states the applicable
policy; Kitsune supplies enforcement and makes no jurisdictional retention choice.

## Recorded policy

An Org owns immutable policy versions with an explicit duration, clock, selected billing fields, basis and reason.
Activation affects newly prepared orders; an already prepared checkout keeps its frozen version. Adopting a
replacement for an existing order is a separate owner action with
its own reason and confirmation; missing historical policy never invents a deadline.

The payment-days clock counts exact 24-hour UTC days from first durable confirmation. The payment-year clock
uses the policy's frozen IANA timezone, starts at January 1 after the payment year, then adds the chosen whole
years. Both deletion boundaries are exclusive UTC instants. Zero is explicit; blank configuration has no default.

Only selected billing name/address fields belong in the personal invoice profile. Email and delivery details
have separate operational purposes. Money, tax, original commitments and the minimum financial/access/replay
receipts are classified separately and are not described as anonymous merely because buyer fields were removed.

## Account erasure, expiry and holds

Whole-reader erasure removes the host account, links and access through the existing account-last workflow.
An internal first-party module witness is captured after module cleanup and checked again after the host
account-deletion callback. Commerce verifies the exact retained orders and negative guards, rather than
treating disappeared reader links as proof. When the host account shares the connection, callback corruption
rolls back the whole transaction. A host account on another database retains the existing two-transaction
limitation; this does not provide distributed atomicity, change the host ReaderAccount contract or supply
general restore orchestration.
Core entitlement and reader-link producers also retain an in-memory witness of the actual row identities they
erased. Final primary-key absence catches resurrection under a changed Org, source or subject; it does not
perform a new broad subject search or silently erase a callback's extra writes.
Retained invoice fields can remain restricted until their recorded deadline. Original guest proofs are withdrawn,
claims cannot move, and a reused reader key cannot discover or restore the old purchase.

If physical delivery is not explicitly complete, essential delivery/contact data remain in a separate hold with
reason and review date. An owner confirms completion; paid status does not mean shipped or delivered. Review
being due does not automatically release the hold. Owner legal holds likewise have a reason and review date;
release cannot bypass unresolved financial custody, disputes or unsettled refunds.

Invoice expiry does not cancel a valid pass. Minimum access evidence stays with its original term while needed.
Account erasure does suppress future grants and restoration. A late capture after account erasure is held for
explicit financial recovery with no new stock or access award. Stock adjustments remain separate and reasoned.

Privacy transitions physically clear personal bytes. They preserve original purchase commitments and validate
an explicit redacted representation, rather than replacing the original invoice hash with a newly altered invoice.
New event IDs for a retired Charge are covered by purchase/source tombstones, not only webhook event deduplication.

## Restricted evidence and access

| Data | Why it remains and who can use it |
| --- | --- |
| Selected billing name/address fields | Restricted invoice profile under the order's explicit policy and holds; owners use the retained order. Erased readers and old guest proofs cannot retrieve it. |
| Essential contact/delivery hold | Only unfinished physical delivery. Owners use the separate delivery door and explicitly confirm completion; these fields never become invoice defaults. |
| Money, tax, payment/allocation/refund history | Checked original purchase and financial recovery while required; reader exports show safe summaries only while ownership remains valid. |
| Original account/mode/provider identities, request digests and stable refund keys | Restricted reconciliation and once-only recovery. They are excluded from reader/guest exports and retired with the eligible settled graph. |
| Operator refund reasons, authorization and audit identity | Restricted accountability for an actual financial operation, distinct from the buyer invoice profile; no transient recovery key or password is retained. |
| Access manifests, original terms and source effects | Minimum original contract evidence while access remains valid. Account erasure removes beneficiary links and reinstatement witnesses and suppresses both consumers. |
| Shared stock movements and manual actor/reason history | Independent inventory accountability. Refunds and replay do not return stock or award it again; a manual adjustment always needs its own reason. |
| Opaque replay tombstones and minimal verified-event receipts | Negative guards prevent new event IDs from recreating a retired purchase. These commitments are restricted and are not claimed to be anonymous. |

The policy does not authorize whole raw checkout responses, credentials, guest tokens or arbitrary customer fields
as invoice data. A pending financial case keeps the checked recovery evidence it needs after account erasure.
Final retirement requires the financial, delivery, legal and active-access checks to permit it.
Core then retires only already expired or revoked rows of that exact order source through its audited writer.
An active source blocks retirement; another order and complimentary grants remain independent. The producer
proves the financial/privacy graph around the write, and raw readback checks both the source and the captured
row identities so a callback cannot hide a recreated row under another scope.

## Operator and restore boundary

The native retention page is owner-only. Policy management and batch maintenance are Org-wide; adoption, holds
and delivery actions for an individual order use the selected Site. Installed-data CLI maintenance is available
independently of runtime commerce enablement; every operation checks its schema, authority and scope. Actions are bounded,
previewable and rerunnable. No additional service or always-on worker is required.

Core's reader adapter reports deleted parent counts. An invoice that remains restricted after minimization is
not a deleted parent; privacy status and retention actions describe that separately. Existing unpaid guest/order
erasure stays on its checked proof path; this does not add a new self-service account-settings flow.

Backups require operational replay. Keep erasure/retirement requests and exact policy/hold choices outside the
backup being restored. Restore in quarantine; reapply the recorded erasures and retention actions; verify the
suppression state; only then reopen webhook and customer traffic. Automatic cross-module restore orchestration
and general subject discovery remain v1.1 work. A database restore by itself does not reapply later erasures.

Seven-day raw webhook expiry remains separate from invoice retention. Local deletion/minimization does not prove
provider-side or operator backup deletion. Preview with
`php artisan kitsune:commerce-retention --org=<slug> --limit=100`; add `--apply` to perform eligible work. The
limit must be 1 through 1000.

Full local `composer ci` passed 9,788 tests / 51,577 assertions, with 47 explicit driver/condition skips; configured
PHPStan and Pint passed. Skeleton Pint and `git diff --check` passed. The focused native owner browser run passed
all 15 checks without retries, including policy versions, historical adoption, account minimization, legal/delivery
holds and Axe scans.

CI, maintainer review, merge and live-provider acceptance remain separate; the review PR records CI results.
The physical SQLite backup drill verifies manual request reapplication on a separate restored file. It does not
certify automatic restore orchestration, enforced network quarantine, another database backup format or
provider-side deletion.
