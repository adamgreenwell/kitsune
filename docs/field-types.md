# Kitsune — Field Type Registry

**Companion to:** [`architecture.md`](architecture.md) §3. This doc specifies the field type contract and the types shipping in v1.0.
**Created:** 2026-09-07
**Status:** Design. Filament component mappings need verification against v5.4 during Phase 4.

---

## Why this document exists

The schema engine is the hardest thing in the project (roadmap Phase 4). Most of its difficulty is not the engine — it's that **every field type has to be right in four places at once**, and if the contract between those places is vague, each new type becomes a small research project.

Get this contract right and adding a field type is filling in a form. Get it wrong and the schema engine becomes twelve special cases wearing a trenchcoat.

---

## 1. The four faces

Every field type must answer four questions, and they are not independent:

| Face | Question |
|---|---|
| **Storage** | How does a value live in the database, and can it be indexed? |
| **Form** | What Filament form component edits it? |
| **Table** | What Filament table column displays it in a list? |
| **API** | How does it serialize out, deserialize in, and describe itself in a schema? |

A type that answers three of four is not shippable. The most common failure is a type that edits beautifully and cannot be queried.

---

## 2. Storage strategies

This is the real architectural content of this document. **Not every field lives in the `values` JSON column**, and pretending otherwise is how you end up unable to answer "what references this image?"

### `Promoted` — a real column on `entries`

Reserved for fields the platform itself needs to query on every request, regardless of entity type.

| Field | Column | Why promoted |
|---|---|---|
| `title` | `entries.title` | List views, global search, breadcrumbs |
| `slug` | `entries.slug` | URL resolution, uniqueness constraint |
| `status` | `entries.status` | Filtered on literally every query |
| `published_at` | `entries.published_at` | Scheduling, ordering, feeds |

Promoted fields are **not user-definable**. An entity type turns them on or off in `entry_types.settings` (`sluggable`, `publishable`), but nobody adds a fifth promoted field through the admin. That list is a platform decision and changing it is a migration.

### `Inline` — inside the `values` JSON column

The default. Scalars and small structures. Indexable via stored generated columns when `field_storage.is_indexed` is true.

```json
{ "price": 24.99, "in_stock": true, "summary": "…" }
```

Cardinality > 1 becomes a JSON array: `{"tags": ["a", "b"]}`.

### `Relational` — rows in `entry_relations`

Anything pointing at another entry. **Never** stored as an ID array in JSON.

The reason is in [`architecture.md`](architecture.md) §3, and it's worth repeating because it's the mistake every JSON-first CMS makes: a JSON array of IDs cannot answer *"what points at me?"* without a full table scan, and cascade-on-delete becomes application code that will eventually be wrong. Deleting an image should be able to tell you the eleven articles that use it. That requires a real table with a real index.

---

## 3. The contract

```php
interface FieldType
{
    // ---- Identity ----
    public static function handle(): string;          // 'text', 'number', 'relation'
    public static function label(): string;
    public static function icon(): string;

    // ---- Storage ----
    public function strategy(): StorageStrategy;      // Promoted | Inline | Relational
    public function isIndexable(): bool;
    public function supportsCardinality(): bool;

    /** SQL type for the stored generated column, or null if not indexable. */
    public function generatedColumnType(SchemaDriver $driver): ?string;

    public function toStorage(mixed $input, FieldConfig $config): mixed;
    public function fromStorage(mixed $stored, FieldConfig $config): mixed;

    // ---- UI ----
    public function formComponent(FieldConfig $config): Component;
    public function tableColumn(FieldConfig $config): Column;

    // ---- API ----
    public function toApi(mixed $stored, FieldConfig $config): mixed;
    public function fromApi(mixed $input, FieldConfig $config): mixed;
    public function apiSchema(FieldConfig $config): array;   // JSON Schema fragment

    // ---- Validation & configuration ----
    public function validationRules(FieldConfig $config): array;
    public function settingsSchema(): array;          // the "configure this field" form
}
```

### Three rules that are not negotiable

