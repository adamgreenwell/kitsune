# Revision growth: snapshots and a bounded diff experiment

Measured 2026-10-23 for issue #262, against main `4e6f9059d841533b7ff45e18685da2c7022392ab`.
Keep the current fifty-snapshot representation. This experiment finds a storage opportunity in predominantly scalar histories, but its simplest diff design trades that saving for replay, history-list, erasure and migration work. It does not justify changing the production format. A production implementation would need its own measured proposal.

The [numeric record](revision-growth.json) contains all nine cases, checkpoint intervals, medians, P95s, descriptive bootstrap intervals, migration footprints, controls and exact experiment-source hashes. No production data, database exports or private storage is included. This numeric record describes the measurement run; full Composer/Pint validation and PR readiness are separate gates.

## Method and validity

Nine synthetic, field-rule-valid histories cross 2 KiB, 32 KiB and 256 KiB rich-text inputs with three edit patterns. Every history makes 1,000 edits through actual audited Entry writes and the form's relation reconciliation, retaining exactly fifty revisions. The initial state and all writes validate scalar and array-element field rules before the timer, including current-record slug exclusion and scoped relations. Two orgs with two sites each, an org-shared entry, both read boundaries, ordered references to two media entries, personal inline text/rich text, a promoted personal slug, a personal relation, author/identity metadata and retained unsanitized originals are present.

The predominantly scalar case changes title each edit and the personal rich-text note every 25 edits. The insert case changes a short insertion in a fixed-size baseline body; it is not unbounded cumulative growth. The replace case changes the whole body and reverses media order on alternating edits. These cover authorable components and contrasting edit shapes; they are not a measured distribution of editorial behavior. Media are references, not repeated binary payloads. Sanitization and direction handling run normally.

The disposable prototype checkpoints every 1/5/10/25/50 versions, diffs object keys and replaces whole strings and ordered lists. It rebases the oldest retained row on every prune, in the same persisted transaction as append/delete. Checksums detect accidental damage; they do not authenticate malicious storage. The independent oracle is the application's recorded full states, not another invocation of the candidate decoder.

The first pilot accidentally put a 2,048-character body in default short text, whose rule caps it at 255. Those figures are excluded. The final corpus uses rich text and includes an explicit negative control rejecting the old input. The prototype also initially used an empty relation list for erasure; a failing check and current-code inspection corrected it to `null`, meaning unknown. All final measurements below include that correction.

Local environment: Mac16,5, 16 logical CPUs, 128 GiB RAM; exact PHP/SQLite versions are in the numeric record. One sample per case, run sequentially on unchanged source, warm caches, no imposed host CPU/RAM limits, no cold-cache or host-load control. The combined large-case harness exhausted its 128 MiB PHP limit while holding multiple candidate/oracle copies. The isolated current 50-version erasure passed 408 checks at 128 MiB, peaking at 69,730,304 bytes. Only failed/not-run large cases were continued with a 512 MiB experiment-process limit; six successful small/medium cases at 128 MiB were reused. No product setting changed. Per-case combined-harness peak memory is in the numeric record and is not a product-memory requirement. The raw runner flag `resource_limits_applied:false` refers to external host CPU/RAM constraints; the PHP process caps above are reported separately. This is not ADR-027 floor qualification or evidence for PostgreSQL/MySQL/MariaDB. Reads/lists use 25 warm samples per target; guarded restores 3; erasure/migration 1. Median confidence intervals use 250 deterministic bootstrap resamples and describe these samples only. Restore tails and one-off erasure/migration timings are exploratory; no autocorrelation or host-distribution inference is claimed.

## Retained logical bytes

KiB after 1,000 edits / 50 retained versions. Snapshot bytes encode the same full state and metadata; prototype bytes include envelopes/checksums/identity. These are logical encodings, not disk savings.

