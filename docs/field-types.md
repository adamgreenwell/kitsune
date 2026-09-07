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

### Two rules that are not negotiable

**1. `validationRules()` never returns Laravel's `unique` or `exists`.** Those rules don't go through Eloquent, so they ignore global scopes and leak across sites and orgs. Return `scopedUnique()` / `scopedExists()`. This is enforced by the plugin validation CLI (roadmap v1.2), and it fails the build.

**2. `generatedColumnType()` takes the driver and returns driver-specific SQL.** There are **three** drivers, and all three differ: Postgres and MySQL diverge on generated-column syntax *and* JSON path operators, and SQLite cannot add a STORED generated column via `ALTER TABLE` at all — it needs a VIRTUAL one, indexed as an expression index ([`architecture.md`](architecture.md) §1). No field type ever writes raw SQL directly — it asks the driver.

---

## 4. Types shipping in v1.0

Twelve types. Deliberately small — every one added before the API freeze is a permanent maintenance obligation.

| Handle | Strategy | Indexable | Cardinality | Notes |
|---|---|---|---|---|
| `text` | Inline | ✅ | ✅ | Single line. `maxLength`, optional pattern |
| `textarea` | Inline | ❌ | ✅ | Plain multi-line |
| `rich_text` | Inline | ❌ | ❌ | Sanitized HTML — see §6 |
| `number` | Inline | ✅ | ✅ | `integer` \| `decimal`, precision, min/max, step |
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

A field is indexed when `field_storage.is_indexed` is true. The engine then adds a stored generated column plus a composite index leading with the scope key — `site_id`, since `entries` is `#[SiteScoped]`:

**Verified 2026-09-07** against all three engines (issue #11). The SQL below is what `Kitsune\Core\Schema\Drivers\*` actually emits, and `tests/Core/Schema/GeneratedColumnParityTest.php` runs it on each.

⚠️ **`values` is a reserved word on MySQL and PostgreSQL, so the identifier must be quoted.** Earlier drafts of this document showed it unquoted; that SQL fails as written. Quoting is part of the `SchemaDriver` interface for this reason.

```sql
-- MySQL: $-prefixed path, CAST wrapper, backtick quoting
ALTER TABLE `entries`
  ADD COLUMN `idx_price` DECIMAL(12,2)
    GENERATED ALWAYS AS (CAST(`values`->>'$.price' AS DECIMAL(12,2))) STORED;
CREATE INDEX `entries_site_price` ON `entries` (`site_id`, `idx_price`);
```

```sql
-- PostgreSQL: bare key, cast suffix, double-quote quoting
ALTER TABLE "entries"
  ADD COLUMN "idx_price" NUMERIC(12,2)
    GENERATED ALWAYS AS (("values" ->> 'price')::NUMERIC(12,2)) STORED;
CREATE INDEX "entries_site_price" ON "entries" ("site_id", "idx_price");
```

```sql
-- SQLite: json_extract, and VIRTUAL rather than STORED
ALTER TABLE "entries"
  ADD COLUMN "idx_price" NUMERIC(12,2)
    GENERATED ALWAYS AS (CAST(json_extract("values", '$.price') AS NUMERIC(12,2))) VIRTUAL;
CREATE INDEX "entries_site_price" ON "entries" ("site_id", "idx_price");
```

The syntax diverges in four ways — path operator, cast form, identifier quoting, and whether the column can be materialised at all. **That divergence is exactly why the field type asks a driver instead of writing SQL.**

### Rules

- **Indexing is opt-in.** Every generated column costs write throughput and disk. Default off
- **Cap indexed fields per entity type** — start at 20, revisit with benchmark data. Without a cap, one org can degrade the shared `entries` table for everyone, which on KaaS is a noisy-neighbour incident
- **Adding an index rewrites the table.** On a large `entries` table this locks. Queue it, do it online where the engine supports it, and warn in the UI
- **Marking a field indexed is not reversible for free** — dropping the column is another rewrite
- **Cardinality > 1 is not indexable this way.** A JSON array can't project to a scalar column. Multi-value fields that need querying should be `relation`
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