**1. `validationRules()` never returns Laravel's `unique` or `exists`.** Those rules don't go through Eloquent, so they ignore global scopes and leak across sites and orgs. Return `scopedUnique()` / `scopedExists()`. This is enforced by the plugin validation CLI (roadmap v1.2), and it fails the build.

**2. `generatedColumnType()` takes the driver and returns driver-specific SQL.** There are **three** drivers, and all three differ: Postgres and MySQL diverge on generated-column syntax *and* JSON path operators, and SQLite cannot add a STORED generated column via `ALTER TABLE` at all — it needs a VIRTUAL one, indexed as an expression index ([`architecture.md`](architecture.md) §1). No field type ever writes raw SQL directly — it asks the driver.

**3. `apiSchema()` may only publish a constraint the consumer can enforce.** A constraint the consumer cannot read is not published, and one it reads *differently* is worse — it advertises a rule the API does not apply, and nothing reports the disagreement.

This bites hardest on `text`'s `pattern`, because the same string is enforced by PCRE server-side and published as a JSON Schema `pattern`, whose dialect is ECMAScript. The two are not the same language, so `validateSettings()` refuses a pattern that cannot travel. Measured on PHP 8.4/PCRE 10.48 and Node 22, PCRE compiles and ECMAScript rejects or reinterprets all of these:

| Written | Problem | Portable form |
|---|---|---|
| `\d` `\w` `\h` `\v` | PHP's `u` modifier sets `PCRE2_UCP`, so `\d` is any Unicode digit; ECMAScript's is `[0-9]`. `\h` is horizontal whitespace here and the letter `h` there | `[0-9]`, `[A-Za-z0-9_]`, `[ \t]` |
| `(?i)` `(?>` `(?P<n>` `(?#` | Group forms ECMAScript has no parse for | `(?:` `(?=` `(?!` `(?<=` `(?<!` `(?<n>` |
| `a++` `x{2,3}+` | Possessive quantifiers | greedy or lazy |
| `(*SKIP)` `(*FAIL)` | Backtracking control verbs | — |
| `\pL` | The braceless property form | `\p{L}` |
| `\p{Arabic}` `\p{Latn}` | A bare script name or code | `\p{Script=Arabic}` |
| `\p{Xan}` `\p{L&}` | PCRE's own property inventions | `\p{Alphabetic}` etc. |
| `\p{bc=AL}` | A property class ECMAScript lacks | — |
| `\p{^L}` | PCRE's internal negation | `\P{L}` |
| `\p{lu}` `\p{Script=latin}` | PCRE matches names loosely — ignoring case, spaces and dashes — where ECMAScript is exact | `\p{Lu}`, `\p{Script=Latin}` |
| `\b` `\B` | Defined in terms of `\w`, so the engines give **opposite** answers on non-ASCII text | the ASCII definition as lookarounds (below) |
| `\a` `\e` | Control characters PCRE spells with a letter | `\x07`, `\x1B` |
| `\g{1}` `\g<1>` `\g1` | PCRE subroutine and relative-backreference forms | `\1`, or `\k<name>` |
| `\o{141}` `\101` `\00` | Octal escapes; multi-digit escapes are a syntax error under ECMAScript's `u` flag | `\x61`, `\x41` |
| `\x{41}` `\x4` | ECMAScript's hex escape is exactly two digits, and its braced form is `\u{...}` — which PCRE rejects | `\x41` |
| `\k{n}` `\k'n'` | Only `\k<name>` is shared | `\k<name>` |
| `[\1]` | A digit escape is a backreference outside a class and octal inside one, where ECMAScript rejects it | `\x01` |
| `\_` `\:` `\!` `\@` `\~` `\ ` | ECMAScript escapes only its syntax characters — `^ $ \ . * + ? ( ) [ ] { } |` — plus `/`. PCRE puts a backslash on anything and reads the character literally; 35 ASCII marks diverge | drop the backslash |
| `\-` | Portable **inside** a character class only, as a ClassEscape | `-`, or `[\-]` |
| `\c1` `\c!` | ECMAScript's control escape takes an ASCII letter; PCRE also reads a digit or punctuation there. `\cA` agrees in both | `\cA` |