| Case | Snapshots KiB | Interval1 KiB | Interval10 KiB | Interval50 KiB | Interval50 vs snapshot |
| --- | ---: | ---: | ---: | ---: | ---: |
| small-scalar | 120.3 | 136.4 | 35.0 | 25.7 | -78.6% |
| small-insert | 124.5 | 140.7 | 126.8 | 125.5 | +0.8% |
| small-replace | 123.8 | 139.9 | 128.3 | 127.2 | +2.8% |
| medium-scalar | 1620.3 | 1636.4 | 215.1 | 85.9 | -94.7% |
| medium-insert | 1624.5 | 1640.7 | 1626.8 | 1625.5 | +0.1% |
| medium-replace | 1623.8 | 1639.9 | 1628.6 | 1627.5 | +0.2% |
| large-scalar | 12820.3 | 12836.4 | 1559.8 | 534.6 | -95.8% |
| large-insert | 12824.5 | 12840.7 | 12827.9 | 12826.6 | +0.0% |
| large-replace | 12823.8 | 12839.9 | 12829.5 | 12828.6 | +0.0% |

A short edit inside a long body still replaces that whole string in this prototype. Larger checkpoint intervals therefore do not make frequent body edits cheap. No conclusion is made about a text-diff algorithm that was not implemented.

Allocated revision-table/index pages, separate prototype-file pages and per-migration database/WAL/freelist observations are in the numeric record. The application's database includes schema, current entries and audit rows; a tiny history-only candidate file is not a fair whole-database replacement comparison. Pruning, rewriting and dropping tables can free pages without shrinking the file.

## Read and history-list costs

Warm median milliseconds. Middle targets use the 25th of 50 retained states. Snapshot reads use the scoped entry's revision relation. A candidate history list returns the same id/title/author/created_at fields by reconstructing all 50 versions; no imaginary metadata sidecar is credited.

| Case | Snapshot read | Interval10 read | Interval50 read | Snapshot list | Interval50 list |
| --- | ---: | ---: | ---: | ---: | ---: |
| small-scalar | 0.092 | 0.163 | 0.608 | 0.539 | 27.215 |
| small-insert | 0.089 | 0.212 | 0.868 | 0.506 | 34.924 |
| small-replace | 0.093 | 0.219 | 0.898 | 0.519 | 36.219 |
| medium-scalar | 0.155 | 1.282 | 5.140 | 0.596 | 248.260 |
| medium-insert | 0.155 | 2.115 | 9.263 | 0.584 | 361.993 |
| medium-replace | 0.155 | 2.110 | 9.047 | 0.615 | 364.627 |
| large-scalar | 0.586 | 9.678 | 38.944 | 0.986 | 1867.629 |
| large-insert | 0.549 | 15.355 | 65.852 | 0.920 | 2702.666 |
| large-replace | 0.580 | 15.531 | 67.667 | 0.978 | 2778.205 |

The candidate currently calls its independent reader for every listed version, so prefixes are replayed repeatedly. These high list numbers describe this implementation, not a lower bound for diff storage. A streaming single-pass decoder could reduce that work without a sidecar; it was not implemented or measured here. A metadata sidecar is another possibility, with its own atomicity, identity, erasure and migration obligations. Neither improvement is credited without measurement.

## Writes, actual restores and erasure

Median write/restore and single erasure milliseconds. The write columns deliberately name different scopes: current application write includes audit/model/form/reconciliation; candidate write is codec+SQLite persistence of an already-recorded state. Their ratio is not a production speedup.

| Case | Application write | Interval10 codec+DB write | Snapshot restore | Interval10 adapter restore | Actual erasure | Interval10 history rewrite |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| small-scalar | 2.939 | 0.520 | 3.495 | 4.063 | 40.196 | 7.673 |
| small-insert | 3.655 | 0.554 | 2.817 | 3.376 | 37.262 | 9.574 |
| small-replace | 5.045 | 0.569 | 3.781 | 4.037 | 37.114 | 9.990 |
| medium-scalar | 3.529 | 2.315 | 4.730 | 6.577 | 131.588 | 66.050 |
| medium-insert | 5.709 | 3.243 | 5.081 | 7.230 | 126.094 | 94.816 |
| medium-replace | 7.523 | 3.258 | 6.031 | 8.167 | 130.979 | 95.536 |
| large-scalar | 5.712 | 15.329 | 18.019 | 27.851 | 816.028 | 482.266 |
| large-insert | 17.064 | 21.067 | 17.353 | 33.836 | 790.412 | 698.734 |
| large-replace | 19.121 | 21.304 | 19.152 | 37.132 | 799.433 | 705.322 |

