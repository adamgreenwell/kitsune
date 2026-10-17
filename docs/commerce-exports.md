# Commerce history exports

The #202 export slice is merged through #286 and provides owner downloads and an installed-data CLI. Original
test/live mode remains part of financial history; [live admission](live-stripe-admission.md) and provider
qualification are separate from export authority.
Exports read the retained purchase, payment, refund, access and privacy records; they do not fetch the current
catalogue, contact a payment/shipping provider or run reconciliation or retention maintenance.

## Audience and scope

Adam chose native owner-only admin downloads plus CLI, JSONL plus spreadsheet CSV, and financial history by
default with an explicit opt-in for permitted billing fields. The page bound, cursor and record layout below are
implementation choices, separate from those product decisions.

In **Storefront → Orders**, an owner downloads the selected Site's history. Choose JSONL or CSV, a page size
from 1 through 100, and optionally the previous page's continuation cursor. The billing checkbox starts off.
The native POST checks body CSRF and the producer independently verifies the current owner on the primary
connection. An editor, a reader and an anonymous HTTP caller cannot use this operator door. Downloads and
errors are private/no-store, have no referrer, and refuse content sniffing.

The CLI requires an exact Org slug. Omit `--site` for that Org's retained history, or provide an exact Site
handle in that Org. This is trusted local console access. An authenticated non-owner is refused, and a reader session cannot
supply system authority; a separately verified owner can still act as that owner. The command remains available for installed commerce data while runtime commerce is disabled or
stale. A missing recorded store, incompatible schema or inconsistent graph refuses instead of exporting a
partial success. A genuinely unapplied order store can produce an empty completed export.

```sh
umask 077
php artisan kitsune:commerce-export --org=golfdom --format=jsonl --limit=100 > orders-001.jsonl
php artisan kitsune:commerce-export --org=golfdom --site=golfdom-fr --format=csv --limit=100 > orders-fr.csv
```

Data alone goes to stdout. Diagnostics and the next cursor go to stderr; failure is a nonzero exit. Store files
in a private location and check the exit code and final completion record before using them. Shell redirection
can create an empty file even when the command refuses. `--include-billing` opts in to the restricted billing
profile; use it only when those invoice fields are needed for the intended operation.

## Version 1 ledger

JSONL is UTF-8 with one complete JSON object per LF-terminated line. Every object has these envelope fields:

| Field | Meaning |
| --- | --- |
| `schema`, `version` | `kitsune.commerce.export`, integer `1`. Reject an unsupported version. |
| `type` | The entity or page-control type listed below. |
| `org`, `site` | Exact Org slug and current Site handle; Site is null for Org/customer/page-level records. |
| `order_reference` | The saved order reference, scoped to its Site. Never join on this reference alone. |
| `customer_reference` | A domain-separated pseudonymous commerce-customer reference, when applicable. |
| `reference`, `parent_reference` | Local entity linkage, interpreted with Org, Site, order and record type. |
| `data` | An object of explicit scalar/null fields for that record type, never a raw database row. |

A file starts with `manifest` and ends with `complete`. Both identify the financial or financial-with-billing
profile, generation time and captured maximum order ID. The manifest states the requested page limit. Completion
states the number of order parents, `has_more` and `next_cursor`. A file without a valid completion record is
incomplete. Customer/line/refund rows do not increase the order-parent count.

| Types | Content |
| --- | --- |
| `customer`, `order` | Commerce-customer association; immutable order totals, currency, mode, tax treatment, UTC dates and saved Site presentation. |
| `line` | Original line reference, original product/content references, SKU/title, quantity, unit amount, tax calculation, net/tax/gross and original offer terms. |
| `shipping`, `payment`, `allocation` | Saved carrier/service and exact shipping calculation; confirmed amount/time; awarded/unavailable quantities and allocated refund components. |
| `automatic_refund`, `refund_attempt` | Unavailable-item obligation and local retry outcome, without provider/request capabilities. |
| `refund`, `refund_selection`, `financial_observation` | Local refund intent/outcome, selected original line and quantity with component amounts, and verified refund/dispute observations. |
| `access_claim`, `access`, `access_effect`, `financial_access`, `financial_access_effect` | Claim time, original fulfillment and current financial access state/expiry. |
| `privacy`, `billing` | Privacy/hold flags, frozen policy version/deadline; separately opted-in permitted billing field/value records. |

Line references preserve the original line identity, so two lines with the same title remain distinguishable.
Amounts ending in `_minor` are exact decimal strings in the order's saved currency. There is no float, current
price lookup, FX conversion or recalculation from today's tax settings. Dates are UTC `YYYY-MM-DDTHH:MM:SSZ` or
null. A quotation is not a charge; confirmed payment, awarded stock and refund observations remain distinct.
Original and financially revised access effects are separate records, not a promise that every original pass
still works.