Some of those rows are judgement calls rather than compile failures, and the rule that settles them is: **refuse a divergence when a portable equivalent exists, record it when refusing would remove a capability.** `\d` is refused because `[0-9]` says the same thing.

`\b` is where that rule earned its keep, by overruling the first answer. It looked like the case for recording — a word boundary has no shorthand to redirect an author to, and on ASCII content the engines agree. But the ASCII *definition* is portable when written out, and measuring it settled the question: across all 108 pattern/input combinations tried, `(?:(?<![A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<=[A-Za-z0-9_])(?![A-Za-z0-9_]))` agrees with ECMAScript's `\b` on both engines. So a portable equivalent exists, and `\b` is refused with that spelling named. Inside a character class it is left alone, because `[\b]` is the backspace character in both dialects.

`\p{L}` remains the case for the other half of the rule: refusing it would remove the ability to express a Unicode-letter constraint at all, so the construct is accepted and only its property name is screened.

The escape rows above were found by **sweeping the whole escape alphabet** on both engines — every letter, digit and punctuation mark, inside a character class and outside one — rather than by collecting reports. That is what turned up `\a` beside a reported `\e`, and `\00` after a first fix had exempted `\0`. The sweep is kept as a test, so the surface stays closed as either engine moves.

⚠️ Its *coverage* has been the recurring defect, not its logic. It missed punctuation, which let `\_`, `\:` and `\!` through a screen built to catch exactly them; and it enumerated only the first character after the backslash, which could never find `\c1` — because `\c` alone does not compile in PCRE and `\cA` compiles in both, so that divergence lives one character further along. It now pairs every multi-character family (`\c`, `\x`, `\k`, `\p`, `\g`, `\o`) with every character in the alphabet. **An alphabet with a hole in it is a list of known offenders wearing a sweep's clothes** — so the alphabet is the part worth reviewing.

What is *portable* is allowlisted, not what is broken. There are ~170 Unicode scripts and a list naming them to refuse them would go stale on every Unicode release — publishing a schema no consumer can compile. A list of what travels goes stale in the other direction: an author is refused, with a message naming what is allowed, and a maintainer adds the name.

That cost is real and it landed immediately — the first list omitted `LC`, the Cased_Letter group, which both engines accept, so `\p{LC}` was refused for nothing. So the test in `tests/Core/Filament/EntryTypeBuilderGuardsTest.php` runs **both** directions against the two engines: every name the allowlist permits must compile in both, and every name in a candidate corpus that both engines accept must be one the screen permits. The first direction catches a PCRE or V8 release that moves the boundary; the second catches the omission a list of permitted things is prone to. Neither can be caught by reading the specifications, which is why they are measured.

---

## 4. Types shipping in v1.0

Twelve types. Deliberately small — every one added before the API freeze is a permanent maintenance obligation.

| Handle | Strategy | Indexable | Cardinality | Notes |
|---|---|---|---|---|
| `text` | Inline | ✅ | ✅ | Single line. `maxLength`, optional pattern |
| `textarea` | Inline | ❌ | ✅ | Plain multi-line |
| `rich_text` | Inline | ❌ | ❌ | Sanitized HTML — see §6 |
| `number` | Inline | ✅ | ✅ | `integer` \| `decimal`, precision, min/max, step. Bounded by its projection: `integer` by signed BIGINT, `decimal` by `10^precision` |
| `boolean` | Inline | ✅ | ❌ | |
| `date` | Inline | ✅ | ✅ | Date only |
| `datetime` | Inline | ✅ | ✅ | Stored UTC, displayed in the site's timezone |
| `select` | Inline | ✅ | ❌ | Options from `settings`, or from an entry type |
| `multi_select` | Inline | ❌ | — | Always an array; cardinality is intrinsic |
| `relation` | **Relational** | via table | ✅ | Target entry types constrained in `settings` |
| `slug` | **Promoted** | ✅ (column) | ❌ | `scopedUnique` per (site, entry_type) |
| `json` | Inline | ❌ | ❌ | Escape hatch. See §6 |

### Deliberately deferred

