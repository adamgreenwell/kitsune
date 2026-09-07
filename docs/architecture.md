# Kitsune — Core Architecture

**Companion to:** [`decision-log.md`](decision-log.md) (*why*) and [`roadmap.md`](roadmap.md) (*what and when*). This doc is the ***how***.
**Created:** 2026-09-07
**Status:** Design. Verified against Filament v5.7.8 source and a working measurement spike. Open items flagged inline.

---

## 1. Platform baseline

| | | |
|---|---|---|
| **PHP** | **`^8.4`** minimum; develop and CI-primary on **8.5** | 8.4 security-supported to **Dec 2028**; 8.5 to **Dec 2029**. 8.3 is *already* security-only (active support ended Dec 2025), so it's a poor floor for a greenfield project. Revisit the floor at v1.0 |
| **Laravel** | 13.x | Bugfixes to Q3 2027, security to 2028-03-17 |
| **Filament** | **`^5.4`** | v5.4.0 is the first release supporting Laravel 13 |
| **Database** | PostgreSQL primary; MySQL 8.0+ / MariaDB 10.6+; **SQLite for small single-site installs** | Postgres/MySQL use STORED generated columns. **SQLite cannot `ALTER TABLE ADD COLUMN` a STORED generated column, but can add a VIRTUAL one, and VIRTUAL columns can be indexed** (as expression indexes) — so index-on-demand works there too, via the driver abstraction. Serves pillar three: no database server required |
| **License** | plain MPL-2.0 (never Exhibit B) | |

**Why PHP 8.4 is the right floor for *this* project specifically** — beyond "newer is nicer":

- **Property hooks** let the `Entry` model expose JSON-backed dynamic fields as real typed properties with `get`/`set` hooks, instead of magic `__get`/`__set`. That means PHPStan can actually analyze field access, and IDEs can complete it. For a schema engine whose entire premise is dynamic fields, this is the difference between a statically-analyzable core and a bag of magic.
- **Asymmetric visibility** (`public private(set)`) fits field and storage definitions, which are read everywhere and written only by the schema engine.
- **Lazy objects** defer expensive schema hydration until something actually touches it.
- **`#[\Deprecated]`** directly serves Standing Principle #2 — deprecate, never remove.

---

## 2. Admin routing — the decision that shapes everything

Two designs were tested with an instrumented spike (Laravel 12 + Filament v5.7.8), not reasoned about. One of them is **impossible**, not merely slow.

### ❌ Rejected: `ResourceConfiguration` (one Resource registered N times)

This was the design in the first roadmap draft. **It cannot support org-owned entity types at all.**

`panel()` runs during service-provider boot — before routing, auth, and tenancy. Instrumented on a real authenticated request:

```
panel() ran | Filament::getTenant()=NULL | auth()->id()=NULL | request()->route()=NULL
```

Filament says so in its own source (`IdentifyTenant.php:15-19`): *"Tenant identification occurs in this middleware... Queries in earlier middleware or service providers will not be tenant-scoped."*

The tenant is a **route parameter**, so there is one route table shared by every tenant. You'd have to register the **union of every tenant's types**, on every request. 500 tenants × 20 types = 10,000 configurations = **40,000 routes per request.**

Measured cost — exactly 4 route registrations per configuration, linear, no knee:

| Configs | Admin routes | Boot (median) |
|---|---|---|
| 0 | 7 | 145 ms |
| 200 | 807 | 186 ms |
| 1000 | 4007 | 238 ms |
| 2000 | 8007 | 333 ms |

**And it is not cacheable.** `Panel::cacheComponents()` deliberately writes `'resourceConfigurations' => []`.

**Worst of all, `route:cache` breaks it silently.** Cached at N=200, then changed the DB:

- Types added → new routes don't exist, 404 until you re-cache
- Types removed → **822 stale routes survive and stay reachable.** `getConfiguration()` returns `null` and `getSlug()` silently falls back to the default. If the configuration carried your tenant/type scope, that's a **scoping failure that returns HTTP 200, not an error.**

That last line is the six-month bug. It's why this is rejected rather than optimized.

### ✅ Adopted: entity type as a route parameter

One `EntryResource`, with the type as a path segment:

```
admin/{tenant}/c/{type}                  → entries.index
admin/{tenant}/c/{type}/create           → entries.create
admin/{tenant}/c/{type}/{record}         → entries.view
admin/{tenant}/c/{type}/{record}/edit    → entries.edit
```

> **`{tenant}` is Filament's route parameter name**, and is the one place the word survives under ADR-021's naming rule. It resolves to a Kitsune **Site**. Everything downstream of the route — models, columns, scopes — says `Org` or `Site`.

**7 admin routes total, with 201 types in the database.** Flat in N, forever.

Verified properties:

- `Page::route(string $path)` accepts arbitrary paths — officially documented: *"Any parameters defined in the route's path will be available to the page class."*
- **`route:cache` is fully compatible.** A type inserted *after* caching returned HTTP 200 and appeared in the sidebar. `{type}` is a parameter — there is nothing to go stale
- You keep the **full Resource**: route-model binding, policies, relation managers, breadcrumbs, global search. No custom Pages needed
- Full rendered request at 207 nav items: 353 ms vs Design A's 369 ms. Speed isn't the differentiator at moderate N — **org-specificity and cache correctness are**

### Required implementation details

Three of these are not optional. Skip any one and it breaks.

1. **`$slug = 'c'`, pages at `/{type}`, `/{type}/create`, `/{type}/{record}`, `/{type}/{record}/edit`.** Register hard-coded segments *before* wildcards.

2. **Never add `{type}` to `mount()`.** `ListRecords::mount(): void` and `EditRecord::mount(int|string $record): void` are signature-locked — adding a parameter is a **PHP fatal error**. Use Livewire's `boot{Trait}()` hook, exactly as Filament does for parent records:

   ```php
   #[Locked] public string $type;
   public function bootInteractsWithEntryType(): void { /* resolve from route */ }
   ```

   Read `request()->route()->parameter('type')`, falling back to `original_request()->route()->parameter('type')`.

3. **⚠️ `->tenantMiddleware([IdentifyEntryType::class], isPersistent: true)` — non-negotiable.** This middleware sets `URL::defaults(['type' => $type])`. Without it, `Resource::getUrl()` auto-injects only `tenant` and `record`, so **every Livewire update 500s** with *"Missing required parameter: type"* the moment the table renders a record link. The decisive test, same code, only the flag toggled:

   ```
   isPersistent: true   → HTTP 200, $type='article', record links work
   isPersistent: false  → HTTP 500 "Missing required parameter"
   ```

   It works because Livewire stores the original path in its snapshot memo and re-matches the real route on update requests.

4. **⚠️ `protected static bool $shouldRegisterNavigation = false;` on the Resource — and navigation supplied explicitly via `Panel::navigation(Closure)`. This is a correctness requirement, not a performance one.** Filament auto-registers one navigation item per Resource and calls `getUrl()` on it while rendering the sidebar. With `{type}` in the URI and no `{type}` in the *current* request, that throws `UrlGenerationException` and **500s every page that is not already under `/c/{type}` — the dashboard included.** Measured:

   ```
   GET /admin/golfdom  →  500  UrlGenerationException
   Missing required parameter for [Route: filament.admin.resources.c.index]
   [URI: admin/{tenant}/c/{type}] [Missing parameter: type]
   ```

   The replacement closure runs at render time, *after* tenant identification, and emits one item per entry type with `{type}` supplied explicitly. **It fires exactly 5× per request** (layout, sidebar ×2, topbar ×2) and does not memoize — measured, not estimated. **Memoize it, or you do 5 identical DB queries per page.** Cache per site.

5. **⚠️ `original_request()` is namespaced — import it.** It lives in `Filament\Support`, not the global namespace, so a bare call fatals with *"Call to undefined function"*. Add `use function Filament\Support\original_request;` to every file that reads it.

   The failure mode is the dangerous kind: on the initial `GET` the route parameter is present, so `??` short-circuits and the function is never evaluated. The page renders perfectly. The fatal fires **only on the first Livewire update**, which is the first time anyone clicks anything.

6. **Override `getGlobalSearchResultUrl()`** to pass `['type' => $record->type_handle]`.

7. Use `route:cache`. **Skip `filament:cache-components`** — it caches nothing useful here.

### Rejected along the way

**Nested / parent resources** won't work. `ParentResourceRegistration` hard-requires a real parent Eloquent model and relationship, and Filament disables global search under nested resources entirely.

---

