# Schema upgrade baseline audit

**Audit only · 2026-10-21 · [#217](https://github.com/adamgreenwell/kitsune/issues/217).**

This inventories source history and recorded installation evidence at
[`afc7ddd78b42d010b195c898ef901c3228d9063d`](https://github.com/adamgreenwell/kitsune/commit/afc7ddd78b42d010b195c898ef901c3228d9063d).
It chooses no supported upgrade baseline or pre-v1.0 migration policy, implements
no repair, and proves no retained-data upgrade. #217 remains open: its policy,
implementation and SQLite/PostgreSQL/MySQL/MariaDB upgrade acceptance still need
work. No deployment host was contacted and no database migrated for this audit.

## What the history establishes

The original migration name is recorded once. A later `migrate --force` skips it;
editing its source does not apply the edit to that database. The existing
[open decision](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/docs/decision-log.md#L11520-L11525)
identifies six PR-level edits after #19. Following the full commit graph matters:
individual feature branches had different combinations, which merges combined.
`git log --follow` alone omits some of those merged combinations.

| Original migration | Distinct file blobs | Distinct code shapes | Current blob |
|---|---:|---:|---|
| [Tenancy tables](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/database/migrations/0001_01_01_000000_create_kitsune_tenancy_tables.php) | 4 | 3 | `0e0484f8f9239684de791d866a85b0eeba386c4f` |
| [Schema tables](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/database/migrations/0001_01_01_000001_create_kitsune_schema_tables.php) | 14 | 13 | `a97623777989c0830b4f96bc7f908e538cb97044` |
| [Audit tables](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/database/migrations/0001_01_01_000002_create_kitsune_audit_tables.php) | 3 | 3 | `94c94c4cef18d980aee60cec4087fcd390a6c57a` |

A **code shape** here is a PHP token stream with comments and whitespace removed,
not a database schema introspected on an engine. Historical blueprint/media
migration edits have one code shape each: their differences are comments. The
other core migrations have one historical file blob each at this source.

### Schema-table branch shapes

These thirteen source combinations are deliberately distinguished from installs.
An origin on a feature branch could have one of them; its existence in Git proves
neither that anyone migrated it nor that its later database stayed unchanged.
The commit links pin the first occurrence found in the full graph. Dates alone
are insufficient: author and committer dates differ for some revisions.

| Shape | Source provenance | Distinguishing contents |
|---|---|---|
| H00 | [`9fa4e068`](https://github.com/adamgreenwell/kitsune/commit/9fa4e068cf6eb08776739251c53e963b843491e1) | Original schema, including `translation_scope`; none of the later additions below. |
| H01 | [`c153fb38`](https://github.com/adamgreenwell/kitsune/commit/c153fb382fb4b1ae9a6e11433ddbcf1fc8ce199e) | H00 plus subject-field nomination and its FK. |
| H02 | [`61b7b886`](https://github.com/adamgreenwell/kitsune/commit/61b7b886ca541282943b600a341e98f96530b929) | H01 plus revision title, slug, publication time and `(entry_id, id)` index. |
| H03 | [`f34cb929`](https://github.com/adamgreenwell/kitsune/commit/f34cb929f2e577298974c678dda9a46c8f4e7108) | H02 plus **nullable** revision `entry_type_id` and FK. |
| H04 | [`6b48f93b`](https://github.com/adamgreenwell/kitsune/commit/6b48f93b03075db71e7442817f8e07aac857fc3d) | Revision type becomes **NOT NULL**; nullable relation snapshot added. |
| H05 | [`07b4deb7`](https://github.com/adamgreenwell/kitsune/commit/07b4deb75f209518f2716c53baf31e970df2271d) | H04 plus unsanitized originals, **without** promotion ownership. |
| H06 | [`e999d5a2`](https://github.com/adamgreenwell/kitsune/commit/e999d5a25895a9d18257543c5c493b447834fd13) | H04 plus promotion ownership, **without** unsanitized originals. |
| H07 | [`9444daae`](https://github.com/adamgreenwell/kitsune/commit/9444daae37f8d80819a8fd276c5122abf112b03f) | H04 without `translation_scope`; neither originals nor ownership. |
| H08 | [`f308a0e3`](https://github.com/adamgreenwell/kitsune/commit/f308a0e3ebecf1f15d2bc4c3b81ff8fd75f59a74) | Merge combines originals and ownership; `translation_scope` still present. |
| H09 | [`607307ff`](https://github.com/adamgreenwell/kitsune/commit/607307ff98ab708c57766dbc5ab391f81369140d) | Ownership, no originals, no `translation_scope`. |
| H10 | [`efd7f28f`](https://github.com/adamgreenwell/kitsune/commit/efd7f28fc8021696451e04de95bc81d84a86f807) | Originals and ownership, no `translation_scope`; neither later sort index. |
| H11 | [`a505d462`](https://github.com/adamgreenwell/kitsune/commit/a505d462f6ed4cfb2fbeecaf818ef3784e83edc7) | H10 plus entries `(site_id, entry_type_id, updated_at)`. |
| H12 | [`6d8079e3`](https://github.com/adamgreenwell/kitsune/commit/6d8079e3515fd2ffbc79b073e13372f43775893d) | H11 plus entries `(site_id, updated_at)`; current shape. |

### Main-history boundaries

Seven of those schema-table shapes reached first-parent main. Including the
tenancy and audit migrations produces **eleven main-history change boundaries**,
not thirteen or eleven known installations. This table names actual source
combinations rather than assuming every possible combination existed.

| Main commit | Change reaching main | Schema-table shape |
|---|---|---|
| [`29cb092b`](https://github.com/adamgreenwell/kitsune/commit/29cb092b7696322b26b93f97dc4cca97b53c197f), #19 | Original tenancy and schema tables. | H00 |
| [`23fe178a`](https://github.com/adamgreenwell/kitsune/commit/23fe178a25eeb9928d6c28a99b29b8463403b63a), #20 | Globally unique Site route `slug`. | H00 |
| [`2a0a7079`](https://github.com/adamgreenwell/kitsune/commit/2a0a7079bda8e2b5406903fba0a2a499ad675226), #30 | Subject-field nomination. | H01 |
| [`07bb3c8c`](https://github.com/adamgreenwell/kitsune/commit/07bb3c8c6cc49dd0d656c1b2f75826c261995bc9), #33 | Audit tables; `target_type` already nullable on main. | H01 |
| [`e90a6a5d`](https://github.com/adamgreenwell/kitsune/commit/e90a6a5db0e9eab67a1bc21d2272652cbbe47f66), #35 | Revision snapshots and promotion ownership; no originals yet. | H06 |
| [`2940c658`](https://github.com/adamgreenwell/kitsune/commit/2940c6589bd2d2ff4a9fb2adc92d8339c63b7c7d), #43 | Adds unsanitized originals. | H08 |
| [`efd7f28f`](https://github.com/adamgreenwell/kitsune/commit/efd7f28fc8021696451e04de95bc81d84a86f807), #45 | Removes unused `translation_scope`. | H10 |
| [`ee112c18`](https://github.com/adamgreenwell/kitsune/commit/ee112c180fa21a4da5c3329ac803fc1ec5ee91a7), #51 | Canonical host/prefix columns and global unique pair. | H10 |
| [`a505d462`](https://github.com/adamgreenwell/kitsune/commit/a505d462f6ed4cfb2fbeecaf818ef3784e83edc7), #82 | Type-list sort index. | H11 |
| [`0c68f7fa`](https://github.com/adamgreenwell/kitsune/commit/0c68f7fa390886431f1bc65e045beb0bd3039a25), #83 | Polymorphic audit actor, string actor/target IDs and actor index. | H11 |
| [`6d8079e3`](https://github.com/adamgreenwell/kitsune/commit/6d8079e3515fd2ffbc79b073e13372f43775893d), #102 | Recent-entry sort index; final shapes of all three originals. | H12 |

## Tags and recorded installations

Remote `origin` annotated tag objects and peeled commits matched local tags at
audit time. All three tags contain the **exact current blobs of all three
original migrations above**. Their dates below are tagger dates, not installation
or GitHub Release publication dates.

| Tag | Remote tag object → commit | Tagger date |
|---|---|---|
| [v0.1.0](https://github.com/adamgreenwell/kitsune/tree/a02496109e9f362df59de7e54f1d6dbdb4afc46d) | `ed669b6fcd6ceaa0d0d8ae217476b61927fd4251` → `a02496109e9f362df59de7e54f1d6dbdb4afc46d` | 2026-09-17 10:45:41 −04:00 |
| [v0.1.1](https://github.com/adamgreenwell/kitsune/tree/9053113852810af005e9bbb0c9209c86b1298b51) | `27cdacacfa963a5d32f310d667e78b90d855e2f5` → `9053113852810af005e9bbb0c9209c86b1298b51` | 2026-09-20 14:30:42 −04:00 |
| [v0.2.0](https://github.com/adamgreenwell/kitsune/tree/f485ac0de6c73a7fe5a03197324020f0650beb13) | `b7fc47244c061f5a7870d096c761dac5d375b51e` → `f485ac0de6c73a7fe5a03197324020f0650beb13` | 2026-09-21 16:43:34 −04:00 |

The [roadmap records Packagist publication](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/docs/roadmap.md#L69),
but the repository's GitHub Releases and Deployments APIs returned empty lists.
Neither API result proves no installations exist. A tag's current source also
does not prove an installation started fresh there: an older database could
subsequently have run that tag.

There is concrete **historical recorded stage evidence** in
[PR #115's verification](https://github.com/adamgreenwell/kitsune/pull/115):

- First deploy of `e729bab14fae1696dfe1481fa4866fa0608399d6` ran eleven migrations
  on PostgreSQL 18.6, activating `releases/20260915221028` on September 15.
- A redeploy used the same commit; the later reviewed
  `0e4b0f9ab6322c4760902fd7a05301a472aa87de` deployment reported “Nothing to migrate.”
- Both exact commits contain all three current original-migration blobs. This
  receipt therefore records an installation **after** the final September 14
  shape. It provides no evidence of a populated earlier shape.

Those are the PR author's historical host receipts, not newly read migration
rows or database schemas. No populated pre-final-shape installation was
established by this audit. A local test fixture or historical source commit is
not a substitute for an operator-confirmed installation baseline.

## Exact differences and recovery limits

The following applies to an installation actually migrated before each change.
The migration ledger alone cannot distinguish these shapes. Types, nullability,
index columns/order, FK actions and retained rows must also be inventoried.

| Difference in an older shape | Current behavior affected | Recoverable information and limit |
|---|---|---|
| `sites.slug`: nonnullable string with global unique constraint absent. | Admin URLs use [`slug` as route key](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/Site.php#L1470-L1473). | Existing org-local handles can collide across Orgs. No globally unique route name or permission to rename one can be inferred. |
| Nullable `sites.canonical_host`, `path_prefix` and their global unique pair absent. | [Public resolution](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Http/Middleware/ResolveSiteFromRequest.php#L72-L145) queries them before Org context. | Retained `base_url`/`url_strategy` allow a candidate derivation. Equivalent URLs, overlaps, invalid inputs and cross-Org conflicts must be checked before DDL/backfill; no silent winner or blanket nulling. |
| Nullable `entry_types.subject_field_id` FK to `fields.id`, `ON DELETE SET NULL`, absent. | Subject nomination and subject-access/erasure tools. The FK is added after `fields` to break the table cycle. | A new null can mean no nomination. The operator's intended identifying field cannot be guessed. |
| Nullable revision `title`, `slug`, `published_at`, and `(entry_id, id)` index absent. | [Revision snapshot/restore](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/EntryRevision.php#L59-L74), newest-version lookup. | The index can be built from existing keys. Missing old titles, slugs and dates are unknown; copying the entry's current values invents history. |
| Revision `entry_type_id` absent, or present nullable rather than current NOT NULL FK to `entry_types.id`, `ON DELETE CASCADE`. | [Restore refuses a different schema](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/Entry.php#L3507-L3529). | Current entry type is mutable and does not prove a revision's original type. A new NOT NULL constraint needs a policy for existing unknowns, not a guessed backfill. |
| Nullable revision `relation_state` JSON absent. | Relation restore and erasure history. | Historical links/order cannot be recreated from current pivots. [Null means unknown; `[]` means known empty](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/Entry.php#L3581-L3588). Substituting `[]` would clear relations on restore. |
| Nullable revision `unsanitized_values` JSON absent. | Retaining pre-sanitization originals; [erasure must sweep them](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/EntryRevision.php#L152-L183). | Sanitized values do not recover raw originals. A null must not claim the original bytes were retained; originals stay outside the restore snapshot. |
| Nullable `entries.promoted_by` JSON absent. | Promoted-column erasure ownership. | [Unattributed values fail closed](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/src/Models/Entry.php#L2544-L2558). Current field mappings after retyping do not identify the historical writer. |
| Extra `field_storage.translation_scope` string, default `per_locale`, remains. | No current consumer; fresh schema omits it. | Stored defaults never proved implemented i18n. Preservation or explicit removal is a policy choice; a fresh-schema equality assertion does not authorize dropping retained values. |
| Entries indexes `(site_id, entry_type_id, updated_at)` and/or `(site_id, updated_at)` absent. | Default content-list sort and recent-entry dashboard cost. | Indexes use existing values; no historical metadata is needed. The [later shared-media migration](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/database/migrations/0001_01_01_000009_index_entries_by_org_for_shared_media.php) adds separate Org-leading indexes and does not repair these. |
| Audit `target_type` still NOT NULL (branch-only original). | Targetless audit actions such as settings/sign-in/export. | Loosening the column preserves rows; no missing historic target should be invented. |
| Audit `actor_type` absent; `actor_id`/`target_id` integer-family instead of nullable strings; actor lookup index `(org_id, actor_id)` instead of `(org_id, actor_type, actor_id)`. | Current host identity may use UUID/string IDs. Old strict-engine columns can reject audited writes. | Existing numbers can retain their textual value. Missing historic actor class cannot be inferred from the current auth provider or relabelled as known in append-only history. |

These additions are not repaired by a later core migration at the audited
source. [`is_media`'s separate migration](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/packages/core/database/migrations/0001_01_01_000008_add_is_media_to_entry_types.php)
documents the same risk and handles its own classification; it does not repair
these earlier differences. `kitsune:schema-sync` handles field-generated storage,
not a replay of previously recorded platform migrations.

## Decisions still needed

No option in this list is selected by this document.

1. **Supported origins.** Named fresh-tag origins, verified pre-tag main
   boundaries, selected branch shapes, or case-by-case inspected databases?
   Obtain operator-confirmed receipts, schema/ledger fingerprints and backups for
   any legacy installation to support. A tag alone does not identify its origin.
2. **Pre-v1.0 policy.** When do original migrations become immutable? Is a
   rebuild-only path acceptable for disposable pre-alpha databases, and what
   separate path preserves installations with data?
3. **Unknown history.** Decide how unknown revision type and snapshot data remain
   preserved and safely non-restorable, or how independently evidenced operator
   recovery works. Do not claim historical fidelity from current mutable rows.
4. **Unknown ownership/identity.** Choose honest handling of old promotion
   ownership, subject nomination and audit actor class. No current configuration
   supplies historical attribution automatically.
5. **Conflicting Site claims.** Define operator-assisted resolution before global
   uniqueness/overlap enforcement, without choosing a winning Org or silently
   changing URLs.
6. **Obsolete columns and DDL operations.** Decide preservation/removal of
   `translation_scope`, permitted downtime/concurrent old-release writes, backup
   and recovery requirements, and partial-DDL retry behavior.

## Fixture plan for the subsequent implementation

1. **Fingerprint before support claims.** Capture column types/nullability,
   index columns/order, FK targets/actions and migration rows together. Select
   actual main-boundary combinations; add a branch-only shape only when covered
   by the chosen policy. Do not generate an imaginary Cartesian product.
2. **Retain source-shaped data.** Create an empty and populated database for each
   supported origin with the original migration name already recorded. Include
   its then-existing migrations and apply subsequent migrations during upgrade.
   Include multiple Orgs/Sites, shared entries, revisions before/after retyping,
   unknown relation history, attributed/unattributed promotion, erased originals,
   targetless audits, host string IDs and conflicting/equivalent Site claims.
3. **Prove the skipped edit first.** On disposable fixtures, demonstrate that
   ordinary migration skips the recorded original and the affected current
   behavior cannot work as intended. Run the eventual upgrade through the normal
   [`release.sh` path](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/deploy/release.sh#L277),
   never `migrate:fresh`. The existing [release-script test boundary](https://github.com/adamgreenwell/kitsune/blob/afc7ddd78b42d010b195c898ef901c3228d9063d/docs/decision-log.md#L2907-L2915)
   stubs migration and is not retained-data upgrade evidence.
4. **Exercise four real engines.** SQLite with FKs enabled, PostgreSQL, MySQL and
   MariaDB each need before/after row and schema witnesses, intact scope/FK
   behavior, rejection from cross-Org and cross-Site callers, and successful
   ordinary writes/restores/erasures where metadata is actually known.
5. **Refuse and recover honestly.** Cover conflict refusal before destructive
   changes, partially applied schema, retries/idempotency, MySQL/MariaDB
   nontransactional DDL and any chosen concurrent-old-release/downtime contract.
   Preserve unknown history and private data; logs must not print row payloads.
6. **Qualify the implemented source.** Run `composer ci`, skeleton Pint, focused
   native upgrade suites and affected admin browser shapes. Fresh-schema tests
   alone do not satisfy #217. This audit executes none of that upgrade plan.

## Reproducing the inventory

Use the pinned audit source, not a moving `main`. These commands read Git and
GitHub metadata; they do not connect to deployment hosts, application databases
or payment/shipping providers:

```sh
audit_ref=afc7ddd78b42d010b195c898ef901c3228d9063d
git ls-remote --tags origin
git for-each-ref --format='%(refname:short) %(taggerdate:iso-strict) %(*objectname)' refs/tags
gh api repos/adamgreenwell/kitsune/releases
gh api 'repos/adamgreenwell/kitsune/deployments?per_page=100'
gh pr view 115 --repo adamgreenwell/kitsune --json body,mergedAt,mergeCommit
git log "$audit_ref" --first-parent --reverse --format='%H %cI %s' -- \
  packages/core/database/migrations/0001_01_01_00000{0,1,2}_*.php
```

The following prints the distinct file/code counts without losing merge
combinations. It requires Python and PHP only for research, introduces no runtime
requirement and executes no migration:

```python
import hashlib
import subprocess

ref = 'afc7ddd78b42d010b195c898ef901c3228d9063d'
lexer = '''foreach(token_get_all(stream_get_contents(STDIN)) as $t) {
    if (is_array($t)) {
        if (!in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) echo $t[1];
    } else echo $t;
}'''

def git(*args):
    return subprocess.check_output(['git', *args])

paths = git('ls-tree', '-r', '--name-only', ref,
            'packages/core/database/migrations').decode().splitlines()
for path in paths:
    commits = git('log', ref, '--full-history', '--reverse',
                  '--format=%H', '--', path).decode().splitlines()
    blobs, shapes = set(), set()
    for commit in commits:
        blob = git('rev-parse', f'{commit}:{path}').decode().strip()
        if blob in blobs:
            continue
        blobs.add(blob)
        tokens = subprocess.check_output(
            ['php', '-r', lexer], input=git('show', f'{commit}:{path}'))
        shape = hashlib.sha256(tokens).hexdigest()
        shapes.add(shape)
        print(path, commit, blob, shape)
    print(path, 'file blobs:', len(blobs), 'code shapes:', len(shapes))
```

Compare `git rev-parse "$ref:$path"` with each peeled tag and recorded stage
commit's `:$path` for the three originals. Author/committer provenance can be
read with `git show -s --format='%H %aI %cI %s' <commit>`. These source commands establish no host's current schema or historical values;
a separate inventory must identify the installation and distinguish fresh
observations from these historical receipts.