| Type | Deferred to | Why |
|---|---|---|
| `repeater` | v1.1 | Nested field groups are the single hardest type: recursive validation, no meaningful indexing, and revision diffs get ugly. Worth doing properly rather than early |
| `code` | v1.1 | Needs a real editor component and a syntax-highlighting payload; no core dependency should pull that in yet |
| `color`, `email`, `url`, `phone` | v1.1 | All are `text` with validation and a nicer widget. Real, but not load-bearing |
| `table` | v1.2 | A repeater in a trenchcoat. Same problems, less justification |
| `geo` | post-1.0 | Wants PostGIS to be worth anything, which contradicts MySQL parity |

**`user` is deliberately not a field type.** Users are not entries and never will be — they're an auth concern with different lifecycle, different privacy obligations, and a different deletion story (GDPR erasure of a user must not cascade-delete their articles). Use `relation` to a `person` entry type if you need editorial bylines.

---

## 5. Media are entries

**There is no separate media subsystem.** An uploaded file is an entry of a system entry type — `image`, `document`, `video` — carrying its own fields for alt text, caption, credit, rights, expiry, and anything else an org adds.

The bytes live in a companion table:

```
media_files
  id
  entry_id        FK, unique          -- 1:1 with its media entry
  disk            string              -- Flysystem disk
  path            string
  mime            string
  size_bytes      bigint
  checksum        string              -- dedupe + integrity
  width, height   int nullable
  duration_ms     int nullable
  created_at
```

A "media picker" field is therefore just **`relation` constrained to media entry types**. No new storage strategy, no second permission model, no parallel search index.

Three things fall out of this for free, and they're the reason it's worth doing:

1. **The DAM use case is a blueprint, not a subsystem.** Add fields to the `image` type — rights holder, licence expiry, usage notes — and you have digital asset management. That was one of the four use cases in the project's premise, and it costs nothing extra.
2. **Revisions, permissions, audit and tenancy all apply to media automatically**, because media are entries and entries already have all four.
3. **"What uses this image?" is a query on `entry_relations`**, not a full-text search for the filename across every JSON blob.

The trade-off, stated honestly: an `entries` row per asset makes the table bigger than a dedicated media table would, and bulk upload of 10,000 assets writes 10,000 entries plus 10,000 relations. Acceptable — but it's a real number to watch in the Phase 1 storage benchmark.

---

## 6. The two types that need security review

### `rich_text`

User-supplied HTML rendered on public pages. **The only field type in v1 that is an XSS vector.**

- Sanitize on **write** (canonical, cheap) *and* escape on **read** (defence in depth)
- Allowlist tags and attributes; never a denylist
- Sanitizer config lives in core, not in `field_storage.settings` — an org must not be able to widen its own allowlist
- `<script>`, `<style>`, `<iframe>`, event handler attributes and `javascript:` URLs are never permitted, regardless of settings
- Store sanitized. Store the pre-sanitization original **only** in the revision record, never in `entries.values`

### `json`

The escape hatch, and escape hatches get abused.

- Size cap, enforced server-side
- Never rendered as HTML without escaping
- Not indexable, not queryable — if you want to query it, you wanted a real field type
- Document it as *"for machine-readable configuration, not content"*, because otherwise it becomes the CMS equivalent of a `misc` column

---

## 7. Indexing

A field is indexed when `field_storage.is_indexed` is true. `Kitsune\Core\Schema\SchemaManager` then adds a stored generated column plus a composite index leading with the scope key — `site_id`, since `entries` is `#[SiteScoped]`. `php artisan kitsune:schema-sync` reconciles the two after a failure, since DDL implicitly commits on MySQL and a row write cannot share a transaction with its schema change.

**The column is named for its projection, not for its owner** — `idx_{handle}__{signature}`, where the signature carries the width wherever the width varies: `idx_price__decimal12_2`, `idx_sku__string64`, `idx_active__boolean` (ADR-028). `entries` is one table shared by every org, so two orgs each defining `price` would otherwise collide: one silently casting the other's data to the wrong type, and either able to drop the other's column. Two rows with the same handle **and** signature generate a byte-identical expression and share the column deliberately; rows that differ in any way that changes the SQL get separate columns. The signature rather than the type handle, because the projection depends on configuration too — a `number` with `format: integer` projects to BIGINT, and through `DECIMAL(12,2)` PostgreSQL refuses the column outright with `numeric field overflow`.