## 3. Data model

### Orgs, site groups and sites

Three structural levels (ADR-021). "Tenant" is **banned from Kitsune's own code** — Filament calls its route segment a tenant; Kitsune means a **Site**. Use `Org` and `Site` explicitly, and the word "tenant" only at the Filament API boundary.

```
orgs                           -- the customer; billing and user boundary
  id, name, slug, settings json
  timestamps, soft deletes

org_user
  org_id, user_id, created_at

site_groups                    -- the brand; settings inheritance
  id, org_id, handle, name, settings json

sites                          -- anything with its own base URL
  id, org_id, site_group_id, handle, name
  slug            string       -- GLOBALLY unique; the admin route key,
                               -- because /admin/{site} has no org segment
  locale          string       -- this site's language; entries have no locale column
  url_strategy    enum         -- path | subdomain | domain
  base_url        string       -- 'https://golfdom.com', 'https://example.com/fr'
  theme           string
  is_primary      bool
  settings        json
  UNIQUE (org_id, handle)
```

**Site carries locale.** `golfdom.com` (en) and `golfdom.fr` (fr) are two sites in one group. One mechanism covers all three URL strategies — a path prefix is just a `base_url` of `https://example.com/fr`.

**Settings resolve org → site group → site** (ADR-022) — sparse overrides, shallow-merged on top-level keys. A level stores a key only if it overrides it; absent means inherit. Every resolved value shows its origin in the admin.

```
entry_type_availability          -- per-site entry types, same inheritance
  entry_type_id  FK
  scope_type     enum   org | site_group | site
  scope_id       int
  is_enabled     bool
  UNIQUE (entry_type_id, scope_type, scope_id)
```

Absent at every level = enabled. `IdentifyEntryType` middleware 404s on a type disabled for the current site, alongside its existing checks.

### Schema definitions

`org_id NULL` = a global system type available to every org. Non-null = org-owned. This is what resolves the boot-order problem cleanly: **the route table never depends on org or site state.**

```
entry_types
  id
  org_id             FK nullable          -- NULL = global
  handle             string               -- machine name, "product"
  name, plural_name, icon, description
  is_system          bool                 -- undeletable
  ordering
  settings           json                 -- revisions on/off, sluggable, publishable
  timestamps
  UNIQUE (org_id, handle)

field_storage                             -- Drupal's FieldStorageConfig
  id
  org_id             FK nullable
  handle             string               -- "body", "price"
  type               string               -- text|number|relation|media|...
  cardinality        int                  -- -1 = unlimited
  is_indexed         bool                 -- drives generated-column creation
  is_locked          bool                 -- true once any data exists
  settings           json
  timestamps
  UNIQUE (org_id, handle)

fields                                    -- Drupal's FieldConfig: per-type presentation
  id
  entry_type_id      FK
  field_storage_id   FK
  label, help_text
  is_required        bool
  default_value      json
  ordering, group    nullable
  settings           json                 -- widget-level config
  UNIQUE (entry_type_id, field_storage_id)
```

The storage/config split is stolen deliberately from Drupal: storage is defined once and reusable across entity types, presentation is per-type, and **`is_locked` flips true the moment data exists.** That guard ships in v1, not later.

### Content

```
entries
  id
  site_id            FK **nullable** indexed   -- NULL = shared across the org
  org_id             FK    indexed             -- denormalized; needed when site_id is NULL
  entry_type_id      FK    indexed
  type_handle        string indexed            -- denormalized for routing lookups
  translation_group  uuid  indexed             -- links siblings across sites in a group
  origin_id          FK nullable               -- null on the origin row
  status             enum draft|published|archived
  slug               nullable indexed          -- NULL for org-shared entries; they are not publicly addressable
  title              nullable                  -- promoted out of JSON for lists and search
  values             json                      -- all field data
  author_id          FK
  published_at, timestamps, soft deletes
  INDEX  (site_id, entry_type_id, status)
  UNIQUE (site_id, entry_type_id, slug)
  UNIQUE (translation_group, site_id)

entry_revisions
  id, entry_id, values json, status, author_id, note, created_at
```

### Relations — a real table, not JSON

