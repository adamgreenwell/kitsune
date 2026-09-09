# Kitsune — Decision Log

**Purpose:** Every architectural decision, and every option that lost, with the specific reason it lost. If a settled question comes back up, the answer is here — don't re-litigate it, amend it deliberately.

**Project:** Kitsune — an open-source, Laravel-based multi-purpose content/application platform. CMS, DAM, e-commerce, ERP — whatever the operator configures it to be.

**Started:** 2026-09-07 · **Last revised:** 2026-09-07 (post-spike)
**Status:** Pre-alpha. Foundational decisions settled. All load-bearing claims verified against primary sources; the routing design was settled by a working measurement spike.
**Companion docs:** [`roadmap.md`](roadmap.md) (*what and when*) · [`architecture.md`](architecture.md) (*how*) · [`field-types.md`](field-types.md) (*field type contract*)

---

## The three pillars

Every decision in this log is measured against three commitments:

1. **Moldable.** Kitsune becomes what the operator needs — CMS, DAM, storefront, ERP — by configuration rather than by forking.
2. **Privacy first.** Compliance is possible by construction, not bolted on. A self-hosted operator is the data controller; core's job is to make their obligations achievable.
3. **Accessible to everyone.** Not one axis, three: **physical** (assistive technology, real WCAG conformance, tested rather than claimed), **linguistic** (usable and contributable-to by people who don't read English), and **economic** (a one-person project and a thousand-person publisher both fit, without either being an afterthought).

### They are load-bearing because they conflict

The pillars earn their place by making hard decisions decidable. Nearly every difficult ADR here is a named resolution of a tension between two of them:

| Tension | Resolved by |
|---|---|
| **Moldable vs. accessible to small orgs.** Maximum flexibility means a blank entity builder — which Drupal spent a decade proving is *harder* to use than a fixed-schema CMS | **Blueprints** as a kernel primitive in v1.0 (ADR-001) |
| **Moldable vs. privacy first.** A runtime schema engine genuinely cannot know whether a tenant's "Customer Notes" field holds personal data | **`pii_class`, fail-closed** — the tenant declares it, and an unclassified field does not save (ADR-020) |
| **Privacy first vs. accessible to large orgs.** Enterprises need audit trails; privacy needs erasure. Both are non-negotiable and they pull opposite ways | **Payload-free audit logs** — actor, action and target, never content (ADR-020) |
| **Accessible to small vs. accessible to large.** Kernel-level tenancy, SSO and workflow serve enterprises and tax a one-site install | Partially unresolved. Sensible single-tenant defaults, and SQLite support so a small install needs no database server |

Where a decision costs a pillar, the ADR says so. A pillar that never costs anything isn't a commitment, it's a slogan.

### The third pillar is the one that breaks

Every platform surveyed in the Standing Principles below eventually picked a side. Drupal drifted toward enterprise until **Backdrop forked specifically for "organizations that need the complex and feature-rich functionality of Drupal 7, but without the expensive upkeep."** That fork is the third pillar failing, in public, with a date on it.

Serving both ends is the hardest commitment in this document and the one most likely to erode quietly. It erodes by a thousand small choices that each make sense for the larger customer.

---

## Context that drives everything

1. **Migrate existing sites into Kitsune.** WordPress sites are the immediate case, but **WordPress is one example, not the defining feature** — see ADR-007.
2. **Stand up Kitsune-as-a-Service (KaaS), a paid subscription platform.** Drives licensing, trademark, tenancy, and the open/paid boundary.

The early audience is people who currently install CMS plugins by uploading a zip. Anything that breaks that expectation is a real adoption cost.

---

## ADR-001 — Extensibility: layered kernel + runtime schema engine

**Status:** Decided · 2026-09-07

Thin module kernel (registry, hooks, service providers, auth/RBAC, audit) with the runtime schema engine as the flagship first-party module. Developers extend with PHP; non-developers build with the schema engine.

**Why:** Serves both audiences. Runtime schema engines are proven at scale — Drupal 15+ years, Directus over arbitrary databases, Strapi at 73.1k stars. Not where this project will fail.

| Rejected | Why it lost |
|---|---|
| Schema engine alone | No extension surface for developers. Everything non-trivial becomes a core patch. |
| Code-first modules alone | Every new use case needs a developer. Kills the premise. |
| Framework/scaffolding only | Competes with plain Laravel; weak reason to exist. |

**Carried risk — query cost.** Drupal core issue #3022864 (open since Dec 2018) documents entity queries with 27+ redundant joins: 1.3s on MySQL 5.7, 13.4s on MariaDB 10.4, one production query at **697 seconds before timeout**. Mitigated by ADR-006 + ADR-010.

**Carried risk — a blank entity builder is not a product.** Drupal spent ~10 years discovering this, then shipped Drupal CMS 1.0 with "Recipes." **Kitsune ships Blueprints in v1.0.**

---

## ADR-002 — Delivery: hybrid headless core + Blade theme layer

**Status:** Decided · 2026-09-07 · *Deferred to v1.1 (ADR-011)*

| Rejected | Why it lost |
|---|---|
| Headless-only | Locks out everyone who just wants a website. |
| Coupled/WordPress-style only | Makes DAM/ERP/commerce feel bolted on; forecloses the headless audience. |

---

## ADR-003 — Admin UI: Blade + Livewire (REVERSES an earlier pick)

**Status:** Decided · 2026-09-07 · **Supersedes** an earlier pick of Inertia + React

**Why the reversal — evidence, not taste.** Plugin-ecosystem size correlates almost perfectly with server-rendered admin:

- **WordPress** — 71,000+ plugins, **zero build step**
- **Filament** (Livewire/Alpine) — 1,013 plugins, 464 authors *(verified 2026-09-07; drifts daily)*
- **Drupal** (Form API/Twig) — enormous contrib, no bundler
- **October CMS** — 11.1k stars, server-rendered
- **Statamic** (Vue+Inertia, kept a Blade escape hatch) — 488 addons
- **Strapi** (React SPA) — 73.1k stars but **7 UI injection zones**, exactly **one** recommended for third parties, widening requests closed as low-severity, and a **mandatory JS rebuild** to install any plugin with admin UI

Root cause is structural: a bundler must statically know every component at build time. Incompatible with "upload a plugin and it works."

| Rejected | Why it lost |
|---|---|
| Inertia + React | No drop-in plugins without a runtime registry nobody has shipped well. No React/Inertia plugin ecosystem in Laravel to borrow. Multi-year solo cost. |
| Inertia + Vue | Better (Statamic precedent) but still a build step for plugin UI and a second mental model. |
| React + build-on-install (Payload's import maps) | Needs Node on the server at install. Non-developers can never install a plugin. |

**Accepted trade-off:** Livewire round-trips every interaction. DAM drag-and-drop, ERP grids and a page builder each need Alpine or an island of client-side JS.

---

## ADR-004 — Monetization: open core + paid first-party modules + hosting

**Status:** Decided · 2026-09-07

| Rejected | Why it lost |
|---|---|
| Everything open, hosting only | Leaves the module revenue line on the table. |
| Open core + modules, no hosting | KaaS is an explicit goal. |
| Decide later | Quietly closes the CLA option (ADR-005), which cannot be retrofitted. |

**Non-negotiable, learned from October CMS:** the paid/free split is **declared day one and never moved**. Statamic has run free-core/paid-Pro for years with zero revolt because nothing changed. October went MIT → proprietary EULA in April 2021; its own maintenance team had forked it five weeks earlier. Directus moved GPL → BSL → restricted custom license, and the stated objection was not the terms but that *"the licensing terms are a moving target."*

**Corollary:** don't charge before there's an adoption base. *"Not very smart to do this while they don't have much adoption."*

---

## ADR-005 — Licensing and trademark: two instruments, not one

**Status:** Decided · 2026-09-07 · **Fully verified against license text and Mozilla FAQ**

**Reframing insight:** "commercial use fine, name intact" is **a code license plus a trademark policy**. WordPress's protection doesn't come from the GPL — the GPL explicitly permits selling, hosting, forking and monetizing WordPress, which is why WP Engine and Kinsta are legal. What stops a fork being *called* WordPress is the Foundation's trademark: *"Under no circumstances is it permitted to use WordPress or WordCamp as part of a domain name."* The 2024 Automattic/WP Engine dispute was fought on trademark, not copyright.

**Second insight:** no OSI license stops a competitor running KaaS. AGPL comes closest — a hosting competitor must publish modifications — but may still host and charge. **The license is not the moat.** The moat is (1) trademark, (2) the proprietary control plane, (3) being upstream.

**Decision:** **plain MPL-2.0** for core + owned trademark policy + CLA from the first merged PR.

**Verified against MPL-2.0 §1.7, §1.10, §2.1, §3.3, §3.4 and Mozilla's FAQ:**

- Proprietary plugins in **separate files** are permitted — §1.10 defines "Modifications" file-by-file; a plugin that *calls* core APIs without copying core code is not a Modification
- §3.3 permits distributing a Larger Work under terms of your choice → **paid proprietary first-party modules alongside an MPL core are squarely legal**
- §2.1(b) explicitly grants "make, use, **sell**, offer for sale"
- **No network/SaaS clause exists** (grep of full text: zero matches for network/remote/service)
- Plain MPL-2.0 **is** GPL/LGPL/AGPL-compatible via §3.3; **Exhibit B is the opt-out — never add it**, and watch that no contributor does

| Rejected | Why it lost |
|---|---|
| GPL-2.0-or-later (the literal WordPress model) | "Are plugins derivative works" is legally murky and *socially settled as yes* in WordPress-land. Conflicts with proprietary third-party plugins and ADR-004's paid modules. |
| AGPL-3.0 | Strongest OSI SaaS protection, but many enterprises ban AGPL outright — a direct cost to the migration goal — and it makes proprietary paid modules uncomfortable. |
| Apache-2.0 / MIT | Maximally adoptable, but a competitor may fork core, close it, and compete with zero obligation to give back. MPL at least forces core improvements to return. |
| BSL / source-available / custom | Not open source. Violates "open source from day one"; Directus is the live cautionary tale. |

**Operational rule:** your **file boundary is your license boundary.** Proprietary modules extend via hooks and interfaces and never patch a core file — editing any core MPL file keeps that file MPL.

### The CLA — the one genuinely irreversible item

Dual-licensing requires owning or being licensed all rights. **The first community PR merged without a CLA permanently forecloses that option** for that code. MongoDB, Grafana and Elastic all had CLAs early. Retrofitting means chasing every past contributor; some won't answer, some will refuse. This doesn't commit the project to dual-licensing — it preserves the option, at zero cost, and the option cannot be recovered later.

---

## ADR-006 — Schema storage: JSON + generated columns

**Status:** Decided · direction **forced** by ADR-010 · ⚠️ **Revised by ADR-021** · ✅ **Mechanism verified 2026-09-07** — composite indexes now lead with the model's scope key (`site_id` for site-scoped, `org_id` for org-scoped), not `tenant_id`. The original text below is left as written

Steal Drupal's field-storage *shape*, not its storage *strategy*. The `FieldStorage` / `FieldConfig` split: storage defined once and reusable across entity types, per-type presentation separate, **storage locked once data exists**. The lock-on-data-present guard ships in v1.

Do **not** copy table-per-field — the direct cause of the 697-second query in ADR-001. **JSON column + stored generated columns with real indexes, every composite index leading with `tenant_id`.** Postgres and MySQL differ in generated-column syntax and JSON path operators; abstract behind a driver interface from the first commit. Benchmark at 10k / 100k / 1M on both.

**Relations use a real `entry_relations` table, not JSON** — reverse lookups ("what references this?") and referential integrity are impossible to do efficiently in a JSON array.

### Verification — 2026-09-07

The mechanism this ADR rests on is proven rather than assumed. `SchemaDriver` and three implementations exist, and one parity suite passes against SQLite, PostgreSQL and MySQL.

Four divergences, not the two originally noted: the JSON path operator, the cast form, **identifier quoting** — `values` is reserved on MySQL and PostgreSQL, and this document's own SQL examples were unquoted and would have failed as written — and whether the column can be materialised at all. SQLite cannot add a STORED generated column through `ALTER TABLE`; it takes a VIRTUAL one, which is still indexable, and that inverts the cost model in SQLite's favour: no write amplification, no table rewrite, paid for by evaluating per row scanned.

The driver fails closed on an unknown engine, because falling back to a probably-compatible driver is how a generated column silently indexes nothing.

---

## ADR-007 — Migration: a source-agnostic adapter framework

**Status:** Revised 2026-09-07 — **generalized; WordPress is one adapter, not the architecture**

**Correction to an earlier draft:** the first roadmap treated "Kitsune imports your WordPress site" as the product's go-to-market wedge. That over-weighted one example. Migration is a **pluggable adapter framework** with a canonical intermediate representation; every source maps into the same IR and adapters never touch Kitsune internals directly. First adapters: WordPress, then CSV/JSON, then whatever demand appears.

**Two separate tracks, deliberately:**

- **A known, bounded set of existing sites** → a throwaway script, weeks not months, run as a parallel track once the schema engine lands. This is the single biggest scope saving available and it still delivers the actual goal early
- **The product-grade adapter framework** → v1.3

**`migration_map` (source id → Kitsune id) makes imports resumable and re-runnable** — re-run against a changed source and update rather than duplicate. That matters more than any single adapter's fidelity. Every adapter must support dry-run with a full report before writing.

**Why WordPress specifically is harder than it looks** — retained because it's still the first adapter:

- WXR **excludes site options and attachment files** (references only), plus installed plugins/themes, configuration, and CPT definitions. WordPress's own data-liberation project calls media handling *"a failing in the core exporter"* and notes exports fail to facilitate *"migrating off WordPress"*
- ACF stores each field as **two `wp_postmeta` rows** — value plus an underscore-prefixed field-key row. Without the second, the first is uninterpretable. Repeaters expand to index-prefixed keys with serialized PHP that breaks on any byte-length change
- **The real blocker:** ACF field-group definitions and CPT registrations usually live in **PHP code or `acf-json/`, not the database.** Neither WXR nor a DB dump reliably yields the *schema* — only the values
- Direct-DB import is meaningfully better but costs self-service file upload

---

## ADR-008 — Build on full Filament v5 panels

**Status:** Decided · 2026-09-07

Kitsune's admin is a Filament v5 panel — full panel builder, not a fork, not just standalone packages.

**Why:** Worth roughly a year for a solo developer. Inherits **1,013 plugins from 464 authors** on day one. MIT, combining cleanly with an MPL core.

| Rejected | Why it lost |
|---|---|
| Standalone packages only | Gives up the Resource system — the biggest labor saving — and the plugin ecosystem. |
| Hand-rolled pure Blade + Livewire | Every field type, table, filter and widget from scratch. Not achievable solo on any reasonable timeline. |
| Fork and vendor Filament | Owning a large foreign codebase and drifting from upstream security fixes. |

**Accepted risks:**

1. **Filament's major cadence is now Kitsune's.** v3→v4→v5 roughly annually. Budget one major upgrade inside the v1.0 window; keep abstractions thin enough to survive it.
2. **Pin `^5.4`** — Laravel 13 support landed only in v5.4.0; v5.0–5.3 constrain to `^11.28|^12.0`.
3. **Two-tier ecosystem reality.** Third-party plugins register hand-written Resources against their own models, so they sit *beside* the schema engine, not inside it. Present that deliberately.
4. **Most Filament plugins are not tenancy-aware** — a live cross-tenant leak vector under ADR-009. Any allowlist needs a tenancy audit.

---

## ADR-009 — Multi-tenancy in core, enforced fail-closed by the kernel

**Status:** Decided · 2026-09-07 · **Boot-order collision resolved by ADR-012** · ⚠️ **Substantially revised by ADR-021** — there are now *two* scoping levels (Org and Site), the attribute set is three-valued, and Filament's automatic scoping protects site isolation but **not** org isolation

Tenancy is a kernel primitive from day one, in core. **Enforcement lives in the kernel, not in plugin authors' discipline.**

**Why in core:** KaaS is an explicit goal and tenancy is the hardest thing here to retrofit. It also supports agencies running many sites on one install.

**The problem, verified from Filament's docs and source:** Filament's tenancy scopes Resources automatically **and nothing else.**

- Models without a Filament Resource are **not scoped**
- Laravel's `unique`/`exists` **ignore global scopes**; `scopedUnique()`/`scopedExists()` are required
- Queries before tenant identification are **unscoped**
- Filament's own source (`BelongsToTenant.php`): *"Filament does not guarantee multi-tenant security; it is your responsibility to implement correctly."*

**Required mitigation, non-optional:**

1. `#[TenantScoped]` / `#[TenantAgnostic]` mandatory on every model. Undeclared = exception in dev, refuse-to-serve in prod. **Fail closed, never open**
2. Schema-engine entities tenant-scoped **by construction**
3. `scopedUnique()` / `scopedExists()` as form-layer **defaults**
4. Module manifest requires a `tenancy:` declaration; the kernel refuses to load without it
5. Plugin SDK ships a two-tenant fixture; the validation CLI fails the build on any cross-tenant read
6. **`{type}` is user-controlled URL input** — middleware must 404 unknown or cross-tenant types (see ADR-012)
7. Every composite index leads with `tenant_id`
8. A deliberately hostile cross-tenant test in core

| Rejected | Why it lost |
|---|---|
| Core single-tenant; KaaS provisions isolated instances | Simplest and safest, but gives up the agency/multi-site segment and pushes a core capability into proprietary code. |
| Database per tenant (stancl/tenancy) | Clean isolation but N migrations and real ops overhead. Still open as the KaaS *deployment topology*. |
| Shared DB, no kernel enforcement | The dangerous default. One unscoped query in one third-party plugin leaks customer data. |

---

## ADR-010 — One `Entry` model with a type discriminator

**Status:** Decided · 2026-09-07 · **Forced by verification, not chosen**

All user-defined entity types share **one Eloquent model** (`Entry`) with a type discriminator plus JSON value storage. **No table or model class per user-defined entity type.**

**Why forced:** Filament's `Panel::getModelResource()` caches a one-model-to-one-resource map, and both route-model binding and policies key off `getModel()`. `getModel()` must be constant.

**Consequences:**

- ✅ Converges with ADR-006 — JSON storage was already the preference; now a constraint
- ✅ Cross-entity-type queries trivial
- ⚠️ Per-type indexes require generated columns over JSON
- ⚠️ Relations need the explicit `entry_relations` table
- ⚠️ **One model = one policy.** Per-type authorization is not free; `EntryPolicy` resolves against `type_handle` with permissions named `entry.{type}.{action}`

**If discovered in month ten instead of week one, this would have meant a rewrite.**

---

## ADR-011 — v1.0 scope cut

**Status:** Decided · 2026-09-07 · **Corrects an earlier bad estimate**

The original roadmap claimed 12–18 months part-time for tenancy kernel + module system + schema engine + blueprints + REST API + theming + importer + plugin SDK. **That was wrong by roughly 3–4x.**

Verified comparables: Statamic v2 ~2 years with a team of domain experts who already had revenue; Statamic v3 spent **9 months in beta alone** with 3 full-time devs; Payload ~18 months funded and full-time; October CMS ~2 years with 2 devs. Those consumed 5,000–15,000 hours. 12–18 months part-time is roughly 1,000–1,500.

**Decision:** **v1.0 = tenancy kernel + module system + schema engine + blueprints.** A multi-tenant app where a non-developer defines entity types in the admin and gets working CRUD, permissions and blueprints. That alone is a real product.

Deferred: REST API and theming → v1.1. Plugin SDK and API freeze → v1.2. Migration adapter framework → v1.3. KaaS control plane → v1.4+.

**Also budgeted:** support load is roughly a **3x multiplier**, arriving exactly when you get users. Statamic's founder on their v2 beta: it *"would have probably taken 6 weeks of focused work [but] took nearly 4 months while handling support, managing expectations, communicating changes, and replying to repetitive bug reports."*

---

## ADR-012 — Admin routing: entity type as a route parameter

**Status:** Decided · 2026-09-07 · **Settled by a working measurement spike, not by reasoning**
**Supersedes** the `ResourceConfiguration` approach specified in the first roadmap draft.

**Decision:** One `EntryResource` with the entity type as a path segment — `admin/{tenant}/c/{type}`, `/{type}/create`, `/{type}/{record}`, `/{type}/{record}/edit`. **7 admin routes total, regardless of how many entity types exist.**

**Why `ResourceConfiguration` was rejected — it is impossible, not merely slow.** `panel()` runs during service-provider boot, before routing, auth and tenancy. Instrumented on a real authenticated request:

```
panel() ran | Filament::getTenant()=NULL | auth()->id()=NULL | request()->route()=NULL
```

The tenant is a **route parameter**, so one route table is shared by every tenant. Design A therefore requires registering the **union of all tenants' types** on every request — 500 tenants × 20 types = 10,000 configurations = **40,000 routes per request.** Measured cost is exactly 4 routes per configuration, linear, no knee (0 configs → 7 routes/145ms; 2000 configs → 8007 routes/333ms).

**And it is uncacheable and unsafe.** `Panel::cacheComponents()` deliberately writes `'resourceConfigurations' => []`. Worse, `route:cache` breaks it **silently**: after caching at N=200 and removing types, **822 stale routes survived and stayed reachable**, with `getConfiguration()` returning `null` and `getSlug()` falling back to the default. If the configuration carried the tenant/type scope, that is a **scoping failure returning HTTP 200 rather than an error.** That is the six-month bug, and it is why this was rejected outright.

**Verified properties of the adopted design:**

- `Page::route(string $path)` accepts arbitrary paths — officially documented
- **`route:cache` fully compatible** — a type inserted after caching returned HTTP 200 and appeared in the sidebar; `{type}` is a parameter, nothing to go stale
- Full Resource retained: route-model binding, policies, relation managers, breadcrumbs, global search
- 353 ms vs 369 ms at 207 nav items — speed is *not* the differentiator; tenant-specificity and cache correctness are

| Rejected | Why it lost |
|---|---|
| `ResourceConfiguration` × N | Cannot express tenant-specific types at all; uncacheable; `route:cache` produces silent scoping failures. |
| Nested / parent resources | `ParentResourceRegistration` hard-requires a real parent model and relationship, and Filament disables global search under nested resources entirely. |
| Custom Filament Pages with hand-written routes | Unnecessary — Resources handle it — and would forfeit route-model binding, policies, relation managers and global search. |

**Three non-optional implementation details** (full detail in [`architecture.md`](architecture.md) §2):

1. **Never add `{type}` to `mount()`** — signatures are locked; it's a PHP fatal error. Use Livewire's `boot{Trait}()` hook
2. **`->tenantMiddleware([IdentifyEntryType::class], isPersistent: true)`** setting `URL::defaults(['type' => ...])`. Without `isPersistent`, **every Livewire update 500s** with "Missing required parameter: type". Verified by toggling only that flag
3. **Memoize `Panel::navigation(Closure)`** — it runs after tenant identification (good) but fires **5× per request** (layout, sidebar ×2, topbar ×2) with no memoization

**Also required:** reserve type handles that collide with route segments — at minimum `create`, `edit`, `delete` — and reject them at type-creation time.

### Amendment — relation-manager spike, 2026-09-07

The highest-risk open question against this ADR was whether Filament relation managers survive the extra route parameter. **Spike run on Laravel 13.30.1 / Filament v5.7.8 / PHP 8.4.25. The design holds, and the spike found two implementation requirements the original ADR did not state.**

**Confirmed working.** Both relation-manager shapes render and operate under `/c/{type}/{record}/edit`: a `HasMany` (revisions) and a `BelongsToMany` through the real `entry_relations` table (ADR-015 Relational storage), including deferred `loadTable`, search, pagination, and Attach/Detach/Edit/Delete actions. Every Livewire update returned **200**; no *"Missing required parameter"* occurred anywhere. Every generated URL carried a populated `/c/<type>/` segment.

**Relation managers register no routes of their own.** They are Livewire components mounted on the page, and their snapshot memo carries the original path (`admin/golfdom/c/article/1/edit`), which Livewire re-matches on update. The original fear — *"they register their own routes and URLs"* — does not apply to `RelationManager`.

**`ManageRelatedRecords` pages, which do register a route, were cleared separately on 2026-09-07.** A page registered at `/{type}/{record}/related` returns 200, its Livewire updates return 200, every URL it generates carries a populated `{type}`, and its Attach action is reachable with a working modal. **ADR-012's URL contract now has no untested corners.**

One correction worth keeping, because it is the failure mode this log exists to prevent. The first version of that claim said Attach "operates", while the test behind it only *read* a pre-seeded relation. Attach did not operate: `entry_relations.org_id` is `NOT NULL`, the pivot has no model to stamp it, and every attach failed on a constraint. Caught in review. The relationship now stamps and constrains `org_id` through `withPivotValue()`, and the claim is scoped to what is actually exercised.

**Finding 1 — navigation is a correctness requirement, not a performance one.** This ADR framed `Panel::navigation(Closure)` as a memoization concern. It is more than that. Filament auto-registers a navigation item per Resource and calls `getUrl()` on it while rendering the sidebar; with `{type}` in the URI and none in the current request, that throws and **500s every page outside `/c/{type}`, the dashboard included.** `$shouldRegisterNavigation = false` on the Resource is mandatory. The 5×-per-request claim was measured and is exact.

**Finding 2 — `original_request()` is namespaced.** It is `Filament\Support\original_request()`, not a global helper, and needs a `use function` import. The fatal is invisible on initial render — `??` short-circuits while the route parameter is present — and fires only on the first Livewire update. A page that renders perfectly and dies the moment anyone clicks is exactly the failure shape this ADR was written to avoid.

**`route:cache` re-confirmed.** An entry type inserted *after* `route:cache` was reachable at its URL and appeared in the sidebar. Route count stayed at 8 with 5 types in the database.

---

## ADR-013 — PHP 8.4 minimum, 8.5 as CI primary

**Status:** Decided · 2026-09-07

**Decision:** `"php": "^8.4"`. Develop and run CI primarily on 8.5, with 8.4 in the matrix. Revisit the floor at v1.0.

**Why not 8.3** (the Laravel 13 floor): PHP 8.3's **active support ended 2025-12-31** — it is already security-only. A poor floor for a greenfield project that ships in 2028.

**Why not 8.5 as the floor:** shared hosting lags, and the early audience is migrating off CMSes that often run on it. 8.4 is security-supported to **Dec 2028**, which covers the v1.0 window.

**Project-specific reasons 8.4 is the right floor, not just "newer":**

- **Property hooks** let `Entry` expose JSON-backed dynamic fields as real typed properties with `get`/`set` hooks rather than magic `__get`/`__set`. For a schema engine, this is the difference between a statically analyzable core and a bag of magic — PHPStan can analyze field access, IDEs can complete it
- **Asymmetric visibility** (`public private(set)`) fits field and storage definitions: read everywhere, written only by the schema engine
- **Lazy objects** defer expensive schema hydration
- **`#[\Deprecated]`** directly serves Standing Principle #2

---

## ADR-014 — Public repository from the first commit

**Status:** Decided · 2026-09-07

**Decision:** The repository is public from commit one, including the planning documents, before any code exists. The decision log ships with its reversals intact — ADR-003 (React → Livewire) and ADR-012 (`ResourceConfiguration` → route parameter) are both recorded as failures of an earlier plan, not quietly edited out.

**Why:** Standing Principle #3 says publish governance before there is anything to govern. This is the same idea applied to engineering reasoning. A decision log containing only what survived is a marketing document; one that shows a design being disproven by measurement is evidence the project corrects itself. For a platform asking people to bet their content on it, that evidence is the product's first real feature.

| Rejected | Why it lost |
|---|---|
| Private until there's working code | Loses the audit trail of *why* the architecture is what it is — exactly the period when the reasoning is most load-bearing and most easily forgotten. |
| Public, but publish only the final decisions | The reversals are the most useful content in the log. Hiding them makes the remaining decisions look luckier than they were. |

---

## ADR-015 — Three storage strategies, not one

**Status:** Decided · 2026-09-07

Field values do not all live in the `values` JSON column. Every field type declares one of three strategies:

- **`Promoted`** — a real column on `entries`. Closed set: `title`, `slug`, `status`, `published_at`. These are queried on essentially every request regardless of entity type. Not user-extensible; adding a fifth is a platform migration
- **`Inline`** — inside the `values` JSON. The default. Indexable via stored generated columns when opted in
- **`Relational`** — rows in `entry_relations`. Anything pointing at another entry

**Why relations are never an ID array in JSON:** a JSON array cannot answer *"what points at me?"* without a full table scan, and cascade-on-delete becomes application code that will eventually be wrong. Deleting an image should be able to name the eleven articles using it. That needs a real table with a real index.

| Rejected | Why it lost |
|---|---|
| Everything in the `values` JSON | No reverse lookups, no referential integrity, no efficient faceting. The mistake every JSON-first CMS makes. |
| Everything relational (full EAV) | Reproduces Drupal's join explosion — the 27-join, 697-second query in ADR-001. |
| No promoted columns; `title`/`slug`/`status` in JSON | Every list view, URL resolution and status filter would hit a generated column or a JSON path. These four are hot on every request; make them real columns. |

**Consequence:** indexing is opt-in per field, capped per entity type (starting at 20, pending Phase 1 benchmark data). Without a cap, one tenant can degrade the shared `entries` table — a noisy-neighbour incident on KaaS.

---

## ADR-016 — Media are entries

**Status:** Decided · 2026-09-07

There is no separate media subsystem. An uploaded file is an **entry** of a system entry type (`image`, `document`, `video`) carrying its own fields — alt text, caption, credit, rights, expiry. The bytes live in a companion `media_files` table joined 1:1 to that entry (disk, path, mime, size, checksum, dimensions, duration).

A "media picker" field is therefore just `relation` constrained to media entry types. No new storage strategy, no second permission model, no parallel search index.

**Why:**

1. **The DAM use case becomes a blueprint rather than a subsystem.** Add rights-holder and licence-expiry fields to the `image` type and you have digital asset management. That was one of the four use cases in the project's premise, and it costs nothing extra
2. **Revisions, permissions, audit and tenancy apply to assets automatically**, because assets are entries and entries already have all four
3. **"What uses this image?" is a query on `entry_relations`**, not a full-text search for a filename across every JSON blob

| Rejected | Why it lost |
|---|---|
| A dedicated `media` table and subsystem | Duplicates permissions, revisions, audit and tenancy for a second entity shape — four systems to keep in sync forever, and the DAM use case then needs building twice. |
| Files as a field type storing paths inline | No metadata, no reverse lookup, no dedupe, no rights tracking. Fine for a blog, useless for a DAM. |

**Accepted trade-off:** one `entries` row per asset makes the table larger than a dedicated media table would, and bulk-importing 10,000 assets writes 10,000 entries plus their relations. Acceptable, but it is a real number to watch in the Phase 1 storage benchmark.

**Users are explicitly not entries** and never will be — different lifecycle, different privacy obligations, and a different deletion story (erasing a user must not cascade-delete their articles). Use `relation` to a `person` entry type for editorial bylines.

---

## ADR-017 — Content translation: entry-level rows with per-field scope

**Status:** Decided · 2026-09-07 · **Revised by ADR-021** — `entries.locale` is deleted; locale is derived from `sites.locale`, and the uniqueness constraints collapse accordingly

Each translation is its own row in `entries`, linked by a translation group. Per-field control over what varies, with fallback to an origin row.

```
entries
  locale             string  indexed      -- 'en', 'fr-CA'
  translation_group  uuid    indexed      -- shared across siblings
  origin_id          FK nullable          -- null on the origin row
  UNIQUE (tenant_id, translation_group, locale)
  UNIQUE (tenant_id, entry_type_id, locale, slug)   -- slugs differ per locale; correct for SEO
```

`field_storage.translation_scope` is an **enum, not a boolean** — `shared | per_locale`, with room for `per_language` so `fr-CA` and `fr-FR` can share while `en` differs. Every composite index gains `locale`: `(tenant_id, locale, idx_price)`.

> **Amended 2026-09-09 (issue #40): the column is not created until content translation is built.**
>
> `translation_scope` shipped in the schema with a default of `per_locale` and was read by nothing. A column with a default does not merely sit there — it **asserts a behaviour**: every row in every install claimed its field was translated per locale, and no code honoured that claim. A later reader, human or agent, would reasonably conclude translation existed because the schema said so.
>
> Dropped rather than made fail-closed. `pii_class` fails closed because an answer is required *now* and getting it wrong is a privacy defect (ADR-020); `translation_scope` has no consumer to fail closed *for*, so refusing writes would add a guard protecting nothing. And re-adding a column while installs are pre-alpha is free, whereas a schema that promises what the code does not do is how migration debt starts.
>
> This does not revise the decision above. Entry-level rows with per-field scope is still the design, and this column is still how the scope is recorded — it arrives with the code that reads it.
>
> ⚠️ The schema sketch above is **stale in two ways** and is left as written because it documents what was decided rather than what exists: `tenant_id` became `org_id`/`site_id` and `entries.locale` was deleted, both under ADR-021, which the status line records. Anyone implementing this should read ADR-021 first.

**Why field-level is not merely worse but disqualified.** Field-level means a locale map inside the JSON — `{"title": {"en": "Hello", "fr": "Bonjour"}}`. A stored generated column cannot project that to a scalar, so indexing would need **one generated column per indexed field per locale**: 20 indexed fields × 10 locales = 200 columns on the shared `entries` table, and adding a locale becomes an `ALTER TABLE` that locks that table **for every tenant on the box**. ADR-006 and ADR-015 chose generated-column indexing specifically to avoid Drupal's join explosion; field-level translation collides with it head-on.

**Prior art agrees, from both directions.** Craft stores content per site and translates field-by-field via a per-field Translation Method, with untranslated fields sharing values across sites. Statamic does entry-per-site with an **origin** — *"A localized entry should define where it originated, and will inherit any undefined values from its origin"* — and `localizable: false` makes a field read-only in localizations.

| Rejected | Why it lost |
|---|---|
| Field-level (locale map in the JSON column) | Incompatible with generated-column indexing — the mechanism ADR-006/015 depend on. Not a preference; it does not work. |
| Entry-level with no per-field scope | SKU, price and dimensions would duplicate across every locale row and drift. Per-field scope is what makes row-per-locale safe. |
| Read-through to origin for `shared` fields | Decisive failure: a generated column on a shared field would be NULL on localization rows, so "French products under $50" silently returns nothing. Shared values must be physically present in every row to be indexable. |

**Consequence — fan out on write, optimize for read.** `shared` fields are denormalized into every locale row; only the origin is editable and the engine propagates to siblings in one transaction. A CMS reads far more than it writes, and the fan-out is bounded by locale count.

**Costs accepted:**

1. **Row multiplication** — 10 locales × 100k entries = 1M rows on a shared multi-tenant table. Must be *in* the Phase 1 benchmark, not discovered after it
2. **Origin deletion is a real UX problem.** Statamic documents the fork: delete the localizations, or detach them as standalone entries. Kitsune needs an answer before v1.0
3. **Explicit localization** — an entry exists in a locale only once localized there. Correct default, but "why isn't my French page showing" becomes a permanent support question

**Open:** do relations target the translation group or a specific locale row? Group-targeting with an optional locale override is the leading candidate.

---

## ADR-018 — Product internationalization is a requirement, not a nice-to-have

**Status:** Decided · 2026-09-07 · **Amended 2026-09-08** — the RTL spike is closed and one factual claim below was wrong. See *Spike result* at the end of this entry.

Kitsune's own interface, errors and eventually docs are translatable from the first commit. *Open source means open to everyone, not open to everyone who reads English.*

This is a **separate axis from ADR-017.** Content i18n serves a site's readers; product i18n serves its operators. The two locale lists are independent — a tenant may publish in 3 languages while its editors work in 5. Never conflate them.

**Filament does most of the work.** The 5.x branch ships **64 locales** in `filament/panels`, including `ar`, `he`, `fa` and `ur` — all four major RTL languages. The entire admin chrome is already translated; Kitsune only ever translates its own strings.

> ⚠️ **Amended 2026-09-08:** the 64 is right and "four" is wrong. **Six** locales ship a `direction => 'rtl'` translation — `ar`, `ckb`, `fa`, `he`, `ku`, `ur`. Sorani and Kurmanji Kurdish were missing from the count, which was taken from the languages that came to mind rather than from the lang directory. `Kitsune::RTL_LANGUAGES` holds the measured six.

**Three rules:**

1. **No bare user-facing strings in UI code.** A CI check, build-failing, from commit one — same class of rule as the tenancy attribute. Costs nothing on an empty codebase, unaffordable at month eighteen
2. **UI locale is a user preference, not a tenant setting.** A Swiss agency has German, French and Italian editors on one tenant
3. **RTL from the start.** Tailwind 4 logical properties make it nearly free now and painful later. Filament shipping four RTL locales means Arabic speakers will arrive

**Runtime labels are content, not product strings — the non-obvious part.** A tenant creates a field labeled "Price"; a French editor on that same tenant opens the form. `fields.label`, `entry_types.name`, `plural_name` and select option labels are **tenant data written at runtime**, so they cannot live in `lang/` files. They need their own localization:

```
fields.label   json   -- {"en": "Price", "fr": "Prix", "de": "Preis"}
```

falling back to the tenant's default locale. Trivial to design in now; a migration of every label in the system to retrofit.

| Rejected | Why it lost |
|---|---|
| English-only until there's demand | Retrofitting means auditing every file for hardcoded strings. The discipline is only free while the codebase is empty. |
| Translate the docs early | Docs churn constantly and a stale translation is worse than an English one — confidently wrong. UI strings are small and stable; docs come after v1.0, community-driven, with visible staleness markers. |
| Crowdin | Both have free OSS tiers and Crowdin is more trodden, but Weblate is itself libre and self-hostable — the same argument this ADR is built on. |

**Also:** CONTRIBUTING states issues in any language are welcome, with an honest disclaimer — the maintainer reads English natively and gets by in German and Italian; everything else goes through machine translation, so nuance and tone will sometimes be lost in both directions, and a reply that reads as blunt is more likely the translation than the intent. Security reports and anything touching the CLA or licensing are safer in English where possible, because a mistranslation there carries consequences a misunderstood feature request does not. Stating the limitation is the point: welcoming other languages while quietly handling them badly is worse than saying what people are getting.

**Open / needs a spike:** Filament ships RTL *translations*, but its RTL **layout** completeness in v5 is unverified — a translated string in a left-aligned sidebar is still broken. Add an RTL render check to the Phase 1 spikes. Separately, **pluralization**: Laravel's helper handles two forms; Polish has four, Arabic six. ICU MessageFormat is the likely answer and should be settled before the first translatable string.

### Spike result — 2026-09-08 (issue #12)

Measured against Filament **v5.7.8**. Full inventory in [`accessibility-inventory.md`](accessibility-inventory.md); the parts that change this ADR:

**The layout question is answered: Filament's RTL layout is complete for every page shape Kitsune uses.** The admin is rendered under `APP_LOCALE=ar` and compared against the LTR render of the same page, and the comparison is a relation rather than an attribute check — an element `n` pixels from the left edge in LTR must sit `n` pixels from the *right* edge in RTL. Drift was **0** on all five layout landmarks across three page shapes at 1280px, with zero axe WCAG 2.1 A/AA violations under RTL and no horizontal overflow. The sidebar is positioned with `inset-inline-start`, so the browser mirrors it. "A translated string in a left-aligned sidebar" does not happen here, and the sidebar moves to the other side of the screen.

**The spike also disproved its own blocker, which is the part worth remembering.** The browser suite had recorded that an RTL render check "needs the locale switcher that does not exist yet". It does not: `dir` comes from `__('filament-panels::layout.direction')`, so `APP_LOCALE` alone decides it and a second server on another port is the entire harness. That claim had been reasoned, not measured, and it parked half of a Phase 1 spike behind nothing. Standing Principle #9 and invariant 15.

**What the spike moved from "unverified" to "Kitsune's to build"** — because with Filament cleared, everything left is ours:

1. **Kitsune's own output had no `dir`.** The skeleton's page emitted `lang` and nothing else, so an RTL locale served RTL text in an LTR document. Filament supplies this for the admin from its own translations, and the public side has no panel and must not depend on one (ADR-002) — so nothing was going to. `Kitsune::textDirection()` supplies it now. **Fixed.**
2. **`sites.locale` is applied by nothing.** No middleware maps it onto `app()->setLocale()`, so direction resolves per *process* and a multi-site install cannot serve one site RTL and another LTR concurrently. This is the accurate, narrow version of the "locale switcher" claim. Note that rule 2 above makes it **two** mappings, not one: the UI locale is a user preference and the content locale is the site's, and they are allowed to disagree.
3. **No per-field content direction.** `dir` appears **exactly once** in Filament's entire view layer — the root `<html>`. No input, textarea or table cell carries `dir="auto"`, so content always renders in the direction of the *chrome*. For a bilingual org — which rule 2 exists to serve, and which the seed fixture already models — that lays out punctuation and numerals the wrong way. Kitsune's to add.

**Pluralization is untouched by this spike** and stays open exactly as written above.


---

## ADR-019 — Path segments encode resource identity; everything else is state

**Status:** Decided · 2026-09-07

A URL path segment identifies *which thing you are looking at*. Viewer preferences — UI locale, theme, view mode, density, timezone — are never path segments. They are query parameters or persisted user state.

`{tenant}` and `{type}` are in the path because they are identity. That is *why* ADR-012's design is correct, rather than it being correct by luck.

**Immediate application: locale is not a third path segment.**

| Rejected | Why it lost |
|---|---|
| `admin/{tenant}/c/{type}/{locale}/...` | Every link becomes a 3-tuple every `getUrl()`, redirect, breadcrumb and notification must carry; the ones that forget fail in the least-tested paths. Compounds — multiplicatively, not additively — the relation-manager risk that is still ADR-012's highest unknown. Server-generated notification links would bake the **sender's** UI locale into a URL sent to the recipient. And one segment cannot express both content locale and UI locale, which are orthogonal — a German editor may be editing the French translation. |

**And it turns out neither locale needs one.** ADR-017 gives every locale its own entry row with its own ID, so the record ID already encodes the **content** locale — `/c/article/42/edit` where 42 *is* the French row. **UI** locale is a user preference and was never resource identity. The route contract stays at three params.

UI locale binds via Livewire's `#[Url]` query-string attribute, falling back to the user's stored preference. **Verify this in the Phase 1 spike alongside relation managers** — query-string binding survives Livewire updates differently from path parameters.

**The value here is the precedent, not the URL.** Without a stated principle, every future preference reopens the argument.

### Amendment — the public front-end is the exception, and it matters

**Added 2026-09-07.** The principle is unchanged; applying it correctly gives opposite answers in the two contexts, because the resource being identified is different.

**In the admin, you are editing a row.** ADR-017 gives each locale its own entry with its own ID, so `/c/article/42/edit` already identifies the French record. Content locale needs no segment. UI locale is viewer preference and gets none either.

**On the public front-end, you are serving a document in a language, and the language is part of what the document *is*.** Content locale belongs in the path:

```
/fr/mon-article           path prefix
fr.example.com/...        subdomain
example.fr/...            separate domain
```

All three must be supported — publishers use all three, and the choice is usually made by the marketing team, not the CMS.

**Why this is not optional.** Serving translations as `?locale=fr` on public URLs would:

- **Break SEO.** Search engines expect a distinct, canonical URL per language, cross-linked with `hreflang`. A query-parameter locale is at best poorly indexed and at worst treated as duplicate content
- **Break sharing.** A pasted URL should tell you what language you're getting
- **Waste ADR-017's design.** `UNIQUE (tenant_id, entry_type_id, locale, slug)` exists precisely so slugs differ per language — `/fr/mon-article`, not `/fr/my-article`. That constraint is pointless if the public URL doesn't carry the locale

This is a real commercial consequence, not a purist point: getting it wrong tanks organic search for every multilingual site on the platform, and on KaaS that is every tenant at once.

**Requirement for the v1.1 theming layer:** locale in the public path (or subdomain, or domain), per-locale slugs, and automatic `hreflang` + canonical tags. Not retrofittable without breaking every published URL.

---


---

## ADR-020 — Privacy by design: classification, redactable revisions, payload-free audit

**Status:** Decided · 2026-09-07
**Not legal advice.** A privacy attorney should review before any compliance claim appears in marketing, a DPA, or a security questionnaire. What is settled here is which obligations are *architectural*.

### The category distinction that drives this

**GDPR and CCPA are core requirements. SOC 2 and ISO 27001 are not.**

SOC 2 audits an organisation's controls over a period; ISO 27001 certifies a management system. Neither is a property of software — you cannot ship SOC 2 in a Composer package. They belong to the KaaS operator (ADR-004), not to Kitsune core.

**But core determines whether they are achievable at all.** Without an audit log, granular access control, retention and erasure, no amount of organisational process makes a deployment auditable. So: **core ships primitives; the operator ships controls and evidence.**

GDPR and CCPA are different in kind, for one reason: **a self-hosted Kitsune operator is the data controller.** The obligation is theirs, and if core cannot locate and delete a person's data, every self-hoster is non-compliant through no fault of their own. Making compliance *possible* is a core responsibility.

### Decision

**1. `field_storage.pii_class` — `none | personal | sensitive`, fail-closed.**

`sensitive` means GDPR Article 9 special-category data (health, race or ethnicity, religion, political opinion, trade union membership, biometrics, sex life or orientation).

A field that has not been classified does not save. Same discipline as the tenancy attribute in ADR-009, for the same reason: retrofitting means re-classifying every field in every tenant by hand, with no way to know which ones were missed.

**2. Entry types designate a subject identifier field.** "Give me everything you hold about this person" is unanswerable without knowing which field identifies the person. A `customer` type nominates its email or its `person` relation as the subject key.

**3. Revisions are field-level redactable, not immutable blobs.** Erasure must reach revision history — article revision 4 still contains the name you just erased.

**4. Audit logs record actor, action and target — never payloads.** *"User 47 updated entry 1203"* survives erasure. *"User 47 changed name from X to Y"* does not. An audit log that captures diffs is a compliance liability wearing a helpful hat, and it puts SOC 2 and GDPR in direct conflict for no gain.

**5. A replayable erasure log.** Backups cannot be rewritten. The workable answer is a documented retention window plus erasure re-applied on restore — which requires core to keep a record of what was erased, containing no erased content.

**6. Encryption at rest for `sensitive`-classified fields**, and retention policies that attach to classified fields.

**7. No telemetry, or opt-in only with visible disclosure.** A CMS that phones home by default creates a processing relationship the self-hoster never agreed to and cannot document.

### Rejected alternatives

| Option | Why it lost |
|---|---|
| Leave compliance entirely to the operator | In a runtime schema engine the platform cannot locate personal data without a declaration, and the operator has no mechanism to add one. This ships a legal liability to every self-hoster. |
| Infer PII from field names or types | "Customer Notes" holds personal data; "notes" might not; and a tenant's German or Japanese field labels defeat English heuristics entirely. Guessing about legal obligations is not a design. |
| Classify at entry-type level instead of field level | Too coarse. An article has a public title and a private contributor email in the same entity. |
| Immutable revisions | Erasure cannot reach them, so the right to erasure is unimplementable. |
| Audit logs that capture field diffs | The payload becomes unerasable, putting the SOC 2 requirement and the GDPR requirement in permanent conflict. |
| Telemetry on by default, opt-out | Creates undisclosed processing, and is exactly the sort of default that ends a project's reputation in one thread. |

### Consequences

- **Data residency constrains KaaS topology.** If EU tenants' data must remain in the EU, a single shared multi-tenant database cannot span regions. This pushes toward region-scoped deployments or database-per-tenant, and it narrows the deployment-topology question left open under ADR-009. That question now has a legal input, not just an operational one
- **Right of access needs a query strategy** across classified fields, spanning entries, revisions and relations
- **CCPA specifics:** honour Global Privacy Control. California's revised CCPA regulations took effect January 2026 and enforcement has been active, so universal opt-out signals are not optional for anyone in scope
- **Build for GDPR** as the strictest regime; UK GDPR, LGPD, PIPEDA and the growing set of US state laws largely follow from it
- Erasure tooling, subject-access export and consent records are **v1.1 features built on v1.0 primitives** — the `pii_class` column, the subject identifier, redactable revisions and payload-free audit must land in Phase 2–4 regardless

---

### Amendment — an unauditable write is refused, 2026-09-07

**Status:** Amended

Primitive 4 settles WHAT an audit row contains. It says nothing about which
writes produce one, and that turned out to be the harder half: five review
rounds found five different paths that changed or created entries with no
audit row, each one ordinary Eloquent that application code reaches for
without thinking about the trail.

**Model events are not a sufficient hook.** `Entry::query()->update()`,
`->delete()` and `->forceDelete()` compile straight to SQL and dispatch
nothing per row. `createQuietly()` and anything inside `withoutEvents()`
suppress the listener while still inserting. `updateOrInsert()`,
`insertUsing()`, `increment()` and `decrement()` are forwarded whole to the
query builder. Every one of those was covered by the claim that the API and
the console go through the same code path as the admin, and none of them was.

So auditing moved to the **query builder**, which is the one place every write
passes through, and **no model event audits anything**. Pairing the two is
worse than either: `$entry->save()` is itself a builder write, so listening in
both places recorded every ordinary write twice — and no test caught it,
because they all asserted a row *existed* and two rows satisfy that as readily
as one.

**Where a write cannot be audited, it is refused.** `insert()`,
`insertOrIgnore()`, `upsert()`, `insertUsing()`, `updateOrInsert()` and
`truncate()` return a row count rather than the keys they wrote, so there is
nothing to name as the target. Refusing them makes the guarantee statable:
**no Eloquent path creates, changes or removes an entry without an audit row
or a refusal.** The cost is real and belongs on the record — bulk import
through Eloquent's own methods does not work, and wants its own audited path
and its own ADR.

**The guarantee stops at Eloquent, and saying otherwise would be false.**
`Entry::query()->toBase()` returns the underlying query builder, and a write
through it is unaudited — as is `DB::table('entries')->update(...)` or any
raw statement. No model-layer guard can stand in front of raw SQL, and
`toBase()` cannot be overridden because Laravel's own `update()`, `count()`
and `pluck()` all route through it. Reaching past Eloquent is explicit and
visible in review; preventing it would take database triggers, which is a
decision with its own costs and would want its own ADR. An earlier version of
this amendment claimed "there is no unaudited way for an entry to appear,
change or vanish", which was a stronger claim than the code can keep.

`insertGetId()` is the exception, and the reason is exactly why the others are
not: it returns the id it wrote. Creation is audited there.

**And "refused" has to mean refused when the context is missing, too.**
`Auditor::record()` is deliberately silent with no org context, which is right
for an action a caller chose to record — console commands, migrations and the
installer all run without one, and an audit system people switch off records
nothing at all. It is wrong for a write that has already happened: console
code supplying `org_id` and `site_id` by hand inserts an entry perfectly well
without populating `Context`, and the audit would return null while the
transaction committed. The entry paths use `recordOrFail()`, so the write
rolls back rather than landing untraced.

**The audited set and the written set must be the same set.** Reading keys and
then re-running the predicate are two statements over a set that moves between
them: on PostgreSQL a row inserted in the interval is written and not audited,
and one that stops matching is audited and not written. The write therefore
runs against the captured keys, inside one transaction, with the keys read
`lockForUpdate`.

The same applies in reverse to the log itself. `AuditLog` is append-only, and
that is a claim about every path — `update()`, `delete()` and `forceDelete()`
were each overridden as they were found, which is precisely how `truncate()`
survived three rounds and erased the whole table with no event and no
override. The remaining mutators are refused together rather than one review
at a time.

---

## ADR-021 — Sites: a third structural level, and Filament's tenant is the Site

**Status:** Decided · 2026-09-07
**Revises** ADR-009 (two scoping levels, not one) and ADR-017 (locale is derived from site, not stored on the entry).

### The gap this closes

`tenant → entries` cannot express a base URL. `example.fr`, `fr.example.com` and `/fr` are properties of a **site**, not of a locale. And the real-world shape of a publisher is three levels, not two: an organisation owns brands, and a brand owns properties — `golfdom.com` and `mediaplanner.golfdom.com` are different sites of the same brand, and neither is a translation of the other.

### Structure

```
orgs                           -- the customer; billing and user boundary
  id, name, slug, settings json

site_groups                    -- the brand; settings inheritance
  id, org_id, handle, name, settings json

sites                          -- anything with its own base URL
  id, org_id, site_group_id, handle, name
  locale          string       -- this site's language
  url_strategy    enum         -- path | subdomain | domain
  base_url        string       -- 'https://golfdom.com', 'https://example.com/fr'
  theme, is_primary, settings
  UNIQUE (org_id, handle)

entries
  ... site_id  FK **nullable**, indexed   -- NULL = shared across the org
```

**Site carries locale.** `golfdom.com` (en) and `golfdom.fr` (fr) are two sites in one group. This is not a new assumption — **ADR-019 already put content locale in the public path**, and a different base URL is precisely what a site *is*. One mechanism expresses all three URL strategies with no special cases, including path prefixes, where `base_url` is simply `https://example.com/fr`.

**`entries.locale` is deleted.** Locale is derived from `sites.locale`. Queries filter by site, not by locale, so no denormalisation is needed.

**Translation grouping does not move.** `entries.translation_group` still links siblings per-entry (ADR-017). Site groups handle brand and settings inheritance; translation groups handle translation. Independent concerns, no overlap.

### Constraints, simplified

ADR-017's four-column uniqueness collapses, because a site already implies both org and locale:

```
UNIQUE (site_id, entry_type_id, slug)      -- was (tenant_id, entry_type_id, locale, slug)
UNIQUE (translation_group, site_id)        -- one entry per group per site
```

**`site_id NULL` means org-shared**, mirroring the `tenant_id NULL = global` pattern already used for entry types — one idea reused rather than a new mechanism. The media library (ADR-016) defaults to shared, which is what a multi-brand publisher needs.

**Rule that makes the nullable column safe: org-shared entries are not publicly addressable, so their `slug` is NULL.** Shared content is media, taxonomy terms, reusable blocks — things referenced by sites, not published at their own URL. Content that gets published needs a site. This also sidesteps a portability trap: NULLs are distinct in unique indexes across Postgres, MySQL and SQLite, so shared rows can neither collide nor be constrained, and no partial unique index is required (MySQL does not have them).

### ⚠️ The security consequence of mapping Filament's tenant to Site

Filament's tenancy segment is now the **Site**. This keeps the route contract at three parameters — `/admin/{site}/c/{type}/{record}/edit` — and turns Filament's built-in tenant switcher into a site switcher, which is the UX editors actually want.

**But it changes what Filament protects.** Filament's automatic global scope now enforces **site isolation**. It does **not** enforce **org isolation**, because org is a level Filament does not model at all. For any org-scoped model, there is no Filament scope whatsoever — a cross-org leak in `users`, `media` or org settings would not be caught by anything Filament does.

**Kitsune's kernel must therefore enforce org isolation itself.** ADR-009's mitigation is revised from one attribute pair to three, still fail-closed:

```php
#[SiteScoped]   // entries and most content — Filament's tenancy scopes these
#[OrgScoped]    // users, billing, settings, shared media — KITSUNE scopes these
#[Unscoped]     // genuinely global: modules, system entry types
```

An undeclared model still throws in development and refuses to serve in production. **`#[OrgScoped]` models get a Kitsune-authored global scope, because nothing else will give them one.**

The hostile test in core (ADR-009) doubles: it must now assert **cross-site isolation within one org** *and* **cross-org isolation**. The second is the one with no framework safety net.

**Index invariant, revised.** ADR-009 required every composite index to lead with `tenant_id`. It now leads with the model's *scope key*: `site_id` for site-scoped models, `org_id` for org-scoped. Since `site_id` is globally unique and belongs to exactly one org, leading with it enforces org isolation transitively — narrower index, same guarantee.

### Amendment — a fourth scope attribute, for users, 2026-09-07

**Status:** Amended · closes [#21](https://github.com/adamgreenwell/kitsune/issues/21)

This ADR settles the kernel on three attributes and puts **users** among the `#[OrgScoped]` models. The second half of that could not be implemented, and the reason is not an oversight in this ADR — it is a fact about users that only became load-bearing once someone tried.

**`OrgScope` compares `org_id = current`, and a user has no `org_id`.** Membership is many-to-many; `architecture.md` §3 has modelled it through `org_user` since before this ADR. Declaring `#[OrgScoped]` on `User` would have been a declaration the kernel could not keep — the attribute would have resolved, the scope would have been applied, and it would have compared a column that does not exist.

So there is a fourth: **`#[OrgScopedThroughPivot(table:, foreignKey:)]`**, enforced by `OrgMembershipScope`.

A separate attribute rather than an option on `#[OrgScoped]`, because the two enforce genuinely different things — one reads a column, the other tests a relationship. Conflating them would mean a reviewer seeing `#[OrgScoped]` could no longer tell which behaviour a model got, and this ADR's whole point is that the declaration is readable at a glance.

Everything else in this ADR is unchanged and still binding. The new attribute inherits all of it: it **fails closed with no org context**, it gets **no framework safety net** (Filament does not model Org at any level), and it needs the same hostile test the other two have.

**Two consequences worth stating, because both cost time:**

- **Failing closed makes the authentication path a carve-out.** A user is resolved before any org exists — the org is derived from the site they are on their way to — so the login query must stand the scope down explicitly. That carve-out belongs in the **user provider**, not on the model: `EloquentUserProvider` builds its own query through `newModelQuery()` and never calls a method on the user, so a carve-out written as `User::resolveForAuthentication()` reads correctly in review and never executes. It is safe because every query there resolves ONE user by an identifier the caller already supplied, and never lists them.
- **The attribute is a declaration, not an enforcement.** `User` carried `#[Unscoped]` and did not `use EnforcesScope`, so it was labelled correctly and completely unconstrained for two phases. A model can pass the declaration sweep that exists to catch exactly this and still be globally readable. Both are now required together, and AGENTS.md says so.

---

### Amendment — the route key must be globally unique, 2026-09-07

`UNIQUE (org_id, handle)` makes a site's handle unique **within an org**, which is right: a handle is how an operator names a site inside their own organisation, and two customers may both reasonably call one "golfdom".

But the admin URL is `/admin/{site}`, and **it carries no org segment**. A segment that identifies a site therefore has to be unique across the whole installation, which `handle` is not.

For most users this is merely awkward — route binding narrowed to their own sites resolves it. **For a user who belongs to both orgs it is genuinely ambiguous**: both candidates are authorised, `first()` picks one arbitrarily, and `/admin/golfdom` silently opens the wrong customer's site while making the other unreachable. Edits would land against the wrong org.

**`sites` therefore carries a `slug` column, globally unique, and it is the route key.** `handle` is unchanged and stays org-unique. The two are separate because they answer different questions: what the operator calls this site, and which URL owns it.

Found by review, not by design — the original ADR reasoned about the route contract having three parameters and never asked whether the tenant segment was unambiguous.

### Naming rule

**"Tenant" is now ambiguous and is banned from Kitsune's own code.** Filament calls its segment a tenant; Kitsune means a Site. Use **Org** and **Site** explicitly everywhere, and the word "tenant" only at the Filament API boundary. This is a small rule that prevents a large category of confusion, in code and in support threads.

### Rejected alternatives

| Option | Why it lost |
|---|---|
| Site and locale as independent axes | Conceptually tidier — a site is a brand, a locale is a language — but URL strategy becomes a per-(site, locale) join table, and every query, permission check and route carries two axes instead of one. ADR-019 already implies a locale change is a URL change, which is a site. |
| Filament tenant = Org, site as a 4th path segment | Keeps ADR-009 unchanged and one clean scoping level, but adds a fourth path parameter, more `URL::defaults` surface, and compounds the relation-manager risk that is still ADR-012's highest unknown. |
| Filament tenant = Org, site as list state (`?site=`) | Three params and ADR-009 untouched, but it contradicts ADR-019's own principle: "Golfdom's articles" and "Landscape Management's articles" are different resources, not one resource viewed differently. |
| Strictly site-scoped content, no sharing | Simplest scoping and easiest to secure, but a publisher with eight brands maintains eight media libraries and re-uploads the same photo eight times. |
| A designated "shared" site holding org assets | Avoids a nullable column, but invents a site with no base URL that must be hidden from every switcher, URL resolver and site list — a special case in more places than the nullable column costs. |

### Consequences and open items

- **Site count multiplies** — 8 brands × 3 locales = 24 sites. The admin needs genuine grouping in the site switcher, not a flat list. This is a v1.0 UX requirement, not a nicety
- **Syndication across brands is a separate mechanism from translation** and must not reuse `translation_group`. Publishing one article to both `golfdom.com` and `landscapemanagement.net` crosses site groups; translation never does. Deferred to v1.1, but do not let the two share a column
- ~~Per-site entry types~~ and ~~settings inheritance~~ — **both decided in ADR-022**

---

## ADR-022 — Scoped configuration: sparse overrides resolved org → site group → site

**Status:** Decided · 2026-09-07

Settings and entry type availability both resolve through the same three-level hierarchy, with **only overrides stored** at each level.

Magento is the closest widely-known analogue — its Default → Site → Store → Store View scopes solve the same problem for the same reason, and notably its Store View is the locale unit, arriving independently at ADR-021's site-carries-locale conclusion.

### Resolution

```
org.settings          {}                          -- the base
  └─ site_group.settings   { "logo": "…" }        -- brand override
       └─ site.settings    { "analytics_id": "…" } -- site override
```

**Shallow merge on top-level keys.** A setting is atomic: you override the whole key, never part of it. This avoids half-inherited nested objects, which are the single most confusing thing a config system can produce.

**Absent means inherit.** There is no "unset" sentinel and no full copy at each level — a level stores a key only if it overrides it. Sparse storage is what makes an intentional override distinguishable from a stale duplicate.

### Entry type availability uses the same mechanism

A French edition can drop a section that exists in English (ADR-021 left this open; it is now decided). Availability is sparse and inherited, not duplicated:

```
entry_type_availability
  entry_type_id  FK
  scope_type     enum   org | site_group | site
  scope_id       int
  is_enabled     bool
  UNIQUE (entry_type_id, scope_type, scope_id)
```

Absent at every level = enabled. A row exists only where someone made a decision.

**Routing consequence (ADR-012).** `IdentifyEntryType` middleware already 404s on a type that does not exist or belongs to another org. It must now **also 404 on a type that exists but is disabled for this site.** Same middleware, one more condition, same security posture — `{type}` remains user-controlled URL input.

**Content consequence.** Disabling a type on a site hides existing entries rather than deleting them. The admin must say how many entries this affects *before* the change is saved, and public requests for them 404.

### ⚠️ Provenance is a first-class requirement

Magento's scope system is widely understood to be hard to debug — the community maintains an extension called **`configscopehints`** whose entire purpose is showing which scope a configuration value came from. That extension existing is the specification for what Kitsune must ship by default.

**Every resolved setting displays its origin in the admin** — "inherited from site group *Golfdom*", with a link to that level — and every override is visibly an override, with a one-click revert to inherited. Opacity here is not a minor UX defect; it is what makes operators afraid to touch configuration.

### Rejected alternatives

| Option | Why it lost |
|---|---|
| Full config copy at each level | Levels drift, and an intentional override becomes indistinguishable from a stale duplicate. Nobody can then safely change anything at the org level. |
| Two levels (org → site) | Loses brand-level settings entirely. A publisher's logo, analytics account and legal footer belong to the brand, not repeated across its language editions. |
| Four or more levels, Magento-style | Magento's opacity problem worsens with depth, and its fourth level exists for catalog scoping that Kitsune handles elsewhere. Three is the minimum that expresses org, brand and property. **Resist a fourth.** |
| Deep-merging nested setting objects | Half-inherited objects are the most confusing possible outcome, and debugging them is exactly the pain `configscopehints` exists to relieve. |
| Entry type availability as a separate mechanism from settings | Two inheritance systems to learn, document and debug, for one idea. |

### Consequences

- **Cache invalidation must be correct and automatic.** "I changed the setting and nothing happened" is a well-known Magento support burden, caused by config caching. A setting write invalidates the resolved cache for that scope and everything beneath it
- Resolution reads at most three rows, so it is cheap — but it is on every request, and must be memoized per request and cached per site
- **Open:** are there settings that may only be set at org level (billing, security policy) and must be locked against site-level override? Probably yes. A `locked_keys` list on the org is the likely shape

---

## ADR-023 — Governance: stated BDFL, with binding commitments and a disclosed conflict

**Status:** Decided · 2026-09-07

`GOVERNANCE.md` is published before there is code, and states plainly that Kitsune is a single-maintainer, benevolent-dictator project with a **bus factor of one**. No steering committee, no vote, no foundation.

**Why publish it now.** Winter CMS forked October CMS in March 2021 over *process* — maintainers doing the work while decisions were made without them — **five weeks before** the licence change everyone remembers as the cause. Licensing gets blamed; governance is usually the trigger. Writing it down costs nothing today and is unavailable later, because by the time governance is contested it is too late to establish it in good faith.

**The decision log is the governance mechanism, and it binds the maintainer.** A decision not in the log is not made; changing a settled decision means amending its ADR with the old reasoning left visible; direction is never announced retroactively. That is the structural answer to Winter's actual grievance.

### Binding commitments

Core stays under an OSI licence permanently — never BSL, never source-available, never bespoke — and any relicence may only be *more* permissive or a later MPL. Shipped releases stay shipped. The free/paid line does not move, and **no feature is ever removed from open core to create a paid version**. Data export is always present, always free. No telemetry without opt-in. Forking is a guaranteed right; only the name is protected.

### Conflict of interest, disclosed rather than discovered

The maintainer operates a commercial hosted service on the same codebase (ADR-004). **Where a decision benefits the hosted service and the open project differently, the ADR says so explicitly.**

Directus's community objection to its relicensing was not the terms but that the ground moved under them. Disclosing the commercial interest up front, and committing to name it per-decision, is the structural answer to that failure — not a promise to have no commercial interest, which would be false.

| Rejected | Why it lost |
|---|---|
| An aspirational foundation or technical steering committee | Describing a governance body that does not exist is verifiably false in about thirty seconds of commit history. Worse than no document, because it signals the project will say what sounds good rather than what is true. |
| No governance document until there are contributors | The Winter/October timeline is the counter-example. Governance written under contest is not credible; governance written when it costs nothing is. |
| Formal voting from day one | A vote among one person is theatre, and process overhead with no constituency deters the contributors it pretends to serve. |
| Omitting the conflict-of-interest disclosure | The commercial interest is discoverable from ADR-004 anyway. Being the one to state it costs nothing; being caught not stating it costs everything. |
| A code of conduct without acknowledging the single-maintainer gap | With one maintainer, the person receiving conduct reports is the person one might need to report. No wording dissolves that; naming it is the only honest option. |

### Consequences

- **Succession is staged to milestones, not promised vaguely** — repo/Packagist/domain access shared at the second maintainer, a written continuity plan naming a 90-day unreachability process at the third
- **Named triggers for revising governance**: three active maintainers, a second contributing organisation, meaningful revenue, or a serious fork — each gets a public ADR
- **Enterprises evaluating continuity are invited to say so in an issue**, which turns an unmeasurable objection into a countable one

---

## ADR-024 — Testing: Pest, a Docker engine matrix, and browser tests that open a real browser

**Status:** Decided · 2026-09-07

Test-driven development is the working discipline, and the test suite is treated as a deliverable rather than as evidence that a deliverable works. Three layers, each earning its place:

1. **Pest** — unit and feature tests, the bulk of the suite, running on SQLite with no Docker required
2. **A Docker-backed engine matrix** — the same feature suite re-run against PostgreSQL, MySQL and SQLite
3. **Playwright** — a deliberately narrow set of browser tests covering what only a browser can see

**Pint is not a testing tool.** It is a code formatter and it belongs to style, alongside PHPStan. Naming that here because the two get conflated, and a formatter cannot tell you your dashboard is returning 500.

### Why browser tests are not optional, with evidence from this project

The relation-manager spike of 2026-09-07 produced the argument. **The PHPUnit suite passed 7 of 8 while the dashboard was returning HTTP 500.**

The bug was structurally invisible to it. Filament auto-registers a navigation item per Resource and calls `getUrl()` on it while rendering the sidebar; under ADR-012's design that throws on every page *outside* `/c/{type}`. The feature tests only ever requested pages *inside* `/c/{type}`, so nothing in the suite ever rendered the failing case. It was found within seconds of opening a browser.

The second finding has the same shape: `original_request()` is namespaced, and calling it unqualified fatals — but only on Livewire update requests, because on initial render `??` short-circuits before evaluating it. The page renders perfectly and dies when someone clicks.

Neither is exotic. **Both are the normal failure mode for this architecture**, because the load-bearing risk lives in URL generation *across* page boundaries, and that is exactly the seam unit tests do not traverse.

### Why the engine matrix is not optional

ADR-006 stakes the storage design on generated columns, and ADR-015 on indexing over them. Postgres, MySQL and SQLite differ in generated-column syntax, in JSON path operators, and — SQLite specifically — in whether a STORED column can be added by `ALTER TABLE` at all. A suite that runs only on SQLite proves nothing about the mechanism the schema engine depends on.

### Mandatory categories, enforced in CI

These correspond one-to-one with the invariants in `CONTRIBUTING.md`:

- **Every model** — a test asserting it declares a scope; an undeclared model fails the build
- **Every scope boundary** — the two hostile tests from ADR-021: cross-site within one org, and cross-org. The second has no framework safety net and is the most valuable test in the codebase
- **Every field type** — storage round-trip, validation, a cross-org boundary test, and index creation on all three engines (`field-types.md` §9)
- **Every admin route shape** — at least one browser test that loads a page **outside** `/c/{type}`. This is the standing regression test for the ADR-012 spike finding, and it exists precisely because a feature test cannot express it

| Rejected | Why it lost |
|---|---|
| PHPUnit only, no Pest | Pest is the ecosystem default and the better authoring experience; PHPUnit still runs underneath, so this costs nothing and gains contributor familiarity. |
| Unit and feature tests only, no browser layer | Disproven inside this project, with a number attached: 7 of 8 green while the dashboard 500d. |
| Laravel Dusk instead of Playwright | Dusk is Laravel-native and would avoid a Node dependency, but it is ChromeDriver-bound, flakier in CI, and has materially weaker tracing and parallelism. The trace-on-failure story matters more here than staying in one language. |
| Browser tests for everything | Inverts the pyramid. Slow, flaky, and it would make the suite something contributors avoid running. The browser layer stays small on purpose. |
| Test against SQLite only | ADR-006's entire carried risk is driver divergence. Testing one driver tests the assumption away. |
| Defer the testing strategy until there is code | The discipline is free while the codebase is empty and unaffordable at month eighteen — the same argument as ADR-018's no-bare-strings rule and ADR-020's `pii_class`. |

**Cost to pillar three, stated.** A contributor now needs Docker to run the *full* matrix, and Node to run the browser layer. That is a real barrier to the weekend contributor Standing Principle #6 exists to protect. **Mitigation is binding: the Pest layer must run green on a bare `git clone` with SQLite and no Docker, no Node, and no services.** The matrix and the browser layer are CI's job. If running the basic suite ever requires Docker, this ADR has been violated.

**Consequence.** CI runs a matrix of PHP 8.4/8.5 × Postgres/MySQL/SQLite, plus one browser job. That is slower and more expensive than a single job, and it is the price of the storage design.

---

## ADR-025 — Laravel Boost: a development dependency, and Kitsune ships guidelines rather than the runtime

**Status:** Decided · 2026-09-07
**Verified 2026-09-07:** `laravel/boost` v2.7.1, MIT, keywords `dev, laravel, ai`.

Three separable questions, answered differently:

1. **Boost as `require-dev` in the Kitsune repository** — **yes.**
2. **Boost as a runtime dependency of `kitsune/core`, shipped to every operator** — **no.**
3. **Kitsune authoring and shipping guidelines in Boost's format, for plugin authors** — **yes, and this is the part that matters.**

### What Boost actually is

`boost:install` writes a project-scoped `.mcp.json`, a 167-line `AGENTS.md`/`CLAUDE.md` guidelines file, and a set of skills. `boost:mcp` starts an MCP server that introspects the application — including database query and Tinker execution.

That is a development tool, and its own package keywords say so.

### Why it does not belong in the runtime

An MCP server exposing database queries and Tinker inside every operator's install is a standing data-access and remote-execution surface, in a product whose operators are **GDPR data controllers by construction** (ADR-020). It also collides with the GOVERNANCE commitment to no telemetry without explicit opt-in, and it is simply the wrong layer: a tool for people who *write* Laravel does not belong in the dependency tree of people who *run* a CMS.

### Why the guidelines are the valuable half

The extension API's central risk (Standing Principles #1 and #2) is third parties writing plugins that violate invariants they never read. The invariants most likely to be violated are exactly the ones an AI coding agent will get wrong by default and get right if told: the fail-closed scope attribute, `scopedUnique()` over Laravel's `unique`, never writing raw SQL in a field type, the reserved type handles, `{type}` as untrusted input.

Shipping a `kitsune/plugin-guidelines` package in Boost's format means an AI-assisted plugin author inherits the tenancy rules for free, before the validation CLI ever runs. **The v1.2 validation CLI fails the build when a plugin gets this wrong; guidelines stop it being written wrong.** Both, not either.

| Rejected | Why it lost |
|---|---|
| Boost as a runtime dependency of core | Ships a database-query and Tinker surface into installs holding personal data, against ADR-020 and the no-telemetry commitment. Wrong layer besides. |
| No Boost at all | Passes up a real contributor productivity gain at zero cost. It is MIT, dev-only, and removable. |
| Invent a Kitsune-specific guidelines format | Boost's format is already the Laravel ecosystem's de-facto standard; adopting it costs contributors nothing new to learn, and a bespoke format would have to earn that difference. |
| Guidelines for core contributors only, not plugin authors | Inverts where the risk is. Core has CI, review and hostile tests; the plugin ecosystem has none of that, which is exactly why Filament's own plugin ecosystem is mostly not tenancy-aware (ADR-008). |
| Wait until v1.2 to think about guidelines | The guidelines encode invariants that are being decided now. Writing them alongside the invariants is cheap; reconstructing them later from the code is not. |

**Cost, stated.** Boost is young and moving fast — v1.0 to v2.7 inside a year. A dev dependency on a fast-moving package means occasional churn. Acceptable because it is dev-only: a Boost break never reaches an operator, and the package can be dropped without touching product code.

**⚠️ Commercial interest, disclosed per ADR-023.** Guidelines that make third-party plugins safer benefit the hosted service disproportionately, because KaaS runs other people's plugins on shared infrastructure and already plans per-org plugin allowlisting. Self-hosters running only their own code get less from this than the hosted platform does. It is still the right call for the open project — a safer plugin ecosystem is a public good — but the asymmetry is real and is named here rather than left to be discovered.

---

## ADR-026 — Self-hosting: one command, no database server, no phone-home

**Status:** Decided · 2026-09-07 · ⚠️ **Amended by ADR-027** — the recommended default flips from Docker to the native path. Both remain supported; the original reasoning below is left as written

A person with a fresh Ubuntu LTS box must be able to paste **one command** and arrive at the onboarding screen. No database server to provision, no credentials to invent, no PHP version to negotiate.

This is pillar three made concrete. The stated early audience is *"people who currently install CMS plugins by uploading a zip"*, and the third pillar's documented failure mode is a fork over **"expensive upkeep."** An install path that demands Composer, a web server, a database and PHP version management is that upkeep tax, charged before the first page load.

**SQLite is what makes it possible.** Because SQLite is already a supported engine, the default install needs no database server, no user, no password and no tuning — which removes the single largest source of failed CMS installs.

### Two supported paths, because the PHP floor forces it

ADR-013 pins `^8.4`. **Ubuntu 24.04 LTS — in support until 2029 — ships PHP 8.3**, and every still-supported LTS predating the floor has the same problem. The installer therefore cannot assume a usable system PHP on a box it is entitled to run on, and there are exactly two honest ways out:

- **Native path — the recommended default** *(revised by ADR-027; this ADR originally recommended Docker)*. PHP-FPM and SQLite at the resource floor, with the `ondrej/php` PPA supplying the `^8.4` requirement. Leanest possible first contact
- **Docker path — fully supported, equal in quality.** Reproducible, immune to the distro's PHP version, and it reuses the image already built for ADR-024's CI matrix. Recommended for scale, for reproducibility, or for anyone who prefers a container to a PPA
Supporting only one of the two costs a real constituency, so both ship.

### Security posture, decided deliberately rather than by default

`curl | bash` is the format users expect, and refusing it outright costs adoption that this project cannot afford to lose. But Kitsune's own stated highest-severity category is data isolation, and normalising "pipe an unverified URL into a shell" sits badly with that. The resolution:

- The script is served over HTTPS from the project domain — `kitsunecms.org` — **versioned and checksum-pinned**, never from a redirect, never from a URL shortener. ⚠️ That rules out serving it from a domain that may later redirect, which is why the installer URL waited for a domain the project actually owns
- **The documented primary instruction is the two-step**: download, inspect, run. The one-liner is offered alongside it, not instead of it
- Releases are signed, and the installer verifies what it fetches
- **The installer never creates a default administrator account.** Onboarding creates the first user interactively. Default credentials at install time are the most reliably exploited mistake in CMS history and there is no version of it that is acceptable
- **It reports nothing, ever** — not usage, not versions, not an "install succeeded" ping. GOVERNANCE commits to no telemetry without opt-in, and an installer is the easiest place to break that promise quietly

### Idempotent by construction

Re-running the installer must upgrade rather than clobber, and must detect an existing install and say so. This is the same property `migration_map` gives imports in ADR-007, for the same reason: the operation people actually perform is *run it again*, usually after something went wrong.

| Rejected | Why it lost |
|---|---|
| Docker-only | Excludes anyone without root, without Docker, or on constrained VPS hosting — a meaningful slice of exactly the audience pillar three exists for. |
| Native-only, no container path | Reproducibility across distros becomes a permanent support burden, and the PHP-floor problem gets worse with every LTS that ships behind `^8.4`. |
| No installer — "install it with Composer" | This is the upkeep tax that forked Backdrop, charged up front. The early audience uploads zip files. |
| `curl \| bash` with no version pin or checksum | Normalises the worst supply-chain pattern in the ecosystem, in a product whose severity ceiling is a cross-org data leak. |
| Installer creates a default admin account to reach onboarding faster | Trades the project's worst-case security incident for a few seconds of convenience. |
| Point self-hosters at the hosted service instead | Contradicts the premise, and contradicts the GOVERNANCE commitment that the export path and the self-host path are never degraded. |

**Cost, stated.** An installer is a permanent support surface that grows with every distro release, and it will generate support load disproportionate to its size — the ~3x multiplier in ADR-011 applies to it directly. Budget for it as an ongoing obligation, not a Phase 6 task that closes.

**⚠️ Commercial interest, disclosed per ADR-023.** **This decision runs against the hosted service's commercial interest.** A genuinely frictionless self-host path is precisely what makes KaaS optional for the customers most likely to pay for it. It is being made anyway, because pillar three is not conditional on the business model — and naming the tension is the whole point of ADR-023's commitment. If a future decision quietly degrades the installer, this paragraph is the thing to hold it against.

---

## ADR-027 — The resource floor is a designed constraint, with a number

**Status:** Decided · 2026-09-07
**Amends** ADR-026 — the recommended self-host default flips from Docker to the native path.

**Kitsune must run well on hardware people already have.** The infrastructure bar is a product decision, not an emergent property of whatever the code ends up needing, and it is set deliberately:

> **Reference floor: 1 vCPU, 1 GB RAM, SQLite, no container runtime, no external services.**

That is a ~$5/month VPS, or entry-level shared hosting. It is the configuration a one-person site actually runs on, and it is the configuration the project is measured against.

**This is measured, not asserted.** Standing Principle #9 applies to the project's own claims as much as to framework internals: the floor goes into the Phase 1 benchmark alongside the storage numbers, and every phase's *done when* includes still meeting it. A floor nobody measures is a floor that quietly rises.

### Why this needs stating rather than being left implicit

It is already the reason behind several decisions — SQLite support, the PHP 8.4 floor chosen partly because *"shared hosting lags"*, Blade + Livewire over a bundler, an installer that needs no database server. But **an unstated principle cannot be violated, only forgotten.** Each individual decision to require a little more is defensible on its own; the sum of them is Drupal 8, which raised the contributor floor and the hosting floor together and got forked over *"expensive upkeep."*

Requirements creep the way the third pillar erodes: by a thousand small choices that each make sense for the larger customer.

### What it forbids

Core may not require, for a default single-site install: a container runtime, a separate database server, Redis or Memcached, Elasticsearch or any search daemon, Node at runtime, or a always-on worker process. Anything in that list may be **supported and recommended at scale** — none of it may be **required to get to the onboarding screen or to run a small site**.

Full-text search must therefore work on native Postgres/MySQL/SQLite facilities at the floor. Background work must degrade to synchronous or cron-driven execution when no worker is running.

### What it costs, stated honestly

This is not free, and pretending otherwise would make it a slogan:

- **It constrains the schema engine.** No assuming a cache server for resolved settings (ADR-022), no assuming a search daemon for global search, no assuming a queue for blueprint application
- **It taxes the write-amplifying decisions.** ADR-017 fans shared fields out to every locale row on write, ADR-016 writes an `entries` row per media asset, and ADR-006 pays write throughput per generated column. Each is correct for read performance and each costs more on one vCPU. **The floor must be benchmarked with those behaviours switched on, not on an idealised empty table**
- **It sharpens the unresolved pillar-three tension.** The decision log already records "accessible to small vs. accessible to large" as *partially unresolved*. This ADR resolves the *floor* half and deliberately leaves the ceiling to configuration
- **It will occasionally lose an argument it should win.** Some future feature will be genuinely better with Redis. The answer is that it may use Redis when present and must work without it

| Rejected | Why it lost |
|---|---|
| Leave the floor implicit, as a value rather than a rule | Values do not fail builds. Every requirement added is locally defensible; only a stated floor makes the *sum* reviewable. |
| Set the floor by what the code happens to need | That is not a floor, it is a readout. It rises monotonically and nobody is ever responsible for it rising. |
| A lower floor — 512 MB, no swap | Attractive, but Livewire renders are PHP-heavy and Filament's admin is not a static page. Setting a floor the project cannot actually hold would be worse than setting an honest one. Revisit with Phase 1 numbers rather than guessing now. |
| A higher floor — assume Docker and 2 GB | Excludes shared hosting and the cheapest VPS tier, which is precisely the constituency pillar three exists for, and precisely the constituency Backdrop forked to serve. |
| Docker as the recommended self-host default (ADR-026 as originally written) | Reproducible, but a container runtime is the single largest fixed cost available to add, and recommending it by default contradicts this floor on the very page where most operators meet the project. See below. |

### Consequence — ADR-026's recommended default flips

ADR-026 offered two install paths and named **Docker** the recommended default, on reproducibility grounds. Under this ADR that is the wrong default: it recommends the heaviest option at the moment of first contact.

**The native path becomes the documented default** — PHP-FPM, SQLite, no container runtime, at the floor. **Docker remains fully supported and equal in quality**, recommended for people who want reproducibility, who are running at scale, or whose distro PHP sits below the `^8.4` floor and who prefer a container to a PPA. Both paths stay first-class; only the recommendation changes.

**Commercial interest, per ADR-023.** Interests mostly align here — a leaner core means more tenants per box on KaaS. The exception is the same one ADR-026 already discloses: the lower the self-host floor, the more optional the hosted service becomes. Disclosed once there; not re-litigated here.

## ADR-028 — A generated column is named for its projection, not for its owner

**Status:** Decided · 2026-09-07

Found while implementing ADR-006's index-on-demand mechanism, before it shipped.

`entries` is **one table shared by every org** (ADR-021), and `field_storage` is `UNIQUE (org_id, handle)` — so two orgs may each define a field called `price`. The first implementation named the generated column after the handle alone, `idx_price`. That is wrong in two ways, and both are silent:

- **Type collision.** Org A's `price` is a `number`, Org B's is `text`. One column gets created, with one type. The other org's queries then filter on a projection that casts their data to the wrong type — wrong results, no error.
- **Drop collision.** Org A un-indexes `price`, the column is dropped, and Org B's queries start failing on a column that another org removed. Cross-org action at a distance, which is precisely the class of defect ADR-021 says has no framework safety net.

**Decision: the column's identity is `(handle, field type)`, rendered `idx_{handle}__{type}`.**

`FieldType::generatedColumnType()` takes only a driver — it reads no per-field configuration. The projection is therefore a pure function of the type handle. Two field storage rows with the same handle and the same type generate a byte-identical expression, so sharing one column is not coupling, it is deduplication. Two rows that disagree on type generate different expressions and get different columns.

This follows from that:

- **Creation is idempotent and shared.** The second org to index `price` as a `number` finds the column already there and adds nothing.
- **Dropping is reference-counted.** `idx_price__number` survives until no field storage row still asks for it. An org un-indexing its own field never removes another org's index.
- **No coordination, and no leak.** Neither org can block the other, and neither learns the other exists — the failure mode a "handles are globally reserved by first use" rule would have introduced.

**Identifier length.** PostgreSQL truncates identifiers at 63 bytes and MySQL rejects them past 64, and a truncated column name is a silent collision — exactly what this ADR exists to prevent. Field handles are therefore bounded at 40 characters and constrained to `[a-z][a-z0-9_]*` with no doubled underscore, which keeps `__` unambiguous as the separator and leaves room for the type. The full identifier is re-checked at index time and refused if it still does not fit.

**The cap counts columns, not rows.** The first implementation capped "indexed fields per entity type" and then counted `field_storage` rows globally — the name and the code disagreed, and neither described the resource being protected. The scarce resource is columns on the shared `entries` table: PostgreSQL stops at 1600, MySQL at a 65,535-byte row. The cap is therefore on generated columns present on `entries`, counted from the table itself.

| Rejected | Why it lost |
|---|---|
| `idx_{org_id}_{handle}` — a column per org | Correct isolation, unbounded cost. 500 orgs × 5 indexed fields is 2,500 columns on one table; PostgreSQL's limit is 1,600. It converts a correctness bug into a capacity ceiling. |
| A handle is globally reserved by whoever indexes it first | Simple, and it leaks. Org B learns that `price` exists elsewhere as a `number`, and cannot proceed without coordinating with a tenant it must not be able to see. |
| Hash the identity — `idx_a3f9c2d1` | Collision-free and unreadable. The column is a query surface; `where('idx_price__number', ...)` is debuggable and a hash is not. |
| A table per org | Solves this and reintroduces Drupal's problem one level up: schema operations become O(orgs), and ADR-021's single-database model exists to avoid exactly that. |
| Leave it — one org per install is the common case | The common case is not the risky case. Shared hosting is the deployment KaaS depends on (ADR-023), and a silent cross-org data defect there is unrecoverable reputationally. |

**Consequence.** Column names are longer and carry a type suffix. Any query written against a generated column must resolve the name through the field storage row rather than assuming `idx_{handle}`.

### Amendment, same day — naming was only half of it

**Status:** Amended · 2026-09-07 · found in review of the first implementation

Naming the column for its projection stopped orgs from *colliding on the name*. It did nothing about the expression, and the expression is the larger problem: **a generated column reads its JSON key from every row in the shared table**, including rows belonging to orgs that gave that handle a different type. Measured against live engines:

| | unguarded `(values->>'price')::NUMERIC` where another org's `price` is `"contact us"` |
|---|---|
| PostgreSQL 17 | `ERROR: invalid input syntax for type numeric` — **the column cannot be created at all** |
| MySQL 8.4 | `ERROR 1366: Incorrect DECIMAL value` — same |
| SQLite | **indexes it as `0`**, silently. A price of zero, matching queries for one |

So the expression is now **total**: it tests the JSON type first and yields NULL for anything else. The wrong type is not this projection's data, and NULL is the honest answer for it.

Three further defects surfaced while fixing that, all of the same shape — a claim the tests did not check:

- **`integer` was unindexable on MySQL.** The driver returned one string for both the column type and the cast, and MySQL needs two: `BIGINT` in `ADD COLUMN`, `SIGNED` inside `CAST`, each rejected where the other belongs. The driver interface now exposes `columnType()` and keeps the cast spelling private.
- **`boolean` was unindexable on MySQL.** `->>` renders a JSON boolean as the text `'true'`, and `CAST('true' AS UNSIGNED)` is an error. Booleans compare the JSON value directly instead.
- **`date` and `datetime` were unindexable on PostgreSQL.** A stored generated column's expression must be IMMUTABLE, and a text-to-`DATE` cast is only STABLE: `ERROR: generation expression is not immutable`. There is no immutable text-to-date path — `to_date` is STABLE too. **Dates therefore project to fixed-width ISO-8601 strings on every engine**, which `toStorage()` already normalises to, so string ordering is exact chronological ordering. Uniform across engines rather than a real `DATE` on the two that would accept one, because one behaviour is worth more than three bytes.

**Why all four hid:** the parity suite exercised `decimal` and `string` only. It now covers every logical type on every engine, and the registry test renders every indexable type against all three drivers rather than only the connected one. `LogicalType` is an enum so PHPStan fails an unhandled match — a new logical type cannot silently leave one engine behind.

**Consequence for field types.** `generatedColumnType(SchemaDriver)` is replaced by `projection(FieldConfig): ?Projection`. A field type now describes what it projects to and takes no driver at all — handing it one was the wrong seam, since it still had to know that a rendered type serves two grammars, and it left the driver no place to put the guard.

### Second amendment — the identity is the projection, and the projection depends on configuration

**Status:** Amended · 2026-09-07 · found in re-review

Naming the column `idx_{handle}__{type}` assumed the projection was a pure function of the field type handle. It is not:

- A `number` with `format: integer` must project to BIGINT. Through `DECIMAL(12,2)` — which is what every `number` used — the value `10000000000` is accepted by the validator and by `toStorage()`, and PostgreSQL then **refuses the column with `numeric field overflow`.**
- A `text` with `maxLength: 400` must project at that width. Through `VARCHAR(255)` it is **silently truncated in the index**, so two distinct values compare equal and an exact filter returns wrong rows — and SQLite, which does not enforce declared widths, disagrees with the other two engines about which rows those are.

So two orgs configuring the same handle differently would have collided on `idx_count__number` with incompatible column types — the very defect this ADR was written to close, reintroduced one level down.

**The column is therefore named for the projection's signature**, which carries the width where the width varies: `idx_count__integer`, `idx_price__decimal12_2`, `idx_sku__string64`, `idx_active__boolean`, `idx_when__date`. Rows that project identically still share a column; rows that differ in any way that changes the SQL do not.

**Indexing constrains configuration, and says so.** An indexed string wider than 700 characters is refused with the reason: MySQL caps an index key at 3,072 bytes and utf8mb4 costs four bytes a character, so `VARCHAR(1000)` fails with `ERROR 1071` while `VARCHAR(700)` succeeds — measured, not derived. Long text that needs searching wants full-text search, not a scalar projection.

`field_storage.handle` drops from 40 characters to **32**, because the signature suffix is longer than a type handle was and the assembled index name has to stay inside PostgreSQL's 63 bytes.

Two consequences followed and are worth recording, because both were wrong in the first pass:

- **Reference counting compares the COLUMN, not the field type.** `text` with `maxLength: 64` and `select` both project to `string64` and share a column deliberately, while two `number` rows configured `integer` and `decimal` do not. A `type` predicate got both directions wrong — dropping a column another org still queried, and leaving an orphan behind.
- **Settings that change the projection are shape, and lock with it.** ADR-006 locks storage once data exists, and the lock checked `type` and `cardinality` only — so switching `format` from decimal to integer on a table full of fractions was permitted, changing both the conversion and the column. The guard compares projections rather than naming settings, so a field type adding a projection-affecting setting is covered without anyone remembering it exists.

---

## Standing principles

From prior-art analysis of Drupal, October, Winter, Statamic, Directus, Strapi, Payload, Backdrop and ClassicPress.

1. **Never do the big rewrite that breaks the extension ecosystem.** Most damaging failure mode observed, by an order of magnitude. Drupal 8 cost the project its momentum by Dries's own account; usage went from ~1M sites to 474,292 reporting installs as of Aug 2026. Strapi v3→v4 forced `patch-package` workarounds and silently skipped rows during migration.
2. **Freeze a deliberately narrow extension API — but not until v1.2.** Then deprecate, never remove within a major. *"Drupal 9.0 should be almost identical to the last Drupal 8 release, minus the deprecated code."*
3. **Publish the governance model before there is anything to govern.** Winter forked October over *process*, five weeks before the license change. Licensing gets blamed; governance is usually the trigger.
4. **Decide the license once and never move it.** Every project that moved twice paid more for the second move.
5. **Don't build a marketplace.** Winter CMS, with a team, still has none 5.5 years after forking — its marketplace page directs users to *October's*.
6. **Don't raise the contributor floor.** Drupal 8's OOP/Symfony shift eliminated the weekend hacker, and weekend hackers produced the contrib long tail.
7. **A fork preserves code and loses the ecosystem.** Backdrop: 4,305 installs after 13 years vs Drupal's 474,292. ClassicPress: <0.1% share, 9 committers, under $2,000/year. This cuts both ways — it's also why *being forked* is survivable, and why trademark matters more than license terms.
8. **Always ship a data export path.** Never trap users. Ethical requirement, not a feature.
9. **Measure, don't reason, about framework internals.** ADR-012 was settled by an instrumented spike that disproved the design reasoning had produced. Two hours of measurement beat six months of assumption.
10. **Keep the resource floor low, deliberately.** WordPress's reach is inseparable from running on the cheapest hosting available; Drupal 8 raised the contributor floor and the hosting floor together and was forked over *"expensive upkeep."* Requirements creep the way the third pillar erodes — one locally defensible addition at a time. The floor is a number, it is measured every phase, and it is in ADR-027.

---

## ADR-029 — Core describes a control; the panel builds it

**Status:** Decided · 2026-09-09

Found while starting the per-field admin UI (issue #39), and recorded because the rule was already being enforced across eight docblocks without ever having been decided.

`SettingsSchemaRenderer` turns a field type's `settingsSchema()` **data** into Filament components, and its docblock explains why the method returns an array:

> ADR-002 keeps core headless-capable: a field type that built `TextInput::make(...)` would make `kitsune/core` depend on a panel, and the API and the CLI would have nothing to render.

**Both halves of that justification are wrong, and the rule is still right.**

- **The dependency claim is factually stale.** `packages/core/composer.json` hard-requires `filament/filament ^5.4`, which is ADR-008 (*"Kitsune's admin is a Filament v5 panel … Pin `^5.4`"*). Core already depends on a panel. Nothing a field type returns can change that.
- **ADR-002 does not contain the rule.** It is five lines — a title, a status marked deferred to v1.1, and a two-row rejected table — about *delivery*: ship a headless surface **and** a Blade theme layer rather than only one. It says nothing about what a core class may return. Eight places cited it for a constraint it never stated.
- **The second clause is not stale but is currently vacuous.** There is no CLI consumer of `settingsSchema()` and no API consumer: `Console` holds three benchmark and schema-sync commands, `Http` holds four middleware, there are no controllers and no resources, and ADR-011 defers the REST API to v1.1. "The API and the CLI would have nothing to render" describes no consumer that exists.

### Decision

**A field type describes a control. The panel builds it.** The description is a value object over a closed vocabulary; construction of Filament objects happens only in `Kitsune\Core\Filament`.

This is ADR-028's ruling generalised. That amendment replaced `generatedColumnType(SchemaDriver)` with `projection(FieldConfig): ?Projection` and gave the reason in one line: **"handing it one was the wrong seam."** A driver is a rendering concern for storage; a Filament component is a rendering concern for the UI. Same seam, same answer, and `Projection` is the precedent for the shape — a description, not the rendered thing.

**The load-bearing reason is exhaustiveness, not portability.** A closed vocabulary gives the renderer a single place through which every control passes, and that is the only structure in which a cross-cutting presentation concern can be made unforgettable rather than merely documented. `dir="auto"` is the case in hand: issue #39 has already shipped that attribute three times and been short of complete twice, both times because the reach of a correct rule depended on somebody enumerating call sites. A field type that returned a finished `TextInput` would put the decision back in twelve places, and a thirteenth type could omit it silently.

So the test of this ADR is not "can a non-panel consumer render it" — there is no such consumer yet. It is: **can a new field type be added without text direction, and is that expressible at all?** Under this seam the answer is no, because direction is derived by the renderer from the control's kind and is not a property a field type can decline to set.

### What this ADR does not claim

- It does not forbid `Kitsune\Core\Filament` from constructing Filament objects. That namespace exists to do exactly that, and it is inside core.
- It does not rest on a future REST API. If the API arrives and can consume these descriptions, that is a benefit rather than the justification.
- It does not make the description a public contract. It may change with the panel it serves until the API freeze (ADR-011, v1.2).

### Consequence

`docs/field-types.md` §3 declared `formComponent(FieldConfig): Component` and `tableColumn(FieldConfig): Column`, returning Filament objects from core. That is the rejected side of this decision, and it also described an interface that does not exist — those methods were never on `FieldType`, along with `generatedColumnType()`, which this log had already replaced. The document is corrected, and a test now reflects over the interface and fails when the document drifts from it: this ADR is a decision about a seam, and a decision recorded only in prose drifts from the code it governs. Eight docblock citations of ADR-002 are repointed here.

| Rejected | Why it lost |
|---|---|
| `formComponent(): Component` on the field type, as the doc described | Puts a cross-cutting presentation decision in twelve places and makes a thirteenth able to omit it silently — the exact reach failure #39 shipped twice. Core already depends on Filament, so the *dependency* objection is void; the *seam* objection is what holds. |
| Keep the rule, keep citing ADR-002 | Invariant 12: amend the decision rather than route around it. Eight docblocks asserting a rule no ADR contains is the same drift as a stale comment, at scale. |
| Drop the rule, return components, delete the renderer | Cheapest to write and the panel converged against it: it is the one option where a field type can be added with no direction support at all. |

---

## Open questions

- Storage benchmark at 10k / 100k / 1M entries
- Blueprint rollback semantics when content already exists
- Revision storage growth — full-JSON snapshots get expensive; consider diffs
- Do relations target the translation group or a specific locale row (ADR-017)? Group-targeting with an optional locale override is the leading candidate
- **Name/trademark clearance** — no PHP/CMS collision, but Mozilla's support platform and a Rust ActivityPub project both use "Kitsune." Confirm availability in software/SaaS classes **before** spending on a logo.

  **Domain settled provisionally, 2026-09-07: `kitsunecms.org`.** `kitsune.org` is held by another party and is being pursued; acquiring it would make it a redirect, not a rename. Naming the domain now unblocks ADR-026's installer, which cannot be served from a URL that might later move — a checksum-pinned script behind a redirect is exactly what that ADR refuses.
- KaaS deployment topology beneath the org- and site-aware core — now an ops decision, not architecture, though ADR-020 gives it a legal input via data residency

**Unverifiable, do not cite:** the free/paid split of Filament's plugin directory. Filters exist; counts are not published.