**Verified 2026-09-07** against all three engines (issue #11). The SQL below is what `Kitsune\Core\Schema\Drivers\*` actually emits, and `tests/Core/Schema/GeneratedColumnParityTest.php` runs it on each.

⚠️ **`values` is a reserved word on MySQL and PostgreSQL, so the identifier must be quoted.** Earlier drafts of this document showed it unquoted; that SQL fails as written. Quoting is part of the `SchemaDriver` interface for this reason.

```sql
-- MySQL: $-prefixed path, CAST wrapper, backtick quoting
ALTER TABLE `entries`
  ADD COLUMN `idx_price__decimal12_2` DECIMAL(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN JSON_TYPE(JSON_EXTRACT(`values`, '$.price'))
                IN ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL')
           THEN CAST(`values`->>'$.price' AS DECIMAL(12,2)) END
    ) STORED;
CREATE INDEX `idx_price__decimal12_2_site_idx` ON `entries` (`site_id`, `idx_price__decimal12_2`);
```

```sql
-- PostgreSQL: bare key, cast suffix, double-quote quoting
ALTER TABLE "entries"
  ADD COLUMN "idx_price__decimal12_2" NUMERIC(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN jsonb_typeof(("values")::jsonb -> 'price') = 'number'
           THEN (("values")::jsonb ->> 'price')::NUMERIC(12,2) END
    ) STORED;
CREATE INDEX "idx_price__decimal12_2_site_idx" ON "entries" ("site_id", "idx_price__decimal12_2");
```

```sql
-- SQLite: json_extract, and VIRTUAL rather than STORED
ALTER TABLE "entries"
  ADD COLUMN "idx_price__decimal12_2" NUMERIC(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN json_type("values", '$.price') IN ('integer', 'real')
           THEN CAST(json_extract("values", '$.price') AS NUMERIC(12,2)) END
    ) VIRTUAL;
CREATE INDEX "idx_price__decimal12_2_site_idx" ON "entries" ("site_id", "idx_price__decimal12_2");
```

### The `CASE` is not defensive style — it is the whole isolation guarantee

`entries` is one table shared by every org, so this expression reads the JSON key from **every row in it**, including rows whose org gave `price` a different type. Without the guard, measured against live engines:

| | another org's `"price": "contact us"` |
|---|---|
| PostgreSQL 17 | `ERROR: invalid input syntax for type numeric` — the column cannot be created |
| MySQL 8.4 | `ERROR 1366: Incorrect DECIMAL value` — the same |
| SQLite | **indexes it as `0`.** A price of zero, answering queries for one |

A value of the wrong JSON type is not this projection's data, so it projects to `NULL` (ADR-028 amendment).

The syntax diverges in five ways — path operator, JSON-type spelling, cast form, identifier quoting, and whether the column can be materialised at all. **That divergence is exactly why a field type describes a `Projection` and never touches SQL.**

Two more divergences the driver owns, each of which made a shipped field type unindexable until the parity suite covered every logical type:

- **MySQL needs two spellings.** `BIGINT` in `ADD COLUMN` and `SIGNED` inside `CAST`, each rejected where the other belongs — so `SchemaDriver` exposes `columnType()` and keeps the cast spelling private. One string for both left every `integer` field unindexable there.
- **PostgreSQL demands an IMMUTABLE expression.** A text-to-`DATE` cast is only STABLE, and there is no immutable alternative (`to_date` is STABLE too). So **`date` and `datetime` project to fixed-width ISO-8601 strings on every engine** — `YYYY-MM-DD` and UTC `…+00:00`, which `toStorage()` already normalises to, making string order exact chronological order.

### Rules

- **Indexing is opt-in.** Every generated column costs write throughput and disk. Default off
- **Cap generated columns on the shared `entries` table** — `SchemaManager::MAX_GENERATED_COLUMNS`, 20 to start, revisit with benchmark data. The cap counts **columns, not `field_storage` rows**: many rows across many orgs can share one column, and the scarce resource is the table. Without a cap, one org can degrade `entries` for everyone, which on KaaS is a noisy-neighbour incident
- **Dropping is reference-counted.** Un-indexing removes the column only once no other field storage row still projects to it. An org must never be able to drop a column another org is querying (ADR-021, ADR-028)
- **Settings that affect storage come from STORAGE, never from the per-type field.** `FieldConfig::setting()` reads `field_storage.settings` only. Presentation merging over storage let an *unlocked* `fields.settings` override a *locked* `field_storage.settings` — a per-type `maxLength: 400` against a column already created as `VARCHAR(255)`, or a per-type `format: integer` changing the conversion under stored decimals — which routes around ADR-006's lock-on-data invariant. A display-only setting uses `presentationSetting()` and says so at the call site
- **Cardinality is handled by the contract, not by each type.** `validationRules()` puts the scalar constraints on `handle.*` when the field is multi-value, `apiSchema()` wraps the item schema in an array, and `toStorage()` maps over the elements. A type describes one value in `scalarValidationRules()` / `scalarApiSchema()` / `castToStorage()`; types that are arrays at every cardinality (`relation`, `multi_select`) override the outer methods instead
- **A projection is a promise about what the field accepts, and validation keeps it.** `number` derives its `DECIMAL(p,s)` from configured `precision`/`scale` and bounds the input to match — unbounded, `10000000000` overflowed the column and `1.234` was rounded inside the index so distinct values compared equal. `select` sizes its projection from the widest configured option key, for the same reason
- **Settings that change the projection lock with the shape.** `is_locked` compares projections rather than naming attributes, so switching `format` from decimal to integer on a field holding data is refused while editing a label is not — and a new projection-affecting setting is covered without anyone remembering to list it
- **An indexed string is capped at 700 characters.** Measured: MySQL caps an index key at 3,072 bytes and utf8mb4 costs four bytes a character, so `VARCHAR(1000)` fails with `ERROR 1071` while `VARCHAR(700)` succeeds. Wider fields are refused with that reason — long text that needs searching wants full-text search, not a scalar projection
- **Field handles are bounded at 32 characters, lowercase snake_case, no doubled underscore.** They become SQL identifiers, PostgreSQL truncates those at 63 bytes, and a truncated identifier is a silent collision rather than an error. `__` is reserved as the separator
- **Adding an index rewrites the table.** On a large `entries` table this locks. Queue it, do it online where the engine supports it, and warn in the UI
- **Marking a field indexed is not reversible for free** — dropping the column is another rewrite
- **Cardinality > 1 is not indexable this way.** A JSON array can't project to a scalar column. Multi-value fields that need querying should be `relation`
- **⚠️ MySQL does not preserve JSON object key order.** PostgreSQL and SQLite return the keys as written; MySQL normalises them. Found by the engine matrix on 2026-09-07, not by reasoning. **Nothing may depend on the key order of `values`** — field order comes from `fields.ordering`, and any test comparing a decoded `values` array must sort first or compare order-insensitively
- **SQLite is a third driver, not a variant of the other two.** It cannot `ALTER TABLE ADD COLUMN` a STORED generated column; it takes a VIRTUAL one, indexed as an expression index. VIRTUAL means the value is computed on read rather than materialized, so the write-throughput and disk costs above do not apply in the same way — and neither does the "adding an index rewrites the table" warning. The SQLite DDL is deliberately not written out here: proving all three drivers behave identically is roadmap Phase 1 work, and this doc should not imply a verification that has not happened

---

## 8. Locking

`field_storage.is_locked` flips true the moment any entry holds data for that field. This is Drupal's guard and it's copied deliberately.

**Locked** — cannot change: `type`, `cardinality`, anything altering the stored shape.
**Never locked** — `label`, `help_text`, `is_required`, `default_value`, `ordering`, `group`, and everything else in `fields` (presentation is per-entity-type and safe to edit).
**Special case** — `is_indexed` can still be toggled on a locked field, since it changes the index rather than the data. It's expensive, not unsafe.

Changing a locked field's type requires an explicit migration: create a new field, run a conversion, verify, drop the old one. The engine should offer this as a guided flow rather than pretending it's an edit — silent type coercion is how content gets destroyed.

---

## 9. Adding a field type — the checklist

Because this should be mechanical:

1. Implement `FieldType`
2. Pick a strategy — `Promoted` is closed, so realistically `Inline` or `Relational`
3. If indexable, implement `generatedColumnType()` for **all three** drivers — Postgres, MySQL and SQLite
4. Map `formComponent()` and `tableColumn()` to Filament components
5. `toApi()` / `fromApi()` / `apiSchema()` — round-tripping must be lossless
6. `validationRules()` — `scopedUnique()` / `scopedExists()` only
7. `settingsSchema()` — the field's own configuration form
8. Register in the field type registry
9. **Tests:** round-trip through storage; validation; a **cross-org boundary test**; and if indexable, an index-creation test on all three engines

---

## 10. Privacy classification

Every `field_storage` row carries a `pii_class` — `none | personal | sensitive` — and **a field that has not been classified does not save** (ADR-020). `sensitive` means GDPR Article 9 special-category data.

This is not paperwork. In a runtime schema engine the platform genuinely cannot know whether "Customer Notes" holds personal data, so a right-of-access or erasure request is unanswerable without the declaration. Field-name heuristics don't work — an org's labels may not even be in English.

What the classification drives:

| Class | Effect |
|---|---|
| `none` | Nothing. The default answer for a price, a slug, a publication date |
| `personal` | Included in subject-access export; redacted on erasure, **including in revisions**; retention policy applies |
| `sensitive` | All of the above, plus encryption at rest and stricter default retention |

Field type authors should set a sensible **suggested** class — a `relation` to a `person` type suggests `personal`, a `number` suggests `none` — but the org confirms it, because only the org knows what the field is for.

**Consequences for field type implementations:**

- `toStorage()` / `fromStorage()` must round-trip through encryption transparently for `sensitive` fields
- Every type needs a **redaction** path, not just a delete — erasure replaces a value in place, in the entry *and in every revision*, without destroying the record
- `rich_text` is the awkward one: personal data can be anywhere in a free-text body, so redaction is a content operation rather than a field operation. Treat a `personal`-classified `rich_text` field as requiring manual review during an erasure request, and say so in the UI rather than pretending it is automatic

---

## 11. Open questions

| Question | Why it matters |
|---|---|
| Do revisions store full `values` snapshots or diffs? | Full snapshots are simple and get expensive fast on `rich_text` |
| Should `relation` support polymorphic targets, or one target type per field? | Polymorphic is more flexible and much harder to validate and index |
| Do `relation` fields target the translation group or a specific locale row? | Left open by ADR-017; group-targeting with an optional locale override is the leading candidate. It decides what `entry_relations.target_entry_id` actually points at |
| Default cap on indexed fields per entity type | 20 is a guess. Needs Phase 1 benchmark data |
| Does `multi_select` warrant `Relational` storage instead? | It would make faceted filtering trivial at the cost of a join |
| Should `pii_class` have a suggested default per field type, or force an explicit choice every time? | Suggestions reduce friction; forcing the choice is what makes the guarantee real |
| How is erasure surfaced for `rich_text`, where personal data can be anywhere in the body? | Manual review is the honest answer, but it needs a UI that says so rather than implying automation |

**Translations are settled, and this doc predated the decision.** They are **entry-level, not field-level** (ADR-017, revised by ADR-021): each translation is its own `entries` row linked by `translation_group`, and locale is derived from `sites.locale` rather than stored on the entry.

Field-level translation was not merely rejected but *disqualified*. A locale map inside the JSON — `{"title": {"en": "Hello", "fr": "Bonjour"}}` — cannot be projected to a scalar by a stored generated column, so indexing would need one generated column per indexed field per locale. That collides head-on with the mechanism §7 depends on.

**What a field type author needs to take from this:** `field_storage` carries a `translation_scope` enum — `shared | per_locale`. A `shared` field is denormalized into every sibling locale row (editable only on the origin), because a shared value must be *physically present* in each row to be indexable. Types whose values are identical across locales — SKU, price, dimensions — are the ones this matters for.