```
entry_relations
  id
  org_id                                  -- org-scoped: a relation may link a site entry
                                          -- to an org-shared media entry
  source_entry_id    FK
  field_storage_id   FK                   -- which field this relation belongs to
  target_entry_id    FK
  ordering
  INDEX (source_entry_id, field_storage_id)
  INDEX (target_entry_id)
```

**Why not JSON:** reverse lookups ("what references this asset?") and referential integrity. A JSON array of IDs cannot answer "what points at me" without a full scan, and cascade-on-delete becomes application code that will eventually be wrong.

### Indexing — the mechanism that avoids Drupal's join explosion

When a `field_storage` row is marked `is_indexed`, `SchemaManager` adds a **stored generated column** over the JSON plus a composite index that always leads with the scope key — `site_id`, since `entries` is `#[SiteScoped]`:

```sql
-- MySQL. `values` is reserved and must be quoted; see field-types.md §7
-- for the PostgreSQL and SQLite forms, all three verified against live engines.
ALTER TABLE `entries`
  ADD COLUMN `idx_price__number` DECIMAL(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN JSON_TYPE(JSON_EXTRACT(`values`, '$.price'))
                IN ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL')
           THEN CAST(`values`->>'$.price' AS DECIMAL(12,2)) END
    ) STORED;
CREATE INDEX `idx_price__number_site_idx` ON `entries` (`site_id`, `idx_price__number`);
```

Two things in that statement are load-bearing, and both come from `entries` being **one table shared by every org** while `field_storage` is `UNIQUE (org_id, handle)` — so two orgs may each define `price` (ADR-028).

- **The column carries the field type, not the org.** Naming by handle alone let one org's type silently reinterpret another's data, and let either drop the other's column. Rows projecting identically share the column; dropping is reference-counted.
- **The `CASE` makes the expression total.** The projection reads that JSON key from every row in the table. Unguarded, another org's `"contact us"` stops the column being created at all on PostgreSQL and MySQL, and indexes as `0` on SQLite. A value of the wrong JSON type projects to `NULL`.

One table, one row per entry, real indexes on the fields that need them. This is the direct answer to Drupal core issue #3022864 — the 27-join, 697-second production query caused by table-per-field.

**Generated-column syntax and JSON path operators differ across all three engines** — and SQLite additionally cannot `ALTER TABLE ADD COLUMN` a STORED generated column at all, taking a VIRTUAL one instead (§1). Abstract this behind one driver interface from the first commit; do not let raw SQL leak into the field types.

### Kernel and modules

```
modules                                   -- global; code is code
  id, handle, version, is_enabled, installed_at, settings json

org_modules                               -- per-org enablement
  org_id, module_handle, is_enabled, settings json

blueprints
  id, org_id, handle, version, applied_at, manifest json
```

---

## 4. Tenancy: the security model

Filament's tenancy scopes Resources automatically **and nothing else.** Its own source comment: *"Filament does not guarantee multi-tenant security; it is your responsibility to implement correctly."* Kitsune's kernel therefore enforces this rather than trusting plugin authors.

### Invariants — every one is a build-failing test

1. **Every model declares its scope. Fail closed** — undeclared means exception in dev, refuse-to-serve in prod.

   ```php
   #[SiteScoped]   // entries and most content — Filament's tenancy scopes these
   #[OrgScoped]    // users, billing, settings, shared media — KITSUNE scopes these
   #[Unscoped]     // genuinely global: modules, system entry types
   ```

   ⚠️ **Filament's tenancy segment is the Site (ADR-021), so its automatic scoping enforces *site* isolation and not *org* isolation** — org is a level Filament does not model. `#[OrgScoped]` models get **no framework scope at all** and must receive a Kitsune-authored global scope. A cross-org leak in `users` or shared media would be caught by nothing Filament does.
2. **`{type}` is user-controlled URL input and must be validated on every request.** Nothing stops someone hitting `/admin/{tenant}/c/anything`. `IdentifyEntryType` middleware **404s** on a type that doesn't exist, that belongs to another org, or that is disabled for the current site (ADR-022). This is a security boundary, not a convenience.
3. **Reserved type handles.** A type literally named `create` collides with `/{type}/create`. Maintain a reserved word list — at minimum `create`, `edit`, `delete`, plus every Filament page segment — and reject it at type-creation time.
4. **`scopedUnique()` / `scopedExists()` are the defaults** in Kitsune's form layer. Laravel's `unique` and `exists` do not use Eloquent and therefore ignore global scopes; the failure mode is site B being blocked from a slug site A holds — a cross-site, and potentially cross-org, information leak.
5. **No query before site identification touches org- or site-scoped data.** The route table is scope-independent by design (§2), so this is now structurally true rather than a rule to remember.
6. **Every composite index leads with its scope key** — `site_id` for site-scoped models, `org_id` for org-scoped. Since `site_id` is globally unique and belongs to exactly one org, leading with it enforces org isolation transitively: narrower index, same guarantee.
7. **Two deliberately hostile tests live in core** — cross-**site** isolation within one org, and cross-**org** isolation. The second is the one with no framework safety net. The most valuable tests in the codebase.