A current reader's commerce reference is a deterministic Org-specific digest of its filed reader key. Guest and
erased-order references instead derive from that exact order and Site. No email lookup or host account join is
performed. Missing host readers do not destroy historical operator export; erased links are never rediscovered
through a reused key. These pseudonyms and purchase records are restricted data, not anonymous data. Customer
records can repeat on later pages; join them by their reference within the Org.

## Billing and privacy

Default files omit buyer names, addresses, email, raw reader/account keys and delivery/contact holds. Both profiles
omit credentials/ciphertext, passwords, owner keys, request/idempotency digests, bearer URLs, guest proofs, raw
provider payloads/metadata, provider identifiers, grant/source witnesses and freeform operator reasons.

Billing opt-in intersects each paid invoice's frozen policy allowlist with the billing fields that still exist.
It never fills an absent field from a host account, catalogue, other order or provider. An unknown policy, an invoice-expired receipt, already-cleared invoice fields, or an already passed deadline
without an active manual legal hold yields no billing rows. Unpaid orders do not become invoice-profile exports. An active legal hold can preserve selected fields
past the deadline; a delivery or unresolved financial hold alone does not extend the invoice field profile.
After account erasure, an owner can export only the permitted invoice survivors; the old reader and guest proof
cannot retrieve them. Delivery-purpose payloads stay behind their separate owner workflow.

Existing subject-access and exact-proof guest JSON exports keep their established shapes and ownership rules.
They already include checked financial/access/privacy summaries, including disabled/stale installed modules.
`ReaderAccount` is unchanged. The whole-reader subject-access API still returns an aggregate array; use this
paged commerce ledger for large operator portability jobs rather than materializing an Org through that API.

## Free purchase records

Free purchases have state `completed_free` and a distinct `free_completion` record, with zero amount and
`completed_at`. They have no provider payment record. Their allocation records use `free-completion` as
parent; awarded and unavailable quantities remain separate. A `free_access_correction` record names its
original line and unit number, correction revision, removal/restoration kind, reason and UTC time. These
records never imply a refund or restocking. Reader/guest JSON summaries retain their audience and exact
ownership rules; owner correction reasons appear only in the operator ledger.

Free purchase billing fields use the Org's explicitly frozen retention policy and the free-completion clock.
No legal duration is supplied. The existing personal-field opt-in, minimization, holds and negative replay
boundaries apply. The producer yields individual normalized rows while assembling the checked, bounded
artifact, so a large unit history is not first materialized as an unbounded array. An order whose complete
history exceeds the 16 MiB artifact bound is refused without a partial download; reducing the number of
order parents cannot make an individually oversized history fit.

## CSV and spreadsheets

CSV contains the same normalized entity records, one row per entity, with a fixed header. The envelope columns
come first, followed by the explicit version-1 scalar data columns and `spreadsheet_escaped_fields`. Columns that
do not apply to a type are empty; nested arrays are represented by linked records rather than JSON in a cell.
All cells are double-quoted; embedded quotes are doubled; records use CRLF; there is no byte-order mark.
Booleans are `true`/`false`. CSV empty cells do not distinguish null from an empty string; JSONL preserves the
exact types and is the canonical structured archive.

Untrusted text that could begin a spreadsheet formula, including a prefix hidden behind whitespace or invisible
characters, receives a leading apostrophe. `spreadsheet_escaped_fields` lists the affected column names, so a
consumer can remove exactly that added apostrophe when reconstructing the original string. JSONL keeps the
original text. Import references and minor-unit columns as text when opening CSV in a spreadsheet: automatic
number/date detection can otherwise change identifiers or round long exact amounts.

## Bounds, consistency and portability

Each artifact contains at most 100 orders and 16 MiB. Reduce the page limit if a page reaches the byte bound.
The entire artifact is validated before HTTP headers or CLI data are sent; a later bad order does not leave an
earlier apparently successful page. The final owner hydration is followed by raw custody and installed-receipt
checks. No plaintext temporary export file, queue, scheduler or external service is required.

The continuation cursor is a bounded, versioned locator tied to Org, selected Site and billing profile. It is
unsigned and grants no authority; every continuation performs the same scope and owner/system checks. It may
be used with either format and a different page limit. Changing the scope or billing profile requires a new
export. The captured maximum excludes subsequently appended orders; retention/erasure between pages can remove
older records. Pages recheck current retained custody and are not one immutable snapshot across a whole job.
Org-wide pages lock Sites in bounded batches before Orders; memory is bounded while work grows with Site count.
For a large multisite Org, explicit Site exports can reduce that work.

This is an audience-limited history/portability format. It is not a database backup, an executable refund request,
a legal invoice numbering system or an automatic import. Host-owned account profiles have their existing reader
export path. Deleted catalogue products need not exist, and fully retired orders are not reconstructed from replay
tombstones. Restoring a database still requires the quarantine and manual erasure reapplication described in the
[retention guide](paid-retention-and-privacy.md); an export file does not replace that process or prove deletion
of provider/backup copies. Live-provider admission, deployment and publication remain separate acceptance gates.