Copy setup is outside restore timing. Every timed restore begins/ends at outer transaction level zero and commits. Current `Entry::restoreRevision()` re-reads under its row lock; handing it an in-memory reconstructed model would not test candidate data. The candidate adapter counts decode, temporary full-row materialization, the same guarded restore/current full-snapshot recorder and removal of the temporary row. It is an adapter measurement, not an integrated diff writer. Oldest/middle/newest targets are checked separately; newest may be a genuine no-op.

Actual `Entry::redactField()` sweeps personal inline values, rich-text originals, the promoted slug and relation history. Candidate erasure rewrites all checkpoints/operations and also uses independent per-version reads, repeating replay work. A streaming rewrite could reduce that cost; these figures do not establish an unavoidable cost of every diff design. Every retained state is checked against an independently redacted oracle; only revision `metadata.updated_at` is normalized because actual rewrites update it and the codec has no clock. Schema, original revision/entry identity, created_at, content and list order remain exact.

The actual erasure timer includes four separate guarded field sweeps and live-row updates. The candidate timer combines four fields into one history rewrite and excludes live-row/model/field-resolution work. Like the write columns, these erasure columns have different work scopes and must not be divided into a production speedup.

All 50 erased versions in all six representations are restored, with replacement contacts installed first: 300 checks per case / 2,700 total. No erased value/original/link returns, and a null (unknown) historical relation does not delete a later replacement. This proves logical live/history behavior, not physical media sanitization: SQLite file-byte canary observations are included separately. Residual canaries were observed in some snapshot files and all tested prototype files after logical erasure; absence of the marker string in another file is not proof of secure physical erasure. Backups, WAL/freelist pages and restored backup erasure replay remain operator/storage concerns under ADR-020.

## Persisted recovery and migration

Every interval/case refuses a damaged or missing persisted envelope and resumes at the next intact independent checkpoint. Exceptions injected after pruning deletion and first rewrite roll back to the exact old rows, verified through a reopened connection. A separate PHP process exits with an open SQLite transaction after deletion; reopening retains the old committed rows. These are exception/process-teardown controls, not power-loss testing or a concurrent-writer protocol.

A staged migration keeps original snapshots until cutover. Within one SQLite transaction it checks the source digest, encodes all 50 states and preserves original revision IDs. Halfway failure leaves no partial candidate table; a changed source token is refused; restart produces identical rows without duplicates. Reverse codec staging restores every full state/identity. The numeric record shows before/during/after database/WAL footprints and freed pages after dropping staging and truncating WAL. Coexisting old/new tables and journaling create transient storage rather than an immediate saving.

The staging experiment does not switch production readers/writers, orchestrate multiple entries, perform online schema migration, handle concurrent edits or qualify crash recovery on server engines. Those costs remain requirements for any future implementation, not hidden completed work.

## Decision

Keep current full snapshots and fifty-version retention. The measurement answers the format question narrowly: this bounded object-key prototype can save logical bytes for mostly scalar histories, while frequent rich-text edits provide little benefit and replay/list work and erasure/migration obligations are real. There is no demonstrated need to add a production diff format now, so no implementation issue is opened merely to route around ADR-020.

A future proposal should bring a representative workload and storage-pressure evidence, then measure any text-diff/metadata-sidecar design, one integrated writer, bounded replay, erasure of every copy, interruption/concurrency, cutover/reverse migration and the supported engines/floor. The present prototype stays opt-in development measurement code outside the runtime package and default test discovery.

## Reproduce

Install normal development dependencies, then use a new private parent outside the checkout. The requested child must not already exist; the runner refuses existing output. Use a supported PHP ^8.4 runtime (this measurement used 8.5.10). Run from the repository root, one case at a time:

```sh
run_root=$(mktemp -d /tmp/kitsune-revision-growth.XXXXXX)
KITSUNE_REVISION_BENCH_OUT="$run_root/small-scalar" \
KITSUNE_REVISION_BENCH_CASE=small-scalar \
php -d memory_limit=512M vendor/bin/pest bin/benchmarks/RevisionGrowthMeasurement.php
```

Repeat with small/medium/large crossed with scalar/insert/replace and distinct output children. Only newly created, owned SQLite files are used; ambient external database settings are ignored. Results are numeric JSON, but temporary databases contain synthetic author/history data: audit ownership and remove those databases/storage/caches when finished. No Node, server database, container or provider is needed.