### Per-type authorization

One `Entry` model means one Eloquent policy for all types — neither routing design gives per-type authorization for free. `EntryPolicy` resolves permissions against `type_handle` and the RBAC layer, with permissions named `entry.{type_handle}.{view|create|update|delete|publish}`. Blueprints seed these when they create a type.

---

## 5. The kernel

A module is a Composer package with a manifest. Kernel responsibilities, and nothing more:

```
Module registry     discovery, enable/disable, dependency resolution, ordering
Lifecycle           install / upgrade / uninstall, with migrations and rollback
Hooks               Laravel events, documented naming convention
Settings            scoped config store, resolved org → site group → site (ADR-022)
RBAC                roles, permissions, per-org assignment
Audit               who changed what, when, in which org and site
Blueprints          a kernel primitive, not a module
```

### Module manifest

```yaml
handle: kitsune/commerce
name: Commerce
version: 1.0.0
requires:
  kitsune/core: "^1.0"
  php: "^8.4"
tenancy: aware          # aware | agnostic — REQUIRED, kernel refuses to load without it
provides:
  entry_types: [product, order]
  blueprints: [storefront]
```

`tenancy:` is mandatory. A module that doesn't declare it does not load. This is the one line that turns "every author must remember" into "the kernel won't let you forget."

---

## 6. Migration adapters — source-agnostic

**WordPress is one adapter, not the architecture.** The importer is a pluggable framework: every source maps into one canonical intermediate representation, and adapters never talk to Kitsune's internals directly.

```php
interface MigrationAdapter
{
    public function name(): string;
    public function connect(array $config): void;
    public function discoverSchema(): SchemaManifest;      // source types → proposed entry types
    public function countRecords(string $sourceType): int;
    public function readRecords(string $sourceType, int $offset, int $limit): iterable;
    public function readMedia(): iterable;
    public function validate(): ValidationReport;          // dry run, no writes
}
```

```
migrations
  id, org_id, adapter, source_config json (encrypted)
  status, started_at, finished_at, report json

migration_map                             -- source id → kitsune id; makes re-runs idempotent
  id, migration_id, source_type, source_id, entry_id
```

`migration_map` is what makes an import **resumable and re-runnable** — you can re-run against a changed source and update rather than duplicate. That matters far more than any single adapter's fidelity.

Every adapter must support **dry-run** producing a full report before anything is written. First adapters, in order: WordPress, then CSV/JSON (trivial, and covers a long tail), then whatever demand shows up.

---

## 7. Open items requiring a code spike

Honest list. None of these blocks starting; all of them should be settled before Phase 4 hardens.

| Item | Risk | Why |
|---|---|---|
| Storage benchmark at 10k / 100k / 1M entries | High | Find the ceiling now, not in year two |
| Blueprint rollback semantics | Medium | What happens when a blueprint is removed after content exists? |
| Revision storage growth | Medium | Full-JSON snapshots per revision get expensive; consider diffs |
| Relation targets under translation | Medium | Do relations point at a translation group or one locale row (ADR-017)? It decides what `entry_relations.target_entry_id` holds |

---

## 8. What this architecture buys

- **Flat routing cost.** 7 admin routes whether an org has 3 entity types or 300
- **`route:cache` is safe**, with no silent scoping failures
- **The boot-order collision is structurally gone** — the route table never depends on org or site state
- **One table, real indexes**, avoiding Drupal's documented join explosion
- **Tenancy enforced by the kernel**, not by plugin-author discipline
- **Migration is a framework**, so a new source is an adapter rather than a rewrite
- **Full Filament Resource features retained** — policies, relation managers, global search, breadcrumbs
