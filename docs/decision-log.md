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

**Status:** Decided · 2026-09-07 · **Amended 2026-09-15** — CI was never primary on 8.5; see the note after the decision

**Decision:** `"php": "^8.4"`. Develop and run CI primarily on 8.5, with 8.4 in the matrix. Revisit the floor at v1.0.

> **Amended 2026-09-15: CI was never primary on 8.5, and it had never started the application on 8.5 at all.** The PHP suites ran on 8.4 and 8.5, but through Testbench. The lint job and the Playwright job — the only job that boots the skeleton, the Filament panel and Livewire — were pinned to 8.4. The browser job now runs on both versions. **The "primarily on 8.5" clause is withdrawn**, for development and CI alike: the test jobs run on 8.4 and 8.5 as equals, lint runs at the floor, and the floor is unchanged. Separately, the alpha targets **8.5**. Ubuntu 26.04 LTS ships PHP 8.5 and no 8.4, so 8.4 there would have to come from a third-party repository — the Launchpad PPA `ppa:ondrej/php` has no 26.04 suite, and packages.sury.org does (ADR-026 amendment). The alpha's stage server runs 8.5 from Ubuntu's own archive instead, and its Forge server is planned on the same release.

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

> ⚠️ **The actor is asked of the PANEL's guard, not of the application default** — review found `Auditor` using bare `auth()->id()`. Filament has `Panel::authGuard()` precisely so a host can authenticate its admin through a guard of its own, and with one configured this column recorded either NULL or whichever unrelated user happened to be signed in on the default guard at the same moment. "At whose hand" is a third of this primitive, so an actor resolved from somebody else's guard is the one kind of wrong it must not be. `Permissions::currentUser()` is the resolution, shared with the RBAC layer rather than copied, and it carries the binding check core's own test suite needs.
>
> ⚠️ **And an id is not an identity, which review found one round later.** `target_type` has been
> polymorphic since this primitive was written, and `actor_id` was a bare number — so in a host
> authenticating its panel through a provider backed by another user model, on another table with its own
> sequence, the column that answers *at whose hand* named whichever row the reader assumed. `audit_log`
> carries `actor_type` beside it now, written from `getMorphClass()` exactly as the target is, and the
> `(org_id, actor_type, actor_id)` index mirrors the target's. An `Authenticatable` that is not an Eloquent
> model records its class name instead: it still acted, and a class is a better answer than a number.
>
> ⚠️ **And the BINDING is not the request, which is the same column wrong a third way.** `app()->bound('filament')`
> is true application-wide the moment the package is installed, so on a non-panel route with its own guard —
> an API route, a custom web guard — the actor was resolved from the PANEL's guard, which has nobody, and a
> real person's action was recorded unattributed. `Filament::getCurrentPanel()` is set by Filament's own
> middleware, so it is non-null exactly when a panel is serving; outside one, Laravel's `auth()` names the
> guard the `auth` middleware actually authenticated with. The knock-on is why this is a P1 rather than a
> tidy-up: `Entry::refuseUnpermittedRepublication()` reads a null actor as "the system is acting" and stands
> aside, so a wrong answer here opened a guard three files away.
>
> ⚠️ **And the column assumed an integer, which is the fourth thing wrong with the same row and the worst
> failure of the four.** An identifier is the HOST's to choose: a UUID-keyed users table, or the LDAP and SSO
> identities this primitive had just learned to name, produced a string — and `actor_id` was an
> unsigned-bigint. On PostgreSQL and strict MySQL the audit INSERT then failed, and because the audit row
> shares a transaction with the write it records, **the content write rolled back with it**. "Cannot record
> who" became "cannot write at all" for every action by that person. Both identifier columns are strings now
> — `target_id` too, swept rather than waited for, because `role.assigned` names a host user on the other
> half of the same row. Neither column ever carried a foreign key (erasing a user must not destroy the
> trail), so the type was holding an assumption rather than a constraint.

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

**Status:** Decided · 2026-09-07 · **Amended 2026-09-09** — three times while public site resolution was built (issue #38); see the amendments below · **Amended 2026-09-19** — every guarded builder reads a written column the way the database does, through one comparison; see *a column is the one the database writes*
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

> ⚠️ **Amended 2026-09-09 — `base_url` gains a host-less form, and two derived columns.** Found while implementing public site resolution (issue #38).
>
> **The host-less form.** `https://example.com/fr` pins a path prefix to one host. `/fr` means the same prefix on **whatever host serves the installation**, which is what a single-domain multi-language install actually wants — and the only form that survives being served from a different address in development, where `APP_URL` is `http://localhost` and the browser suite answers on `127.0.0.1:8125`. A null `base_url` means the site has no public URL and is reachable only through the admin.
>
> **`canonical_host` and `path_prefix`** are derived from `base_url` on save, stored, and **unique together**. Three reasons, none solvable by parsing `base_url` per request:
>
> - **Resolution must be indexed.** Scanning every site in PHP makes every public request O(total sites) in time and memory, unbounded as an installation grows, against ADR-027's 1 vCPU / 1 GB floor.
> - **Two orgs must not claim one URL.** `base_url` accepts equivalent spellings — scheme, port, trailing slash, letter case, a trailing dot — so a uniqueness constraint on it directly would let two orgs each hold a distinct-looking value and both answer on one hostname, with row order deciding which. Canonicalising first makes the constraint mean something, and cross-org URL theft is the class ADR-021 says has no framework safety net.
> - **The winner must be deterministic.** Ranked by specificity — this host with the longest matching prefix, down to this host at its root, then any host with the longest prefix, down to any host at its root — not by whichever row the database returned, so deleting an unrelated site cannot silently change which org a URL serves.
>
> ⚠️ The unique index deliberately does **not** lead with `org_id`, which is the carve-out added to AGENTS.md invariant 4 — a global uniqueness claim rather than a lookup index, consumed by a bootstrap that runs before scope exists. Leading with the scope key would permit the very thing the constraint forbids. The migration states both conditions beside the index.
>
> ⚠️ **The index is the exact-match backstop, not the whole guarantee.** It cannot see OVERLAPPING claims — org A holding `https://example.test` and org B holding `https://example.test/news` both satisfy it, and longest-prefix resolution then serves org A's hostname from org B. Prefix containment is not an equality, so `Site::refuseOverlappingClaim()` enforces it on save, unscoped, because the question is whether ANOTHER org holds a conflicting claim. Found by review of the implementation.
>
> ⚠️ **And that check is a check-then-act, which took a table to close (issue #61).** It reads the rival claims for a host and compares prefixes in PHP, so two orgs creating `example.test/` and `example.test/news` *concurrently* could both pass it — the derived keys differ, the unique index accepted both, and the theft was recreated. `site_host_claims` holds one durable row per hostname whose only purpose is to be locked; `Site::save()` opens a transaction, upserts and locks that row, and the overlap check then runs against committed state.
>
> **Three things that fix could not be.** A `lockForUpdate()` in the `saving` hook is only meaningful if the *caller* wrapped the save in a transaction, and a hook cannot make that true — `Site::create()` outside one is the ordinary case, so it would have looked like serialisation while working sometimes. Locking rows on `sites` serialises only the case where a rival already exists, which is the sequential one already closed: when both claims are new there is nothing there to lock. And relying on MySQL's gap locks, which Postgres does not take, would make correctness engine-specific — the thing invariant 5 exists to prevent.
>
> ⚠️ **Where the wait happens is the engine's business, and the two disagree.** Postgres blocks on the `SELECT … FOR UPDATE`; MySQL and MariaDB block earlier, on the unique index during the upsert, and raise `DeadlockException` — which extends `PDOException` and **not** `QueryException`, so a test catching the narrower type passed on Postgres and failed on both MySQL engines. SQLite serialises writers at the database level and compiles `FOR UPDATE` to nothing, so there is no row lock to demonstrate there; the concurrency test runs on all four and skips those two assertions with that reason stated.
>
> ⚠️ **And holding the mutex is worthless if the read answers from an older point in time.** Under MySQL and MariaDB's REPEATABLE READ it can: if a caller wrapped the save in a transaction that had already read anything, the nested `DB::transaction()` is only a savepoint and the snapshot belongs to the *outer* transaction — so a rival committing while this save queued for the mutex is invisible, and `/` and `/news` coexist across orgs after all. The rival lookup is therefore a **locking read**, which forces a current read on both MySQL engines; the lock is incidental, since the mutex is what serialises, and the clause costs Postgres and SQLite nothing. Found by review, and it is the kind of engine-specific hole invariant 5 exists to prevent.
>
> ⚠️ **A save that MOVES a site locks both hosts, in hostname order, and the deadlock is measured.** Found by review. Locking only the destination let two same-org sites moving across each other's hosts deadlock even with disjoint prefixes: each took a mutex the other did not hold, then needed a site row the other did. Staged as two real sessions — site 1 `a.test/x → b.test/x` against site 2 `b.test/y → a.test/y` — **PostgreSQL 17 reports `deadlock detected` and MySQL 8.4 `ERROR 1213`**, and `DB::transaction()` takes one attempt, so an otherwise-valid save surfaced as an exception. Re-measured with both mutexes held in sorted order, both transactions commit and the swap completes.
>
> It is a proof rather than two passing runs: with both mutexes held, every site row a transaction touches sits at a host whose mutex it holds — its own row at its **original** host, and the rivals it locks at its **destination** host — so two transactions whose row sets intersect must intersect in mutexes, and mutex acquisition is globally ordered by hostname. The order therefore comes from the hostnames and not from origin-then-destination, which is precisely the per-transaction order that deadlocked. The old host is locked **even when the save removes the URL entirely**, where nothing needs checking but the row being updated still sits there.
>
> ⚠️ **Amended again — two limits of that ordering, both found by review, one fixed and one a stated contract.**
>
> **The origin must not come from the loaded instance.** The proof above rests on *"every site row this transaction touches sits at a host whose mutex it holds"*, and the row's host was read from the model's loaded original — so if another save moved the row afterwards, the mutex set is computed for a host the row has left. Two such saves take **disjoint** mutex sets, serialise against nothing, and their rival reads then acquire each other's rows. Staged as two real sessions — row 1 believed at `a.test` but actually at `b.test`, moving to `c.test`, against row 2 believed at `d.test` but actually at `c.test`, moving to `b.test` — **PostgreSQL 17 reports `deadlock detected`**. `Site::save()` now re-reads the committed host under the mutexes it took (a *locking* read, for the REPEATABLE READ reason above) and **refuses** when the row is not on one of them.
>
> Detected rather than repaired, and that is the honest fix: re-deriving the mutex set from the committed host needs that host read *before* the lock that makes it stable, so it can go stale again between the two — a loop with no guaranteed end. An instance whose row has moved is also a save about to overwrite a change it never saw, so a refusal answers the question the caller actually asked. The test is the invariant itself rather than "the host changed": a row moved to the host this save is moving it *to* is already under the right mutex, and policing lost updates in general is a separate decision about `Site` rather than a consequence of this proof.
>
> ⚠️ **And the first version of that check had a null-shaped hole, found by review.** It returned early when the *loaded* `canonical_host` was null, reasoning that an admin-only site claims no host and has no origin to be stale about — true of the instance and not of the row. Another transaction can give that row a host, and the stale save then locks only its destination while its row sits somewhere else: the same disjoint-mutex cycle, reached through the one path that skipped the check. The row is queried whatever the instance believes, and a **missing** row is distinguished from a **present** row whose host is null, because a value read cannot tell them apart and they need opposite handling.
>
> A row on no host is reached by nothing — `refuseOverlappingClaim()` finds rivals by `canonical_host` equality, which a NULL never satisfies, so the only save that ever locks such a row is a save of that row. Passing there is the invariant holding rather than an exemption from it, and it is what stops the check from becoming "an admin-only site cannot be given a URL".
>
> **Ordering across a caller's whole transaction needs a mechanism, and the contract that stood here instead was unsound.** Every mutex is held until the *outer* commit and the order several saves run in is the caller's, so this ADR said a caller batching site saves must order them **by the hostname each will claim**. Review disproved it with a counter-example rather than an argument: every save also locks its **origin**, so destination order is not an order over the union. Staged as two real sessions, both obeying that contract —
>
> ```
> TX1  a.test → d.test, then b.test → e.test     (d < e)
> TX2  b.test → c.test, then a.test → f.test     (c < f)
> ```
>
> — **PostgreSQL 17 reports `deadlock detected`**: TX1 holds `a` and wants `b` while TX2 holds `b` and wants `a`. There is no ordering of the *saves* that fixes it, because each save locks a non-contiguous pair.
>
> **This is a known limitation, deferred to v1.2, and it is the third answer to the same finding.** The first two are recorded because each was wrong in a way worth keeping. A *contract* was tried — "order your saves by the hostname each claims" — and review disproved it with the inversion above. A public `Site::saveAllInHostOrder()` was tried next, acquiring the union before any save; it works, and re-measurement confirmed both transactions commit. Review then held it to `CONTRIBUTING.md`, correctly: **new public API surface before v1.2 is on the won't-merge list**, and the argument that callers "have no door" is weak when there is no caller. Nothing in this repository batches site saves.
>
> So what stands is honest rather than complete: a caller who wraps several site **moves** in one transaction can deadlock, the failure is loud and retryable rather than silent, no in-tree code does it, and the mechanism lands with the v1.2 API. The counter-example is recorded here so nobody has to rediscover it.
>
> ⚠️ **And the rival lookup's currency is a PRECONDITION on PostgreSQL, not a property of `lockForUpdate()`.** Review found that the clause added for MySQL does not do the same work on Postgres: at REPEATABLE READ a row **inserted** after the transaction's snapshot is invisible, `FOR UPDATE` or not. Measured on PostgreSQL 17 — one session took a snapshot, a rival committed `x.test/`, and the locking read returned only the pre-snapshot `x.test/other`. The whole design rests on one requirement, *the rival lookup must see rivals that committed while this save queued*, and Postgres satisfies it at READ COMMITTED (its default, and Laravel's). That requirement is now **checked for each save**, because the effective level is a property of the *transaction* rather than of the connection — measured on one PostgreSQL connection: `read committed` outside a transaction, `repeatable read` inside a `REPEATABLE READ` one, `read committed` again after. A per-connection cache was tried and is rejected: it would skip the check for a later `REPEATABLE READ` transaction on a connection first seen at READ COMMITTED, and refuse every valid save on one first seen the other way round. A save under Postgres REPEATABLE READ is refused with the reason, rather than silently permitting the overlap the mutex exists to prevent. SERIALIZABLE is not refused: Postgres aborts a transaction whose read has been invalidated, which meets the requirement by failing loudly instead. MySQL and MariaDB run at REPEATABLE READ **by default** and are safe there, because a locking read is a current read — refusing every REPEATABLE READ connection fails 13 of their tests, which is why the check names the engine.
>
> This ADR previously said nested saves were safe, on the strength of a nested save re-locking nothing. That much is true and does not imply the rest.
>
> NULL in both columns keeps admin-only sites out of the unique index, because NULLs compare distinct on every engine. An empty string is a real value: `canonical_host = ''` is any host, `path_prefix = ''` is the site root.
>
> ⚠️ **Amended again 2026-09-09 — a bare `base_url` needs the strategy, and a prefix has a depth bound.** Both found by review of the implementation.
>
> **A bare value is ambiguous.** `x.test` and `fr` are the same shape, so `deriveUrlParts()` judged on the string alone had to guess, and guessed "prefix": a `domain` site written as a bare `x.test` was stored as host `''` with prefix `/x.test`, unreachable at `https://x.test/` and claiming `http://any-host/x.test` instead. It now takes `url_strategy` — required, not defaulted, because a default is how the same guess returns — and an explicit scheme still outranks the column. `url_strategy` is also defaulted **on the model**, because a column default applies at INSERT and the value is read while deriving, before the row exists.
>
> **A path prefix is bounded at `Site::MAX_PREFIX_SEGMENTS`.** Resolution turns a request path into candidate prefixes asked for in one query, so an unbounded depth would let a URL a stranger chooses decide how much work the database does. The derivation **refuses** a deeper prefix rather than storing one, so the bound can never be why a saved site is unreachable — which is exactly what the first resolver did, matching only the FIRST segment and leaving a `/news/fr` site configured, indexed and reachable by nothing.
>
> ⚠️ **The slug is not a public address.** The first implementation matched a `path` site against its admin `slug`, which exposed every site at `/{slug}` on every host while leaving a site with a real `base_url` unreachable at its own URL — and dropped `subdomain` into an unhandled branch so those sites resolved to nothing. Under `base_url` there is no third case: a subdomain is just a host, which is what "one mechanism, no special cases" above already promised.

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

### Amendment — a column is the one the database writes, in every guarded builder, 2026-09-19

**Status:** Amended

The kernel enforces isolation at the write, and at the builder rather than in a model event, because a mass update dispatches nothing. That enforcement compares the columns a write names with the columns it guards — and six builders made the comparison, each its own way. After #127, `ScopedBuilder` folded case and rooted a JSON path at its column. `GuardedRelationBuilder` (`entry_relations`) and `GuardedStorageBuilder` (`field_storage`) each kept a private copy of the older, exact rule, which also read `settings->format` as a column nobody guards; `GuardedRoleBuilder`, `AppendOnlyBuilder` and `AuditedBuilder`'s status and soft-delete checks compared exactly by rules of their own. SQLite, MySQL and MariaDB match column names without regard to ASCII case, so each of those guards had a second spelling that walked past it. Measured before the fix, on SQLite and — for the two sibling builders — on MySQL and MariaDB too:

- **A relation row moved across the org boundary.** `EntryRelation::query()->update(['ORG_ID' => $rival])` restamped it, `['Source_Entry_Id' => $theirs]` hung it off another org's entry, and `['FIELD_STORAGE_ID' => $single]` gave a full cardinality-one field a second target — ADR-020's two-subject disclosure, one shift key from the refusal.
- **A locked field changed shape.** `FieldStorage::query()->update(['IS_LOCKED' => false])` cleared the lock ADR-006 calls the record that data exists, `['HANDLE' => 'cost']` renamed a locked field, `['PII_CLASS' => 'bogus']` stored a classification ADR-020 does not have — and `['settings->format' => 'integer']` moved a locked field's projection **spelled correctly**, because the path was never rooted at its column.
- **A genuine save walked past the model's own hooks**, which read each attribute by its name. `$storage->update(['IS_LOCKED' => false])`, `$relation->update(['FIELD_STORAGE_ID' => …])` and `attach($id, ['FIELD_STORAGE_ID' => …])` each passed every check on the lowercase attribute — unchanged, or never set — while the engine wrote the other one.
- **And beyond the two siblings:** `Role::query()->update(['IS_OWNER' => true])` promoted every role it matched with no per-holder audit (ADR-033); `AuditLog::query()->insert(['ORG_ID' => $rival, …])` appended to another org's trail; `$entry->update(['STATUS' => 'published'])` published for somebody without `publish` (ADR-033); and `update(['DELETED_AT' => now()])` was audited as `entry.updated`.
- **#127's own fix was not closed either.** `ScopedBuilder` folded every written name into one map before comparing, so of `ORG_ID` and `org_id` it judged the last — and SQLite keeps the **first** of a duplicated column in an INSERT. From org A, `insertGetId(['ORG_ID' => $orgB, 'org_id' => $orgA, …])` planted a row in org B on the default engine. MySQL and MariaDB refuse a duplicated INSERT column themselves (error 1110), and every engine keeps the last in an UPDATE: luck on those, not a guard.

**One comparison now — the `ResolvesWrittenColumns` trait, `@internal` — and every one of those builders uses it**, with three rules:

1. **A written name is the column the database writes.** The JSON path comes off first, then the table qualifier by its last dot, then quoting, then ASCII case. That is exactly what the engines fold: an accented `org_íd`, a dotted `ORG_İD`, a fullwidth `ｏrg_id` and `org_id ` with a trailing space are unknown columns on all three, measured, so the fold refuses nothing any engine would store elsewhere.
2. **A write that stands behind its guards writes each guarded column under the name they read, or not at all** — a save through either sibling or through `GuardedRoleBuilder`, and the siblings' `insertGetId()`, which builds the model its guards read from the written names verbatim. `ScopedBuilder` already asked this of a save for `columnsRequiringModelSave()`.
3. **A write that names one column twice is refused**, on every write `ScopedBuilder` takes that carries values and in both siblings, because which value the database keeps depends on the engine and the statement. Several JSON paths into one column are partial writes rather than a duplicate, and stay allowed. `AppendOnlyBuilder` and `AuditedBuilder`'s status checks judge every spelling instead, and `AuditedBuilder`'s writes reach `ScopedBuilder`'s refusal as well.

**What it does not claim.** A builder guards the columns it names. A model hook that reads some *other* attribute by name is covered only where a builder refuses that column under another spelling — this amendment makes the comparisons the builders make agree with the database, not every attribute read in every model. And below Eloquent — `toBase()`, `DB::table()`, raw SQL — nothing at this layer can stand, as every guard in the kernel already states.

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

**Status:** Decided · 2026-09-07 · **Amended 2026-09-18** — the settings store landed with timezone as its first consumer; invalidation is automatic for every write through Eloquent, and the per-site cache is deferred · **2026-09-19** — the store holds to one database connection per process. See the amendment after the consequences

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

### Amendment — the settings store, 2026-09-18

**Status:** Amended

Phase 3's settings store, with `timezone` as the first setting production code reads. Before it, `SettingsResolver` resolved correctly and was bound nowhere, its defaults were supplied only by its own test, there was no audited way to write a setting (only a raw model write, which nothing validated or invalidated and which the skeleton's seeder used), and `forget()` — the invalidation the consequences above require — was called by nothing but that test.

**What is built.**

- **Bound, per request.** `SettingsResolver` is bound `scoped`, like `Context`, so a long-lived worker cannot carry one request's memo into the next job. Its defaults are `config('kitsune.settings')`, from `packages/core/config/kitsune.php`, merged *beneath* a host's own `config/kitsune.php` — shallowly, as Laravel's `mergeConfigFrom()` does, so a host that declares `settings` replaces the map rather than one key of it. The defaults are held to the same rules as a stored override when the resolver is built. With no site — an org-level page, a console command, a queued job — resolution returns the defaults, marked as defaults, rather than failing.
- **Written, and audited once.** `SettingsWriter::set()` overrides a key at an org, site group or site; `revert()` **removes** it, because absent means inherit and there is no unset sentinel. Each change records one audit row — `settings.set` or `settings.reverted`, the level as the target, and neither the key nor the value (ADR-020; a setting may one day be a secret). **A change, recorded from the write's effect:** the writer compares what the column will hold with what it holds, a map without regard to its keys' order, so a repeat the stored JSON cannot tell from the value already there — `1.0` against `1`, an object against a map, a map MySQL hands back in its own key order — records nothing; a save a listener cancels is refused rather than recorded; and a save a listener empties of the change records nothing. Each was measured recording a row for a change that did not happen. The row's `site_id` is the site the actor acted from, as for every `Auditor` record, so an org or site group change is filed under whichever site was in context. Measured first: an org, site group or site write — through the model or in bulk — recorded nothing, because `AuditedBuilder` is bound to `Entry` alone, so the writer's record is the only one and a direct model write is validated and invalidated but **not audited**, like every other column on those three tables. The writer merges into the row re-read under `lockForUpdate()` rather than into the caller's copy, refuses a level outside the current org — the audit row is filed under the current org — and refuses with no org context, since the change could not be recorded.
- **Validated at every door Eloquent has.** A `timezone` must be one of the identifiers PHP lists — `DateTimeZone::listIdentifiers()`, the canonical IANA names, and the list Laravel's `timezone` rule checks — so an offset (`+05:00`, which has no daylight-saving rules), an abbreviation (`EST`), a mis-cased name and the IANA database's own aliases (`Etc/UTC`, `GMT`, `US/Eastern`) are refused, and so is `null`. The check runs on `saving` for all three levels, `FieldStorage::guardShape()`'s pattern — and **again in `ScopedBuilder`, on the value it is handed to write**, because listeners run in registration order and a host's `saving` listener registered after the model boots runs after the first check and before the write: one that set a refused timezone was stored (Codex, #127). The listener stays, since it also checks the whole map on a save that does not write `settings`, which the builder never sees. `settings` is listed in `columnsRequiringModelSave()` on all three, so `ScopedBuilder` refuses the bulk update, the JSON-path update, the arithmetic extras, the hand-rolled insert and the quiet save that would skip the hook — under any spelling the database accepts. Review found two it did not: the builder compared column names exactly while SQLite, MySQL and MariaDB compare them without regard to case, so `update(['SETTINGS' => …])` stored an unchecked timezone (and, the same comparison guarding scope keys, `update(['ORG_ID' => $other])` moved a row into another org — an ADR-021 hole older than this amendment, closed at the same place); and it found the table qualifier by the last dot before removing the JSON path, so `settings->a.b` read as the column `b` — measured, allowed on MySQL and MariaDB, where SQLite and PostgreSQL happened to reject the SQL themselves. A model save that sets `Settings` beside `settings` is refused too, because the hook checks the one attribute and the engine writes the other. **The cost is real and belongs here:** `Org` and `SiteGroup` had no per-row column before, and as `RequiresModelSave` models a bulk `insert()` of either is now refused outright, as it already was for sites, entry types and fields. **Where it stops:** below Eloquent — `toBase()`, `DB::table()`, raw SQL — as every guard in `ScopedBuilder` states, and a **JSON-path** write inside `withoutScopeBecause()`, which stands the builder's per-row refusals down for every guarded column and leaves no whole map to judge until the database has merged the path in. A whole map written there is still checked: the escape hatch decides which path may write a column, not what the column may hold. That holds for every Eloquent write that can carry one — `update()`, the insert family, `insertGetId()`, the `$extra` of `increment()`, `decrement()`, `incrementEach()` and `decrementEach()`, and `upsert()`'s rows and explicit `$update` assignments — and the other builder writes are refused outright wherever they run (`updateOrInsert()`, `updateFrom()`, and the subquery inserts, whose values no model-layer guard can read). Codex found the arithmetic extras unchecked after the claim was first made, and `upsert()` was found by listing every write the builder takes; each has its own test. A value one of those writes, or one stored before the check existed, is not silently used: the whole map is checked on every save — read from the row when the instance was loaded without the column, since a `select()` that leaves `settings` out used to read as null and let a rename pass (Codex, #127) — so the row refuses every later save, a rename included, until the value is replaced or reverted, and `SiteTimezone::current()` refuses to format a date with it, naming the level that holds it. Before that it reached Carbon, which threw from every cell, when it was a string, and was read as UTC — hiding a valid value above it — when it was not.

**Invalidation is automatic for every write through Eloquent, and this is how.** The consequence above says a setting write invalidates "that scope and everything beneath it", and `forget()` now takes an org, a site group, a site or nothing, and drops exactly that: each memoised site records the org and site group its resolution read, so forgetting org A cannot drop a site of org B, and forgetting one brand keeps its sibling's sites. A write through Eloquent does not have to call it: `ScopedBuilder` does, after every update, delete, arithmetic write and upsert to an org, site group or site, events or not — every write that can change an existing row, which an audit of all of them for both properties (checks the value, drops the memo, or refuses outright) confirmed; `upsert()` was the one missing, found by Codex on #127. When the write is that model's own save — evented or quiet, which `isPerformingModelSave()` answers and no caller can arrange — it drops that level and the sites beneath it, so `$site->update(['settings' => …])` invalidates exactly as the writer does. Any other write — a bulk or relation update, every delete, a write inside `withoutScopeBecause()` — could have touched any row its predicate matched, and the builder cannot name them without reading them, so it drops the whole memo. Any change to the row counts, not only to `settings`: resolution also reads a site's `site_group_id` and the names the provenance label quotes. This first hung on the models' `saved` and `deleted` events, which fire for an evented save or delete of one instance and for nothing else, and review measured eight writes left stale: a bulk move to another group, a relation update detaching a site, a quiet move, a bulk and a quiet delete of a group (whose sites the database detaches), an org's bulk soft delete, a bulk rename and a bulk settings write inside the escape hatch. A `TransactionRolledBack` drops the whole memo too, as it already dropped the permission memo, so a value memoised from an uncommitted write does not outlive its rollback. Both drop what every resolver alive in the process holds and build none: they first asked the container's `resolved()`, which stays true after a queue worker's scope reset, so every later job's write built a resolver only to empty it — and failed if the defaults had become invalid since. **Where it stops:** below Eloquent, again — a caller writing through `DB::table()`, `toBase()` or raw SQL calls `forget()` itself. Two defects in the reads were fixed on the way, each measured on the old code: the resolver read `$site->org` and `$site->siteGroup`, relations that load once per instance, so a level written through a different instance stayed stale through the same `$site` even after the memo was dropped; and with no org context the `siteGroup` relation matched nothing under `OrgScope`, so the brand level silently vanished. It now reads the three rows, the group pinned to the site's own org and every one on the site's own connection — `withoutScopeBecause()` is a static call that makes a fresh model on the default connection, so a site loaded from another one had its rows re-read in the default database, where the same id can be another org's row (Codex, #127); the reads are built from the site's connection instead, as `Site::rivalClaimsOnThisConnection()` already is, and the saving check reads its own row from the instance for the same reason — and a saved site whose row has since been deleted contributes no overrides of its own, where it used to fall back to the caller's stale instance and go on applying them (Codex, #127); only a site never saved, which has no row to read, is resolved from the instance.

**The per-site cache is deferred — approved by Adam on 2026-09-18.** The consequences above say resolution "must be memoized per request and cached per site". The per-request memo is built; the cross-request cache is not. Resolution reads at most three rows, each by primary key, and a cache is exactly where this entry's own named failure lives — "I changed the setting and nothing happened". It is added when a measurement shows the three reads cost enough to be worth one, and when it is, its invalidation has to hang off the same writes the memo's does.

**The first consumer.** `SiteTimezone::current()` is the current site's resolved `timezone`, or the default with no site. The admin builds every column that lists an instant and every picker that takes one through `SiteTime`, which applies it per component — not through `FilamentTimezone::set()`, which is one per application and would move the dates in a host's other panels too — and `SiteTimeReachTest` fails when anything else in `packages/core/src` formats an instant's time, sets a timezone, or builds or aliases a date-time or time picker. It cannot tell an instant from a date, so an instant listed with `date()` — its UTC calendar day — passes it, as does one formatted by hand; review has to catch those. (The dashboard's `since()` column prints a duration, which is the same in every zone, and is left as it was.) A date is not an instant: `Control::Date` now lists in a `Cell::Date` of its own, formatted with no timezone, because `2026-09-18` read as UTC midnight is the seventeenth in New York. The picker round trip was measured through Filament's own schema, not assumed: 09:00 entered with the site in America/New_York is stored as `2026-09-18T13:00:00.000000+00:00` and shown as 09:00 again, and an entry opened and saved untouched keeps its instant. A field holding several instants did not: Filament's simple repeater hands each stored value to its item raw, skipping the inner picker's hydrating cast while its dehydrating one still runs, so every untouched save moved each instant by the site's offset — 13:00 UTC to 17:00, then 21:00, measured — and the item showed an ISO string a datetime input cannot display. `FieldValueRenderer` runs the hydrating half itself now.

**One connection per process — decided 2026-09-19.** Kitsune's tenancy models — orgs, site groups, sites and what hangs off them — live on one database connection in a process. A second handle to that same database is supported, and the host-claim concurrency tests open one; so is a connection carrying a table prefix, which is a host's single connection named differently. Models split across *databases* are not supported, and the settings store makes no promise there. Two review rounds on #127 showed why this needed deciding rather than patching: each fix Codex prompted on a non-default connection exposed the next layer — the reads, then the audit row, which `Auditor` writes on the default connection and so outside a scope connection's transaction, and next the resolver's memo, keyed by site id alone. The reads are built on the model's own connection all the same, as cheap defence already paid for; the audit row and the memo key are left as they are, because making every layer connection-aware is a design this project has not chosen, and a store that claimed it without doing it would be worse than one that states the boundary.

**Still open.** The provenance UI this entry makes a first-class requirement — every resolved setting showing its origin, with a one-click revert — is **not built**: the panel has no org, site group or site resources to put it on. `Resolved` carries the provenance and `SettingsWriter::revert()` is the revert, so what remains is the screen. The picker holds a wall-clock time and no offset, and two consequences are measured and undecided: in the hour a zone repeats, one wall-clock time names two instants, and the second is saved back as the first — an entry holding 06:30 UTC on 2026-11-01 in New York moves to 05:30 when saved, even untouched — and in the hour a zone skips, 02:30 is stored as 07:30 UTC and shown as 03:30, with no message. Whether to refuse such a time, disambiguate it, or show the offset is open; so is fixing the zone for the life of an open form, which today reads its untouched instants in whatever zone the site has when it is saved. ⚠️ **Both are unreachable today and must be fixed before they become reachable.** Every site resolves to the default, UTC, which repeats and skips no hour, and nothing in the admin calls `SettingsWriter` — only code can set another zone. The first admin screen that lets an operator choose a timezone is therefore the change that exposes an untouched save rewriting a stored instant, and it may not ship until that save stores what it read. `SiteTimezoneTest` pins the repeated-hour case as a known defect, so the fix fails that assertion on purpose. Whether an org or site group change should be filed under the level's `site_id` rather than the actor's is open, and the audit migration's comment on that column disagrees with what every `Auditor` record does. `locked_keys` is still this entry's open question; encrypted setting values wait on ADR-036's secrets; and entry type availability still resolves through `EntryTypeAvailability::enabledMapFor()` rather than through the settings resolver, so "the same mechanism" above describes the inheritance rule, not shared code.

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

**Status:** Decided · 2026-09-07 · **Amended 2026-09-14** — MariaDB joined the engine matrix; see the note after the consequence · **Amended 2026-09-15** — the browser job runs on both PHP versions; see the note after the MariaDB amendment

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

⚠️ **MariaDB joined the matrix after this was written, for the reason the matrix exists.** README, `architecture.md`
and the roadmap all documented MariaDB 10.6+ as supported, and `DriverFactory` routes it to `MySqlDriver` — but
nothing ran against it, so two MySQL-only constructs had shipped: the `->>` operator, which MariaDB does not have, and
`CAST(… AS JSON)`, which it rejects. CI now runs PHP 8.4/8.5 × SQLite, PostgreSQL, MySQL and MariaDB — eight engine
jobs, beside lint, two bare-clone jobs and the browser job. "Three engines" elsewhere in this log counts SQL
dialects, and stays true: MariaDB is the MySQL dialect through the same driver, with a job of its own because
documented support that is never exercised is a claim, not a feature.

> **Amended 2026-09-15:** the browser job now runs on PHP 8.4 and 8.5, because it is the only job that boots the skeleton (ADR-013 amendment) — two browser jobs, and thirteen jobs in all.

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

**Status:** Decided · 2026-09-07 · ⚠️ **Amended by ADR-027** — the recommended default flips from Docker to the native path. Both remain supported; the original reasoning below is left as written · **Amended 2026-09-15** — the Launchpad PPA it named has no Ubuntu 26.04 suite; see the note under *Two supported paths*

A person with a fresh Ubuntu LTS box must be able to paste **one command** and arrive at the onboarding screen. No database server to provision, no credentials to invent, no PHP version to negotiate.

This is pillar three made concrete. The stated early audience is *"people who currently install CMS plugins by uploading a zip"*, and the third pillar's documented failure mode is a fork over **"expensive upkeep."** An install path that demands Composer, a web server, a database and PHP version management is that upkeep tax, charged before the first page load.

**SQLite is what makes it possible.** Because SQLite is already a supported engine, the default install needs no database server, no user, no password and no tuning — which removes the single largest source of failed CMS installs.

### Two supported paths, because the PHP floor forces it

ADR-013 pins `^8.4`. **Ubuntu 24.04 LTS — in support until 2029 — ships PHP 8.3**, and every still-supported LTS predating the floor has the same problem. The installer therefore cannot assume a usable system PHP on a box it is entitled to run on, and there are exactly two honest ways out:

- **Native path — the recommended default** *(revised by ADR-027; this ADR originally recommended Docker)*. PHP-FPM and SQLite at the resource floor, with the `ondrej/php` PPA supplying the `^8.4` requirement. Leanest possible first contact
- **Docker path — fully supported, equal in quality.** Reproducible, immune to the distro's PHP version, and it reuses the image already built for ADR-024's CI matrix. Recommended for scale, for reproducibility, or for anyone who prefers a container to a PPA
Supporting only one of the two costs a real constituency, so both ship.

> **Amended 2026-09-15: the native path named a PPA that does not cover the current LTS.** The Launchpad PPA `ppa:ondrej/php` has no suite for Ubuntu 26.04 LTS — its `dists/resolute/Release` returns 404 — and describes itself as being merged into its maintainer's apt repository, [packages.sury.org/php](https://packages.sury.org/php/), which it names as the canonical source for 26.04. That repository publishes both 24.04 and 26.04 with PHP 8.4 and 8.5. 26.04 needs no third-party source at all: its own archive ships PHP 8.5, which satisfies `^8.4`, while 24.04 ships 8.3. The native path therefore uses the distribution's own PHP wherever it meets the floor, and packages.sury.org only where it does not. Found while provisioning the alpha's stage server, which runs PHP 8.5 from Ubuntu's archive.

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

**Status:** Decided · 2026-09-07 · **Amended 2026-09-15** — the admin fetched avatars from a third party; see the note under *What it forbids*
**Amends** ADR-026 — the recommended self-host default flips from Docker to the native path.

**Kitsune must run well on hardware people already have.** The infrastructure bar is a product decision, not an emergent property of whatever the code ends up needing, and it is set deliberately:

> **Reference floor: 1 vCPU, 1 GB RAM, SQLite, no container runtime, no external services.**

That is a ~$5/month VPS, or entry-level shared hosting. It is the configuration a one-person site actually runs on, and it is the configuration the project is measured against.

**This is measured, not asserted.** Standing Principle #9 applies to the project's own claims as much as to framework internals: the floor goes into the Phase 1 benchmark alongside the storage numbers, and every phase's *done when* includes still meeting it. A floor nobody measures is a floor that quietly rises.

⚠️ **And the measurement ships, so an operator can make it too — which is why it must leave their installation as it found it.** Installing the subtree split into a bare host (issue #8) found `kitsune:benchmark-floor`, `-storage` and `-admin` registered in every host's console with nothing guarding them: the floor benchmark left its org, site, entry type and every inserted entry behind, the storage benchmark left its fixture org, and none asked before writing to production. Moving them into the monorepo's own tooling was considered and rejected — this ADR's claim is that the floor holds on *the operator's* hardware, and a measurement only the project can run is the project's word for it. So they stay in core and behave like it: each asks in production unless given `--force`, as Laravel's own destructive commands do, and each removes what it created unless given `--keep` — an org it made is force-deleted, since `Org` soft-deletes and a trashed org is still residue, and one it found keeps everything the run did not add. Review found what that takes one piece at a time. Each run's rows carry a token that run generated, and cleanup removes that token and nothing wider: a slug pattern also matched the corpus an earlier run kept, and an id range also held whatever a second, overlapping run inserted. The token belongs to one invocation, because Artisan keeps a command object across calls and state an earlier run left on it once made a run that inserted nothing remove the rows that run had kept. An org the run created goes only when nothing is left in it, decided under a lock on its row: a second run that found it and inserted under it keeps it, because a fixture two overlapping runs share cannot be removed without removing rows a running benchmark is still using. And runs of one benchmark are serialized by a lock the running process holds — an exclusive `flock` on a file under `storage/framework` — because overlapping runs share what no per-run token divides: a fixture joined without inserting anything, and the storage benchmark's generated columns on `entries`. A second run is refused before it touches anything, and the lock goes when its process does, however that ends. The first lock was Laravel's command mutex, and review found it wrong on exactly that point: a cache lock on a clock expired under a run that was still going, and stayed held for the rest of its hour after a run was killed before its `finally`. The stated limit is the host: runs started on two machines against one database are not serialized, and the token and the org check are what hold for them. The storage benchmark drops only the generated columns it added, for the same reason: a column an earlier run kept is that run's, as its rows are. They go out beneath the audit trail, the way they went in: removing them through `Entry` recorded a force-delete per row, so a run in an org it did not create — and the admin benchmark always borrows a real site — filled that org's audit log with deletions of content nobody wrote. And the fixture is created in one transaction, because cleanup cannot begin until it exists: a site slug another org already held failed the insert after the org was made, and left the org. And each command gives back the tenancy context it was called in, however it ends and exactly — cleared before it is restored, because `setOrg()` keeps a site of the same org: its fixture sets the application's one `Context`, and a caller running more than one command was left scoped to an org the benchmark had rolled back or removed.

### Why this needs stating rather than being left implicit

It is already the reason behind several decisions — SQLite support, the PHP 8.4 floor chosen partly because *"shared hosting lags"*, Blade + Livewire over a bundler, an installer that needs no database server. But **an unstated principle cannot be violated, only forgotten.** Each individual decision to require a little more is defensible on its own; the sum of them is Drupal 8, which raised the contributor floor and the hosting floor together and got forked over *"expensive upkeep."*

Requirements creep the way the third pillar erodes: by a thousand small choices that each make sense for the larger customer.

### What it forbids

Core may not require, for a default single-site install: a container runtime, a separate database server, Redis or Memcached, Elasticsearch or any search daemon, Node at runtime, or a always-on worker process. Anything in that list may be **supported and recommended at scale** — none of it may be **required to get to the onboarding screen or to run a small site**.

Full-text search must therefore work on native Postgres/MySQL/SQLite facilities at the floor. Background work must degrade to synchronous or cron-driven execution when no worker is running.

> **Amended 2026-09-15: the admin broke "no external services" on every page, and no test could see it.** Filament's default avatar provider builds each avatar as a `ui-avatars.com` URL from the signed-in user's and the site's initials. Every page view therefore sent personal data from the install to a third party (ADR-020), and a host with no outbound network showed two broken images. The alpha's local smoke test found it in the network log; nothing on screen looked wrong. `KitsunePanel` now draws the initials locally as an inline SVG, and `e2e/admin.spec.js` fails if an admin page requests anything from another host.

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

### Amendment, 2026-09-11 — the seam now carries the case it was written for

**This ADR claimed a guarantee the implementation did not keep, and review found it.** The test above is *"can a new field type be added without text direction"*, and the answer was **yes** for the one direction that needs more than an attribute: per-block direction for rich text was a private method on `RichTextType`, so a module registering its own type returning `Control::RichText` got none — and `FieldValueRenderer` deliberately adds none for `PerBlock`, because for rich text the direction belongs in the stored bytes rather than on the wrapper.

So the closed vocabulary reached every control and the cross-cutting rule reached one class.

That pass is **private methods on `Entry`**, applied from a private method there, keyed on `ValueDirection` rather than on the `Control` case — that enum is where the mapping already lives, and `PerBlock`'s own docblock already said direction is needed *inside* the value; this makes that sentence happen instead of restating it.

**It took three attempts to find a seam a field type cannot decline, and the ones that failed are worth recording.** Applying it in `BaseFieldType::toStorage()` made it control-driven and left it **overridable**: `MultiSelectType` and `RelationType` already override that method, a module may too, and a module implementing `FieldType` directly never reaches the base class at all. A protected hook there was also new extension surface before v1.2 — a plugin subclass with a same-named method fails to load, which is a concrete break rather than a theoretical one.

`Entry::convertFieldValuesForWrite()` is where every value that reaches the database is converted, and a private method on a model is what this codebase has already settled on as *"the first version a plugin cannot reach"*. The guarantee is enforced at the point of storage rather than inside the contract the guarantee is about.

**The machinery itself then moved twice more, and the second time cost something worth naming.** As a `final class BlockDirection` with public statics it was new public API before v1.2, which the freeze forbids whatever the intention. As a **trait of entirely private methods** it offered no callable surface — and was still an autoloadable symbol a plugin can `use` and wrap, which is the fourth version of the same objection.

So the pass is **private methods on `Entry`**: six hundred lines of DOM walking on a model, which is not where it belongs by any other measure. What buys it is that nothing outside that class can call, override, extend or bind to any of it, and ADR-029's argument is precisely that a cross-cutting rule must be **unforgettable rather than documented** — which a reachable seam is not.

The one duplication it cost is a twenty-line DOM serialiser, now in both `Entry` and `RichTextType`, because a private method cannot be shared. That is cheaper than a symbol a plugin can bind to, and it is recorded in both copies.

⚠️ **The test is what keeps it there**, and it asserts that the two rejected symbols do not exist rather than inspecting visibility — because visibility was the thing that turned out not to be enough.

**A consequence worth having:** with direction applied after the conversion rather than inside it, a conversion is lossy-only again — so the `RichTextType`-by-identity special case in `Entry`'s revision loss check is gone, along with the comment calling it *"the price of freezing the contract before v1.2 and the first thing to undo when it opens."* Moving the seam undid it early.

**And the guarantee now holds for markup this vocabulary does not know, which it did not at first.** The pass treated anything that was not a recognised container as inline — safe while its only caller was `RichTextType`, every element of whose output is in `ALLOWED_TAGS`, and wrong for the module output the seam exists to cover. A module emitting `<div>مرحبا</div>` got `<p dir="auto"><div>…</div></p>`, which a browser takes apart, leaving the element that bears the text with no direction at all: the invalid markup and the missed guarantee were the same defect.

*"Not a known block"* is not a definition of inline. Phrasing content is an allowlist now, so an unrecognised element is a run boundary — and it is **stamped rather than wrapped**, because `dir` is a global attribute valid on any element while a `<p>` is valid only in some places. The one thing that is safe to do to markup whose content model this pass cannot parse is exactly the thing the guarantee needs.

So the ADR is **not** amended to constrain what a `Control::RichText` type may emit, which was the alternative. A guarantee that holds only for the vocabulary core happens to ship is the kind of guarantee invariant 14 forbids publishing.

| Rejected | Why it lost |
|---|---|
| Amend this ADR to scope the guarantee to renderer-side direction | It concedes the load-bearing claim. The argument here is that *"issue #39 has already shipped that attribute three times and been short of complete twice, both times because the reach of a correct rule depended on somebody enumerating call sites"* — and the branch that prompted this amendment was the **sixth** round of that same failure. The ADR was the side that was right. |
| Leave the pass in `RichTextType` and document the limitation | Invariant 14: publish enforceable constraints only. A guarantee that holds for the type that happens to exist is not a guarantee. |
| Move it to the renderer instead | The stored bytes and the displayed bytes would drift: a value arriving from the API, a seeder or an import would be stored undirected and only look right when rendered by this panel. `castToStorage()`'s docblock has the longer form of this argument. |

---

## ADR-030 — kitsunecms.org runs on Kitsune, so the site waits for the blueprint

**Status:** Decided · 2026-09-10

Raised while starting work on the site and stopped before anything was built. The question asked was *what do we build it with*; the answer settles *when* instead.

**The project's own site is its first install.** Not a demo of one — the real thing, in public, with the maintainer as the operator who has to live with it.

That is available exactly once. A site stood up now on Astro, Hugo or WordPress would be replaced later by a Kitsune site, and that replacement would be a migration performed by the one person least able to learn anything from it: someone who already knows every workaround. Building it on Kitsune the first time makes the site a **test**. Building it on anything else makes the eventual move a **chore**, and chores get deferred.

### What it tests that nothing else does

Phase 5 commits to a first-party **Marketing Site** blueprint. Shipping that blueprint and hoping someone uses it is the weak version of that commitment; the project's own site being its first user is the strong one, and the difference is who absorbs the defects. Every gap in the blueprint becomes the maintainer's problem before it is a user's — which is the same argument ADR-024 makes for browser tests, applied one layer out. The PHP suite was seven-of-eight green while the dashboard returned 500 because nothing crossed the seam a user crosses. A blueprint nobody has installed is that suite.

The same holds for the resource floor. ADR-027 fixes it with a number — 1 vCPU, 1 GB RAM, SQLite, no container runtime — and **a floor nobody stands on is a claim rather than a constraint.** The site runs the self-host path at that floor, not KaaS, and that choice is disclosed below rather than left implicit.

### The real deadline is ADR-026, not the blueprint

ADR-026 requires the installer to be served from `kitsunecms.org` — versioned, checksum-pinned, **never from a redirect**. The domain therefore has to be serving real content before Phase 6's installer can ship at all. So the blueprint gates when the site *may* move; the installer sets when it *must*. These are different dates and the second one is not negotiable by preference.

**Verified 2026-09-10** (invariant 15): the domain is registered and delegated to Cloudflare nameservers, with no A record. Nothing is served. `README.md` links to it from the status banner — the most prominent link in the project — and that link 404s today. It is being left as-is deliberately; see the cost below.

### The bar

**The Marketing Site blueprint applies cleanly to a fresh install.** The trigger is the capability, not a version number, and a tag may or may not exist on the day it is met.

Concretely — all four, and each one answerable by somebody who is not the maintainer:

- Phase 5's **Marketing Site blueprint exists and applies to a fresh install** with no manual step outside the apply flow. No hand-edited config, no SQL, no *"and then you also need to."*
- It applies **idempotently**, which Phase 5 already requires of every blueprint: re-applying upgrades rather than clobbers.
- It does so **at the ADR-027 floor** — SQLite, 1 vCPU, 1 GB, no container runtime, no external services. The site is the first thing to stand on that floor for real, so meeting the bar on a developer laptop does not count.
- The result is **editable by its operator through the admin**: content changes without a deploy. That is the entire claim a CMS makes, and a marketing site that needs a commit to fix a typo has not demonstrated it.

**Why the capability rather than a tag.** A tag is something the maintainer decides; these four are facts about the software that someone else can check. Given the distortion risk named under cost below, the trigger for this particular decision should not be one the maintainer can satisfy by concluding it is satisfied.

**Why not the stricter bar.** Making the trigger *"a fresh floor-spec box, installed by the real installer, serves the site"* was considered and rejected as close to circular: ADR-026 cannot ship the installer until `kitsunecms.org` serves the checksum-pinned script, so gating the site on a working installer leaves the two blocked on each other at ship time. The site is meant to precede the installer and prove the ground it stands on — a marketing site is the smaller workload, and finding out there that the floor does not hold is much cheaper than finding out during an install someone else is watching.

### What this ADR does not claim

- **It does not commit to dogfooding at any cost.** If the blueprint lands and Kitsune still cannot serve the site, that is *information about the platform*, not a problem with the plan — and the honest response is to say so publicly rather than to quietly stand up a static site and not mention it.
- **It does not cover the documentation site** (Phase 6, separate roadmap line). Docs have different needs — search, versioning, deep cross-linking — and whether those are a Kitsune workload or a static-generator workload is unsettled. Deciding it here would be deciding it without evidence.
- **It does not forbid a holding page.** One was considered and declined on 2026-09-10 — a disposable non-Kitsune artifact is still an artifact somebody has to tear down, and the README already states the project is pre-alpha with nothing released.

| Rejected | Why it lost |
|---|---|
| Static site now (Astro/Hugo), migrate to Kitsune later | Spends the one first-install opportunity on a throwaway, and creates a migration whose most likely outcome is that it never happens. The project would then be a CMS whose own site runs on a static generator — the loudest statement available about how much its author trusts it. |
| WordPress now | Same as above, and worse: ADR-007 makes WordPress the migration *source* case. Being a WordPress site while building the thing that migrates people off WordPress is not a position that survives being noticed. |
| Run the site on KaaS, the hosted service | The commercially attractive option, and it degrades the self-host path by removing its most motivated user. See the disclosure below. |
| Wait for v1.0 rather than the blueprint | Leaves the domain dark for two more phases, and ADR-026 will not permit it — the installer cannot ship from a domain serving nothing, so the site has to precede v1.0 regardless. |
| Ship the site now as-is and call it the alpha | Inverts the dependency: the site would set the platform's readiness bar instead of the reverse, and everything unfinished becomes something to hide rather than something to fix. |
| Leave the timing informal — "we'll do it when it's ready" | This is the option this ADR exists to close. Invariant 12 binds the maintainer identically, and an undocumented intention is exactly what a future contributor routes around by opening a PR with an Astro site in it, entirely reasonably. |

**Cost, stated.** Four, and they are real:

- **The domain sits dark through pre-alpha**, and the README's most prominent link 404s. Accepted deliberately on 2026-09-10.
- **The site's launch is now coupled to the platform's readiness.** If Phase 5 slips, the site slips with it. There is no independent path.
- **The project's public face will run on alpha software.** A shop window that breaks in public is a worse first impression than no shop window — which is an argument for the bar above being strict rather than early.
- **⚠️ Distortion risk, and it is the one to watch.** Once the project is its own customer, roadmap pressure can start coming from *what the site needs* rather than *what operators need*. These overlap, which is what makes the drift hard to see. This paragraph is the thing to hold a future decision against when some feature is justified primarily by kitsunecms.org needing it.

⚠️ **Commercial interest, disclosed per ADR-023.** **Running the project's own site on KaaS would be the commercially preferable choice** — a visible reference install, continuous exercise of the paid product, and materially less work for the maintainer, since the hosted platform is the path with someone paid to keep it working. It is rejected here.

GOVERNANCE and ADR-026 both commit that the self-host path is never degraded, and **the most reliable way for a self-host path to degrade is for the people maintaining it to stop walking it.** ADR-026 already discloses that a frictionless installer runs against the hosted service's interest; this decision is the same tension one level up, and it resolves the same way. If kitsunecms.org is ever quietly moved onto KaaS for operational convenience, this paragraph is the thing that was traded away.

The inverse is a genuine gap and is named rather than solved: **KaaS then has no dogfooding of its own.** That should be met by giving the hosted platform a real first-party workload of its own, not by relocating this one.

---

## ADR-031 — An authored pattern is validated against a published grammar, not screened for known divergences

**Status:** Decided · 2026-09-10 · Amended 2026-09-10

Issue #44. `Pattern::unpublishable()` began as a screen: a list of constructs known to diverge between PCRE and ECMAScript, refused by name. A screen is a denylist, and the failure mode of a denylist is the construct nobody thought of — which here means a pattern the server enforces and the published JSON Schema does not, or the reverse.

`TextType` publishes the author's pattern into `scalarApiSchema()` and validates values with `patternRule()`. The two consumers are a generated client's regex engine and PCRE, so a divergence is not cosmetic: the client validates locally, the server validates authoritatively, and they disagree about the same string.

### Decision

**A pattern is accepted when every construct in it appears on a published allowlist. Anything else is refused, with the reason, whether or not anyone anticipated it.** The grammar is published in `docs/field-types.md` §3 and enforced by `Pattern::unpublishable()`.

The direction of staleness is what settles this. An allowlist goes stale by refusing something portable: the author is blocked, sees a message naming what *is* allowed, and a maintainer adds the name — reviewable, testable, and visible. A denylist goes stale by *accepting* something unportable, and that is found by a consumer failing in production. There are also ~170 Unicode scripts, so a list naming them in order to refuse them would go stale on every Unicode release.

**The allowlist was derived by measurement, not from the specifications.** Every candidate name was compiled in both engines and only the ones both took are on the list — which earned its keep immediately: `Assigned` and `Changes_When_NFKC_Casefolded` are in ECMAScript and PCRE rejects both, while `LC` is a group category both engines have that the first hand-written list omitted. `tools/pattern-parity/` is that measurement, landed as a harness so the claim is reproducible rather than asserted.

### What the grammar guarantees, and what it cannot

**It guarantees dialect portability:** the construct exists in both grammars and means the same *rule* in each. That is enforceable by an allowlist, and it is what this ADR delivers.

**It cannot guarantee freedom from engine BUGS either, and what to do about one depends on what the shape costs.** Review measured `(?=a)a?a` diverging on PCRE 10.44 with Node 24 — PCRE not matching `a` — while on PCRE 10.48 with Node 22 both match, along with six neighbouring shapes. Both dialects define that pattern identically, so the disagreement is an upstream defect fixed between those releases rather than a portability property.

**It is refused anyway, and the reason is the shape rather than the measurement.** A lookahead asserting the character the following optional atom consumes constrains nothing that atom does not — it is `a?a` with a no-op in front. Nobody authors it deliberately, so the expressiveness cost is approximately zero, while `composer.json` requires PHP `^8.4` whose earliest releases bundle PCRE2 10.44: the exposure is real. **Cheap insurance against a real deployment beats a rule that is right on one engine pair.**

That calculus is what generalises, not the verdict. A divergence this project cannot reproduce is still worth refusing when the construct has no legitimate use; one that would cost authors a shape they actually write belongs in the harness, which reports it as a live defect on whichever pair still shows it, rather than in a grammar rule that outlives the bug.

**It cannot guarantee Unicode-version stability**, and this is disclosed rather than implied. `\p{L}` gained SIDETIC LETTER N01 (U+10940) in Unicode 17.0, so a server at 15.1 and a client at 17.0 enforce different rules on `^\p{L}+$` for that codepoint. No allowlist can make two engines share a Unicode table, and the client's version is outside the operator's control entirely. A property claim is portable **only to the extent the two engines share a Unicode version.**

`Cn` and `C` are excluded, and the reason is not that their membership moves — every category's membership moves. It is that neither polarity of them names a stable rule to converge on. *"A letter"* is a rule both engines are answering, one with a shorter table, converging with each release. *"Not yet assigned"* describes the table's incompleteness, so the answer moves *away* from the author's intent every release: `\p{Cn}` matches steadily less, `\P{Cn}` steadily more. `C` is `Cc|Cf|Co|Cs|Cn` and inherits that. Their complements are refused with them, and a complement of any *other* property is permitted — symmetrically with the property, since `\P{L}` and `\p{L}` diverge on the same codepoint in opposite directions and refusing one would claim a portability the other does not have.

### Consequence

**Migration is not optional.** Patterns already authored were accepted by the screen, not by the grammar, so any outside it must be found before this lands — a pattern that saved yesterday and is refused today is a broken install, not a fixed one.

The measured expressiveness cost is three refusals of constructs both engines honour, and all three are deliberate: `\b`, which has a portable spelling to redirect an author to, and `\p{Cn}`/`\p{C}`, which the measured pair cannot show diverging because it shares one Unicode version. `\p{Lower}`, `\p{Alpha}` and `\p{Upper}` *were* omissions and are now on the list, added on a set comparison across all 1,114,112 codepoints rather than on compiling — `\p{Space}` compiles in PCRE and is rejected by ECMAScript, which is why the aliases were measured one at a time instead of adopted as a family.

| Rejected | Why it lost |
|---|---|
| Keep the screen, add divergences as they are found | Its staleness mode is accepting an unportable construct, discovered by a consumer failing. Seven of the first eight "divergences" measured were artefacts of the harness rather than the code, which is how much confidence a by-name list of offenders deserves. |
| Allow any name PCRE accepts | Publishes a JSON Schema the consumer cannot compile, and reports nothing when it happens. |
| Exclude every version-sensitive property | Empties the list: `\p{L}`, `\p{Nd}` and every complement qualify. It also claims a guarantee no allowlist can keep, which is invariant 14's failure. |
| Refuse the complements (`\P{L}`, `[^\p{L}]`) but keep the properties | Half of a symmetric pair. Both diverge on the same codepoint, in opposite directions, on the same engines. |

### Amendment · 2026-09-10 — the allowlist is necessary and not sufficient

Review found two patterns built entirely from permitted constructs that the grammar should not publish: `^(a|aa)+$`, which makes neither engine answer, and `(?<=([ab]{1,2})([bc]{1,2}))\2\1$`, on which PCRE and ECMAScript disagree outright. Checking them surfaced the larger problem.

**`docs/field-types.md` §3 published three such rules and the code enforced none of them.** Measured: `^(?=a)+a$`, `(?<=(a|aa))b\1$` and the document's own example `^([a-zA-Z0-9]+\.?)+$` were all accepted by `Pattern::unpublishable()`. The first two are exactly the two `divergent AND accepted` rows the parity harness had been reporting — the instrument built to find this was reporting it, and the document was read as if it described the code.

That is a worse failure than an unwritten rule. An unwritten rule leaves an author to discover a divergence; a written one that is not enforced tells them the divergence cannot happen. It is the same defect as the `\p{Lower}` claim corrected in the same commit, and both are invariant 14.

**Decision: the grammar is an allowlist of constructs *and* a closed set of structural rules, enforced together.** `structuralRefusal()` holds four, each a property of how constructs fit together rather than of any construct:

1. No quantifier on an assertion.
2. A lookbehind's alternatives must be equal length.
3. An unbounded repetition must have only one way to divide its subject.
4. A capturing group inside a lookbehind must be fixed length.

Rules 3 and 4 are review's in part. Rules 1–3 were already published, and are now true.

**The two exemptions are proofs, not conveniences**, and both were forced by measuring the cost of the rule without them:

- Rule 3 stated bluntly refuses `^[^,]+(?:,[^,]+)*$`, the ordinary delimited list. If the repeated body begins with a required literal and no unbounded quantifier inside it can match that character, every iteration must begin at an occurrence of it and none can consume one — so the subject's own delimiters force the split. One way to divide, nothing to backtrack over. Measured linear: 5,000 items in 0.04 ms (PCRE) and 0.09 ms (ECMAScript), on input that fails at the last character.
- Rule 4 stated bluntly refuses `^(?:cat|dog)+$`. Prefix-freeness is exactly the condition under which at most one branch can match at a position, so the alternation is deterministic.

**Rule 5's line was placed by measurement rather than by caution.** A blanket ban on captures inside lookbehinds was the obvious rule and is wrong: with a fixed width the engines agree, including two adjacent captures. Only variable width diverges, because the engines traverse a lookbehind in opposite directions. Two forms that *agree on the subjects tried* — `(?<=(a{1,2}))\1$` and `(?<=(a?))\1$` — are refused anyway, because that agreement is subject-dependent rather than a property of the construct.

### Consequence

The harness reports **0** `divergent AND accepted`, down from 2. The expressiveness cost is 6 rows, every one deliberate: `\b` has a portable spelling, `\p{Cn}` and `\p{C}` are version skew the measured pair cannot show, and three are refused on cost rather than portability.

**Structural rules make the migration warning sharper, not softer.** A pattern that was accepted by the construct screen can now be refused for its shape, and `^(a|aa)+$` is a plausible thing to have authored. The audit before this lands must run `unpublishable()` over stored patterns, not just check them against the construct list.

| Rejected | Why it lost |
|---|---|
| Leave the three rules as documentation | They read as enforced. A reader has no way to tell the difference, which is what made them worse than absent. |
| Blanket rules with no exemptions | Refuses `^[^,]+(?:,[^,]+)*$` and `^(?:cat|dog)+$` — the commonest safe shapes there are. A validator paid for by every author is not free because the cost is invisible in the diff. |
| Detect ambiguity in general | Not something to attempt in a validator on the authoring path. The exemptions are narrow, provable, and fail closed; everything else is refused with the portable spelling named. |
| Ban captures inside lookbehinds | Measurement says fixed widths agree. It would have refused four working forms to catch two broken ones. |

---

### Amendment · 2026-09-10 — one property, not two rules; and the migration exists

Three more from review, and each is a case where the previous amendment fixed a symptom rather than the property behind it.

**Two rules were aimed at symptoms, and a third symptom walked between them.** The pair was "no unbounded quantifier over a group containing one" and "not over ambiguous alternation". `^(a{1,2})+$` satisfies neither trigger — the inner quantifier is bounded and there is no alternation — and measures at ECMAScript ~100 ms for 30 characters, past three seconds for 40, with PCRE's backtrack limit exhausted. Worse, a test in this repository asserted `^([a-z]{1,8})+$` was *acceptable*, under the heading "accepts a bounded outer quantifier". Same shape, same measurement. Two symptom rules let one symptom through and blessed another.

They are now one rule about the property: **an unbounded repetition must have only one way to divide its subject.** A body establishes that by being fixed-width with every alternation inside it unambiguous, by having prefix-free literal alternatives, or by being delimited.

**A forced division turned out not to be sufficient, which measurement established rather than reasoning.** `(?:[a-z]|x)+` is fixed at one character wide, so the division IS forced — and it is still catastrophic, because `x` lies inside `[a-z]`: on 30 `x` characters both branches match at every position, giving 2³⁰ branch choices. ECMAScript 7.9 s; PCRE's backtrack limit exhausted. The same pattern on 30 `a` characters is instant. So the fixed-width clause carries a second condition, checked recursively so that `(?:a(?:b|c))+` — as safe as `(?:ab|ac)+` — is not refused for having its alternation one level down.

**Every scanner advanced past a backslash by exactly two characters**, which is right for `\.` and wrong for every escape with a payload. `fixedWidth()` read `\x61` as a `\x` atom plus the literals `6` and `1` and reported width 3 for a one-character escape, so `(?<=(\x61|aaa))b\1$` passed the equal-length lookbehind rule — PCRE says no on `aaaba`, ECMAScript says yes. One arithmetic error bypassed both lookbehind rules. `escapeSpan()` is now the single answer to "how long is this escape", used by every scan; the payload also matters for correctness rather than only precision, since `\c|` puts a `|` where a two-character step reads an alternation that is not there.

**And the migration this ADR calls mandatory did not exist.** The consequence section said patterns outside the grammar "must be found before this lands"; review searched for the command and there was none. `kitsune:audit-patterns` reports every stored pattern the grammar refuses, with `--strict` as a deployment gate. It has no `--force`, deliberately unlike `kitsune:schema-sync`: a pattern says what a field accepts, and only its owner knows what that should be.

That is the third time in this ADR's short life that something was published without being enforced — the three structural rules, the `\p{Lower}` allowlist claim, and now the migration itself. The pattern is worth naming: **prose describing intended behaviour reads exactly like prose describing actual behaviour**, and nothing in review catches the difference unless someone goes looking for the implementation.

### Consequence

Live defects remain **0**. The expressiveness cost is nine rows: three are portability judgements (`\b`, `\p{Cn}`, `\p{C}`) and six are cost refusals where the engines only appear to agree because the harness's subject is benign — for five of them neither engine gives a verdict at all.

| Rejected | Why it lost |
|---|---|
| Keep two rules and add a third for bounded repeats | A fourth symptom would have followed. The property is "one way to divide", and rules that enumerate shapes will keep missing shapes. |
| Treat fixed width as sufficient | Measured false: `(?:[a-z]|x)+` divides one way and still costs 7.9 s. |
| Check alternations only at the body's top level | Refuses `(?:a(?:b|c))+`, which is exactly as safe as `(?:ab|ac)+`. |
| Decide class overlap properly, so `(?:a|[b-z])+` publishes | Real analysis on an authoring request, to admit a pattern whose author can bound the repetition instead. Refused conservatively and the cost reported. |
| Ship the enforcement and write the audit later | The audit is what makes the enforcement safe to deploy. "Later" is after somebody's install broke. |

---

## ADR-032 — The mark reduces to a unit, not to a lesser fox, and it is adopted provisionally

**Status:** Provisional · 2026-09-12 — adopted for use, not for registration · **Amended 2026-09-12** — the vector source landed and was measured the same day; see the amendments below

Raised because a comp exists. It settles two things that were about to be settled by accident: what the mark system is, and whether having drawn one violates the roadmap's instruction not to spend on a logo before name clearance.

### The comp is a direction, not an asset

The artwork this decision was taken from is **raster only**. That makes it a design direction and nothing more — it cannot be placed at arbitrary size, its palette cannot be sampled reliably, its wordmark corresponds to no licensed typeface, and a registration cannot be filed on a JPEG. A vector redraw is in progress and every asset in [`brand/README.md`](../brand/README.md)'s manifest depends on it.

This matters beyond convenience. **Five defects are visible in the comp and all five are cheap in vector and expensive afterwards** — the cream tail tips vanishing against white, the keyline haloing on dark grounds, the glyph's tip at small size, the absent horizontal lockup, and the unoutlined wordmark. They are listed in `brand/README.md` rather than here because that is where somebody opening the source will look.

**Amended 2026-09-12 — the vector landed the same day, and three of those five defects did not exist.** The tail tips do not vanish on white: the white shapes are fully inset within the rust and read as notches on every ground tested. There is no keyline to halo, and there never was — the comp only appeared to have one. The wordmark was already outlined as paths, so no typeface licence question arises. **All three were reasoned from a rasterised comp and all three were wrong**, which is Standing Principle #9 arriving from the direction nobody watches: the failure mode is not only asserting a problem is absent, it is asserting one is present and spending the redraw on it.

What measurement found instead was worse than what it cleared. **The teal wordmark fails on dark grounds at 2.19:1**, and no single hue serves both grounds — `#2E96A4` is the minimum that clears on dark and falls to 3.49:1 back on white, so the wordmark needs two colours. **The mark cannot sit on its own brand teal at all**: the legs and paws are `#00545D` against a `#00545D` ground, which is 1.00:1, and the render shows a fox with no legs. Neither was visible in the comp and neither was predicted. Both are in `brand/README.md` with the numbers.

The two defects that survived are the two that were about absence rather than appearance — no horizontal lockup, and the glyph's tip below 24px. Absence was the thing reasoning could get right.

### The ladder abstracts; it does not reduce

Three marks — formal lockup, compact fox, single-tail glyph — and the rule that generates them is that **the smallest mark is one unit of the largest, not a shrunken copy of it.**

A kitsune's tails are its counting unit; nine is the mature form. So the ladder drops the wordmark, then drops to the tail. Nine of the glyph is the logo.

**Amended 2026-09-12 — the glyph is two tails, not one.** The original pick was a single tail, on the reasoning that one unit is the cleanest possible reduction. Rendering both at 16, 32 and 48px on white, dark and grey settled it the other way: **the mirrored pair carries structure that a single diagonal stroke does not**, because symmetry gives the eye an axis to resolve when detail is gone, and a lone tail at 16px is one stroke with a closing notch. Two-tailed kitsune are a stage in the folklore, so the count is still a count — the rule that the glyph is a tail-count and never a lesser fox is unchanged, and it is the rule rather than the number that this ADR fixes. The decision was made by looking at the thing at the size it will be used, which is the only way it could have been made correctly.

**The rejected form is the one that looks most obvious: a one-tailed fox.** It fails three ways at once. Visually, a single orange fox at 32px is the most crowded image in software and sits closest to the marks a clearance search will surface. Structurally, it is a *reduction* — a shrunken picture of a complex mark, which is the thing that reliably turns to mud at favicon size. And in the folklore it is a juvenile kitsune, so the smallest and most-repeated mark would depict the least of what the name claims.

The distinctiveness lives in the nine-tail fan and in the tail as a shape. It does not live in the fox, and a mark that reduces toward the fox reduces toward the generic — which is the wrong direction for something whose entire job at 16px is to not be mistaken for a competitor.

The glyph reads secondarily as a flame. That was checked rather than assumed and is **on-myth**: *kitsunebi*, fox-fire, belongs to the same folklore. An abstract glyph whose two available readings are both correct is a better outcome than one with a single enforced reading.

### Provisional, on the precedent this project already set

Roadmap [#1](https://github.com/adamgreenwell/kitsune/issues/1) says clearance comes before spending on a logo, and the open question says the same. **Neither is violated, because designing a mark and registering one are different expenditures** — and the docs currently conflate them. Drawing costs nothing to reverse. Filing does.

So the mark is adopted the way the domain was: **settled provisionally, 2026-09-12**, mirroring `kitsunecms.org`'s status of 2026-09-07. Provisional means the mark is used — README, the site, the admin, the manifest in `brand/` — while **registration stays gated on the software and SaaS class search in roadmap [#2](https://github.com/adamgreenwell/kitsune/issues/2).**

The visual decision feeds back into that search rather than merely waiting on it. A distinctive mark is easier to protect and a generic one is harder; a fox-shaped mark alongside two existing "Kitsune" projects argues *for* confusion if there is ever a dispute, and the nine-tail fan argues against it. Choosing the more distinctive form is therefore a clearance input, not just an aesthetic preference. **This is not legal advice and does not substitute for the search.**

### The bar for dropping "provisional"

<!-- TODO(adam): the exit criteria. ADR-030's "The bar" is the shape to match — conditions
     somebody who is not the maintainer can check, rather than conditions the maintainer can
     satisfy by deciding they are satisfied. Candidates to weigh: clearance returning clean in
     the relevant classes; the vector existing with the five comp defects resolved; the palette
     measured rather than transcribed; TRADEMARK.md published. Whether a filing must be
     *granted* or merely *filed* is the consequential one — granting can take a year or more,
     and gating on it leaves the mark provisional through v1.0. -->

### The licence boundary is drawn at the directory

ADR-005 separates the code licence from the trademark, and GOVERNANCE.md restates it. **That separation only holds if the asset files are actually outside the MPL**, which is why they live in a top-level `brand/` with their own notice rather than anywhere under `packages/` or `skeleton/`. Shipping the mark as an MPL asset inside the package would license the one thing the trademark policy exists to withhold, and would do it silently.

Two consequences follow. `skeleton/` never ships the mark as a default site logo — an operator's logo is `site_group.settings.logo` (ADR-022) and is unrelated content. And the split-publish of `kitsune/core` ([#8](https://github.com/adamgreenwell/kitsune/issues/8)) takes the `packages/core` subtree, so `brand/` stays out of the published dist without needing an export rule.

### Accessibility is a property of the mark, not of its usage

Pillar three is tested rather than claimed, so the brand carries requirements rather than guidance: a monochrome variant, dark-ground variants, a fixed alt-text convention, and measured contrast. **The palette is currently unmeasured and is recorded as such** — sampling it from a rasterised comp would be exactly the reasoned-not-measured failure invariant 15 and Standing Principle #9 exist to catch. Rust is *expected* near the 4.5:1 boundary on white; which side it lands on decides whether it may ever carry text, and that is a measurement nobody has taken.

| Rejected | Why it lost |
|---|---|
| One-tailed fox as the small mark | The original proposal. Generic at exactly the size where distinctiveness matters most, adjacent to every other fox-named project, and a juvenile kitsune in the folklore the name comes from. |
| Head-plus-fan glyph | Considered and dropped in favour of the tail. Still a reduction rather than an abstraction — a shrunken picture of the logo, with a face that becomes mud at 16px and a fan that becomes a lumpy halo. |
| A single tail as the glyph | The original pick, reversed on 2026-09-12 by rendering both at icon sizes. One diagonal stroke with a notch has no axis to resolve at 16px; the mirrored pair does. |
| Two marks instead of three | Forces one asset to serve 512px and 16px. Whichever size it is drawn for, it fails the other. |
| Adopt outright and file now | Spends the clearance budget before knowing whether the name survives contact with the two existing "Kitsune" projects. The roadmap put clearance first for this reason. |
| Hold the mark entirely until clearance returns | Leaves the README, the site and the admin with no mark for an unbounded period, to avoid a cost — redrawing — that is already sunk and was never large. Provisional adoption gets the same protection at a fraction of the delay. |
| Ship the raster comp in the meantime | A logo that cannot scale, cannot be recoloured for dark grounds and cannot be filed is not a stopgap, it is a second migration. |
| Brand assets under MPL with the rest of the repo | Contradicts ADR-005 outright. The trademark is the moat that ADR-005 identified when it concluded the licence is not; licensing it away by filing convenience is the most expensive possible clerical error. |

**Cost, stated.** Four:

- **Everything downstream waits on a vector that does not exist yet.** No favicon, no social card, no admin mark until the redraw lands.
- **The mark may have to be abandoned.** If clearance comes back contested, provisional adoption means the README, the site and any published assets carry a mark that has to be pulled. The cost is bounded by keeping the manifest small until clearance returns — which is an argument against producing the full asset set early, and is why the manifest is a checklist rather than a batch job.
- **A provisional mark invites treating it as settled.** Every use makes the reversal marginally more expensive, and nobody will notice the moment it stops being cheap. This paragraph is what to hold that against.
- **The trademark notice in `brand/LICENSE.md` is not lawyer-reviewed.** It states the intent so the boundary exists from the moment assets land, and it is a placeholder for `TRADEMARK.md`. An unreviewed notice that overstates the position is worse than none, which is why it claims referential use is permitted rather than attempting to enumerate every restriction.

### Where this ADR actually landed, and why the commit does not say so

⚠️ **This ADR, `brand/LICENSE.md`, `brand/README.md` and all twelve vector files reached `main` inside [`5b94182`](https://github.com/adamgreenwell/kitsune/commit/5b94182679daef5b8266cf1192511edc98f1bcbf) — the squash merge of [#64](https://github.com/adamgreenwell/kitsune/pull/64), "Enforce rule 3 by a published grammar, and land the parity harness."** That message describes the pattern grammar and mentions none of the branding. Anyone reading `git log` for when the brand was decided will not find it there. The same mixing happened one commit earlier on the branch, in `8807011`.

It was noticed, and **it was deliberately not rewritten.** Fixing it means force-pushing `main` on a repository that ADR-014 made public from the first commit, breaking every clone and every branch built on `5b94182` — `feat/rbac-storage` and `feat/rich-editor-direction` both carry it. A rewrite is also the git form of the thing this log exists to refuse: the README's claim is that keeping reversals in is what separates an engineering record from a marketing document, and quietly deleting a messy commit is deleting a losing option. **The annotation is the honest fix; the force-push is the tidy one.**

The raster exports were kept out of it and landed separately on `docs/brand-assets`, along with this paragraph.

**The lesson is not "write better commit messages."** It is that a long session which touches one area while a branch is checked out for another will silently pool both into whatever commit comes next, and nothing in the tooling objects. The guard is to branch at the moment the subject changes, not at the moment the work is ready to commit.

---

## ADR-033 — Kitsune owns its RBAC, and a permission is a string a role holds

**Status:** Decided · 2026-09-13 · **Amended 2026-09-13** — twice during the wiring: the owner bypass does not resolve in `Gate::before`, and the scope hatch does not suspend the authority guards; see the amendments below · **Amended 2026-09-19** — the owner flag and the status vocabulary are read under every spelling the database writes; see the consequence on column names, and ADR-021's amendment of the same date

Issue #81. Phase 4's last unchecked line is `EntryPolicy`, blocked rather than deferred: a policy needs roles and permissions to resolve against. `architecture.md` §4 already fixes the naming — `entry.{type_handle}.{view|create|update|delete|publish}`, resolved against `type_handle`, seeded by blueprints — and settles nothing about where any of it lives.

### Decision

**Kitsune authors its own RBAC.** Three tables: `roles` and `role_permissions` in core, `role_user` in the skeleton. A permission is a **string** a role holds, validated against a published registry at write time. Assignment is per-org.

### Why not `spatie/laravel-permission`

It is mature, audited, and adopting it would save real work — including a resolution cache this now owns and will have bugs in. Four reasons it does not fit, in descending order of how hard they are to work around:

- **AGENTS.md invariant 2: every model declares its scope.** A vendor model cannot carry `#[OrgScoped]` and cannot `use EnforcesScope`. The package's `teams` feature models **one** scoping axis; Kitsune has two that scope data and one that does not (ADR-021). An authorization table with no declared scope is precisely the shape invariant 2 exists to refuse, and it would be the first one in the schema.
- **Permissions here are derived from schema rather than enumerated by an operator.** Entry types are created at runtime through the admin, so the permission set changes when content modelling changes. A design whose mental model is a fixed list maintained in a seeder is fighting the flagship feature.
- **Standing Principle #1.** The extension API is deliberately unstable until v1.2. Adopting a package puts its public API inside Kitsune's surface before Kitsune has one, and taking it back out later is the ecosystem break that principle exists to avoid.
- **ADR-025** already sets the bar: a runtime dependency of core gets argued for rather than assumed.

### Why a table rather than a JSON column on the role

A JSON array of permission names on `roles` is one fewer table and cannot drift. It was rejected on **ADR-015's own argument, applied to a different subject**: relations are a real table "so reverse lookups and referential integrity work". Authorization asks the reverse question constantly — *who can publish articles?*, *what will break if this entry type is deleted?*, *which roles hold the grant this incident report is about?* — and a JSON blob answers none of those without decoding every row.

### A permission is a string, and what that costs

`role_permissions (role_id, permission)`, unique on the pair.

A normalised `permissions` table with a foreign key was rejected because it makes deleting an entry type a **cascade decision about authorization**: rows would have to be created as types are created and destroyed as types are destroyed, so a content-modelling change becomes a security change, and the failure mode of getting that wrong is silent over-permission.

**The cost is that a misspelled permission is silently never granted.** It fails closed, which is the right direction, and invisibly, which is not. Three mitigations, in the order they fire:

1. **A published registry of actions, enforced at write time, fail-closed.** `Permissions::REGISTERED` names the actions; a grant whose shape or action is not on it is refused with the reason — the `pii_class` pattern from ADR-020, for the same reason: the answer is required *now* and getting it wrong is a security defect rather than a formatting one.
2. **The registry validates SHAPE, not existence.** A grant may legitimately name a type that does not exist yet, because a blueprint seeds permissions alongside the type it creates and the two arrive in one operation. So `entry.product.view` is accepted on an installation with no `product` type.
3. **A report of grants naming a type that no longer exists** — the same shape as `EntryType::withoutSubjectIdentifier()`, which lists the holes a subject-access request cannot see. A grant pointing at nothing is not dangerous; it is *confusing*, and the way to keep it from becoming a belief about what a role can do is to be able to list it.

### No implicit wildcard, and an explicit one that is a decision

**`entry.*.{action}` is a grant somebody writes, never one a role gets by default.** A wildcard applied silently means an entry type created next month grants access to data that did not exist when the role was written — a privacy failure mode, and this project's answer to those is to fail closed.

It is resolved at **check** time rather than expanded at grant time, because expanding it would make it precisely *not* cover types added later, which is the only reason to write it.

⚠️ **And resolving it at check time meant validating the string being asked about, which the first version did not** — review found it failing open. The match was built from any three segments ending in a registered action, so a holder of `entry.*.view` was granted `site.settings.view`: a **subject this vocabulary does not have**. Today `entry` is the only one, so the wrong answer is about a permission nobody asks for; the moment a module adds `media.image.view` in v1.2, every `entry.*` holder would have held it retroactively, by a wildcard written about entries.

`Permissions::allows()` therefore refuses anything outside the published vocabulary before it resolves anything — **including for an owner**, because an owner told yes about `site.settings.view` is an owner whose caller now believes such a permission exists. What it does **not** check at that point is the type segment's SHAPE: that rule belongs to writing a grant (`validated()` refuses `entry.art*cle.view`), and the entry type table accepts a handle the admin form would not, so refusing one here would deny authority over data that exists.

The alternative — no wildcard at all — was rejected on arithmetic: an org with 40 entry types and 6 roles maintains 240 grants by hand, and the predictable response to that is a script nobody reviews. An explicit, visible, single-row opt-in is better than a bypass invented in the field.

### Assignment is per-org, and the site dimension is deferred rather than absent

`architecture.md` says per-org, and this keeps it. A user who should edit on one site and only read on another is **not** served, and that is a real limitation rather than an oversight: `site_user` already decides which sites a user reaches, so what v1.0 offers is *which sites you can enter* × *what you may do in the org*.

Recorded here because the alternative is somebody discovering it while configuring a customer.

### An owner role, because the first user has to be able to act before any permission exists

Bootstrap requires it: somebody must create the first entry type before a permission naming that type can exist. `roles.is_owner` is the flag; a handle named `owner` would make the bypass depend on a string an org can rename.

⚠️ **Amended 2026-09-13, during the wiring: it does NOT resolve in `Gate::before`,** which is what this ADR said first. A before-hook applies to **every** ability in the application, including policies the host application wrote for its own models — so core would be deciding that an org owner may do anything in somebody else's code, which is not core's decision to make. The bypass lives inside `Permissions::allows()`, where its blast radius is the permissions Kitsune defines and nothing else.

⚠️ **Issue #81 proposed auditing the bypass whenever it is what granted an action, and that is withdrawn on volume.** An authorization check runs per row: the entry list at 100k rows with the default page size fires ten `view` checks, a bulk delete fires one per record, and an owner browsing an admin would write audit rows faster than they write content. The log ADR-020 designed is for *actions*, and "somebody was permitted to look at a row" is not one.

**What is audited is the assignment** — who was made an owner of which org, and when — which is rare, high-value, and the question an auditor actually asks. One row per assignment instead of thousands per page.

⚠️ **And that sentence was a published claim with nothing enforcing it for one commit**, which AGENTS.md #14 forbids and which is worth recording rather than quietly fixing. `AuditedBuilder` is bound to `Entry` — deliberately, and its docblock says to generalise it when a second case turns up to check the design against — and `role_user` is a skeleton pivot with no core model in front of it at all, so there was no builder to audit at.

**So the audit lives in the four methods that change authority:** `Role::grant()`, `revoke()`, `assignTo()` and `removeFrom()`.

⚠️ **And the first version of those rows could not answer the question this ADR asks of them, which review found.** They recorded the ROLE as the target, so with two people holding one role the log said *somebody was assigned it* — and `audit_log` carries actor, action and target with deliberately no payload (ADR-020), so there was nowhere for the user to go. An assignment has **three** parties and the schema holds two.

**The target is the USER**, because that is the irreplaceable half: a named role is there to be read while it exists, and the person whose authority changed is the question. **Owner-ness goes in the action** — `role.owner_assigned` and `role.owner_unassigned` beside `role.assigned` and `role.unassigned` — because it is the fact this ADR singles out, and an action is a vocabulary rather than a payload.

**What the log therefore does NOT answer, stated rather than implied:** which *named* role a non-owner assignment was, and which *permission* a `role.granted` was. Both are the payload ADR-020 refuses, and both are recoverable from the role itself while it exists. What survives the row being deleted is the security-relevant shape: **who gained or lost authority in this org, when, at whose hand, and whether it was the owner bypass.** `$user->roles()->attach()` remains an unaudited back door, in exactly the sense ADR-020 already states about `toBase()`: the guarantee is about the path core provides, reaching past it is explicit and visible in review, and claiming more would be claiming a guarantee the Eloquent layer cannot give.

**Creating a role is deliberately not audited, and that is a line rather than a gap.** The log records changes to **authority**, and a role holding no grants and held by nobody is not authority — it is a name. Authority changes on the first grant or the first assignment, and both of those are recorded.

⚠️ **And flipping `is_owner` is a third way authority changes, which the first version missed.** Review found it: turning the flag on for a role that already has holders gives every one of them the bypass immediately, and their assignment rows were logged as `role.assigned` — so nothing in the log said they were owners now, and this ADR's own question was unanswerable again. `Role` records `role.owner_assigned` for each affected holder on the transition, and `role.owner_unassigned` on the way back. **One row per person**, because the question is about people: a single `role.updated` would record that something changed and leave the answer exactly where it was.

### What the vocabulary does not cover, and what that costs

`architecture.md` publishes five actions on **entries** and nothing else, so two things an org will want to delegate have no permission to ask for: **editing the schema** and **administering roles**. Both are owner-only in v1.0.

⚠️ **That is a limitation rather than an omission, and it was found the hard way.** Review pointed out that after this ADR landed, the seeded copy-editor could still create, rewrite and delete entry types while being refused `/c/product` — a permission system governing the content and not the shape of the content governs the smaller half. Adding a subject (`schema.manage`, `role.manage`) widens the extension surface, and Standing Principle #1 keeps that shut until v1.2. So an org cannot delegate either without making somebody an owner, and that sentence belongs in the docs rather than in a support ticket.

### A policy is not a query scope

`EntryPolicy` answers about a record somebody already holds. Eloquent never consults a policy while **building** a query, so every place that LISTS entries has to apply the grant itself — review found three: the relation picker's search and label resolvers, the related-records table, and the attach dialog, each of which named titles of a type the same user is refused at the URL.

`Permissions::constrainToViewable()` is that predicate, defined once and applied to all three. It returns **null for unrestricted** — an owner, or the explicit wildcard — and a **list** otherwise, because `whereIn` on an empty list matches nothing, which is the right answer for a user who may view nothing and exactly the wrong one for an owner who holds no grants at all.

It hides relations that exist, and that cost is accepted rather than hidden: an editor may see fewer related entries than the entry has, because a title is the whole of what those views show.

⚠️ **Hiding one inside a FORM turned out to freeze the whole record, which review found.** A relation control is hydrated with every id the entry holds, and Filament validates a select's submitted options through the same label callbacks that were applying the grant — so a withheld label made the id an invalid option, and the editor could not save a title change on a field they were not touching. A permission narrowing one relation silently locked the record.

⚠️ **`publish` is a permission about a TRANSITION, so it is decided from the stored row.** Somebody without it
keeps the `published` option on an entry that is already published — otherwise a copy-editor cannot fix a typo
without demoting the article — and the first version read that current value off the loaded instance. A form
held open across somebody else's demotion therefore kept offering the option, and the rule kept accepting it.
One keyed read makes the answer the row's rather than the request's. ⚠️ And the type is the one the entry will be: a save that retypes a draft and publishes it in the same write asks the destination type's `publish`, which review found being asked of the source. ⚠️ The consequence review described —
the stale form putting the entry back — **did not reproduce**: Eloquent writes dirty attributes, and an
instance loaded as published submitting published writes no status at all. That measurement is pinned in a
test rather than recorded here alone, because it is a fact about the framework that nothing else would notice
changing.

⚠️ **And what counts as `published` is asked case-insensitively, because two of the four engines are.** Under
MySQL's and MariaDB's default collations `scopePublished()`'s `status = 'published'` matches a stored
`PUBLISHED`, so a strict comparison in the guard stood aside for exactly the spelling that publishes the row.
PostgreSQL and SQLite compare case-sensitively, which is why a guard written and proven on SQLite could not
see it — the matrix exists for findings shaped like this one.

⚠️ **And that was still only half of it: the same collations are ACCENT-insensitive.** A stored `publíshed`
matches `status = 'published'` in the database while `mb_strtolower()` leaves it a different string, so the
guard stood aside again — and no predicate written in PHP can enumerate what a given server considers equal,
because that depends on the column's collation. Comparing better is a losing game; constraining what can be
stored is not. `Entry::STATUSES` is now a closed set of three, enforced at the builder so the bulk write and
the quiet paths are covered, and "is this published" has one answer on every engine. The form's option list
carries the LABELS and reads the same vocabulary, because two lists of what a status may be is one list that
drifts.

⚠️ **What that guard cannot see is a raw expression, and the limit is stated rather than hidden.** A joined
update assigning `status` from the joined table — the one shape MySQL allows and this suite asserts — hands
the builder SQL rather than a value, and there is nothing to inspect until the database evaluates it.
Refusing every expression would remove a supported capability to close a hole only a caller writing raw SQL
can reach; the same trade `updateFrom()` documents from the other side. A raw expression may therefore write
any string the column accepts, and `scopePublished()` will read it the way the collation does.

⚠️ **And the form was not the only way into that state, which is the finding the question led to rather than
the one that was asked.** `EntryRevision::SNAPSHOT_ATTRIBUTES` carries `status`, so restoring a version that
was published publishes the entry — an editor holding only `entry.{type}.update` could undo somebody else's
demotion by asking for last Tuesday, through a button with no rule behind it. `Entry::restoreRevision()` now
refuses a restore that would move an entry into the published state without the permission, inside the
transaction and under the lock it already takes. The rule is the same one the form draws — keeping a published
state is not moving into it — enforced on the route a form rule cannot reach.

⚠️ **And a model guard the panel still offers is a 500, not an answer.** Review made the point immediately
after that fix landed: the history table rendered Restore unconditionally, so an editor without `publish`
confirmed a modal and met a server error. The predicate lives on the model as one public method, asked by the
guard that enforces it and by the action that offers it — disabled with a tooltip naming the missing
permission, rather than hidden, because a row whose only action has silently vanished explains nothing. The
browser suite asserts both directions on a seeded article that was published and then pulled back, since no
ordinary row has a published version in its history.

⚠️ **And the vocabulary stood at one door of six, and the transition at one door of two.** Review found
`insertGetId()` — which every creation reaches, quiet or not — and the four arithmetic methods writing a
`status` that `update()` refused: a create or an `$extra` assignment stored `publíshed`, and
`increment('status')` stored a number. The vocabulary is one method asked by all six now, before any
transaction, because it reads the values being written rather than a row. It is the fourth guard this ADR
records being added to `update()` and forgotten at the arithmetic family. The same finding named the other
half: the publication guard compares against a stored row, so a creation had nothing to compare and brought an
entry into existence published for somebody holding `create` alone. Nothing-to-published is the same
transition as draft-to-published, and `Entry::refuseUnpermittedCreationAsPublished()` asks it at the creation
door — reading the type from the row `entry_type_id` names, not from a `type_handle` that a quiet creation
never re-derives.

⚠️ **And a restore is an edit, which the History never asked.** Filament's view page renders a resource's
relation managers, and a custom action inside one carries no authorization unless it declares some — Filament
infers one only for its own named actions — so somebody holding `entry.{type}.view` alone could put an old
version back from a page that never asked whether they may edit. The Restore action now asks
`EntryResource::canEdit()`, the question the edit page asks before it renders, and is hidden rather than
disabled because nothing on any row is that user's to do. Filament refuses to mount a hidden action, so a
hand-built Livewire request meets the same answer as the missing button. The browser suite asserts both as a
seeded `viewer@kitsune.test` holding `view` alone, because the copy-editor's `update` is exactly what makes a
restore allowed.

⚠️ **And two answers assumed a host shaped like the skeleton, which installing the split into a bare Laravel
host showed is not the only host.** Membership was asked through the user model's own scoped query, and a stock
`User` has no scope: its query was `where id = ?`, every user was a member of every org, and a `role_user` row
for somebody who never joined resolved an owner bypass. A model with no registered `OrgMembershipScope` now
resolves nothing — the direction already taken for an `Authenticatable` that is not an Eloquent model — and the
check reads the registered scope rather than the attribute, because `#[OrgScopedThroughPivot]` without
`use EnforcesScope` applies nothing. Separately, `Permissions::userModel()` stood aside only when `filament`
was unbound, which in a real host it never is: with no panel, or none marked default, it threw from
`Role::assignTo()` before writing, so the configured fallback its caller takes on null was unreachable. It
catches `NoDefaultPanelSetException` now. And outside a request it asks Kitsune's own panel before Filament's default — `KitsunePanel::apply()`
records which panel that is — because a console command, a seeder or a queued job has no current panel, and the
default may be a host's other panel, whose provider names another model. What a host must supply is still written down only in the monorepo;
shipping that with the split is #8's.

⚠️ **And the History's restore was not the only edit a viewer could reach.** The related-entries page is
authorized with `viewAny`, and Filament's default action authorization on it covers create, edit, delete and
view — not attach or detach — so both ran for a user holding `view` alone. They ask `EntryResource::canEdit()`
now, the answer the restore action already uses, and the browser suite asserts both halves as the same
view-only user. Separately, a `Role` save vetoed by an application observer left the lifecycle proof armed —
Eloquent returns before either place that disarms it — so a `saveQuietly()` on the same instance could move
`is_owner` past the per-holder audit; `save()` now drops the proof on every exit.

⚠️ **Integer user keys remain a requirement on this branch, and that is now a decision with an issue rather
than an open question.** A non-numeric identifier resolves no grants and no owner bypass, which is the right
floor and the wrong product: `role_user.user_id` lives in the host's migration, so the host already chooses
its type, and core narrowing that choice in PHP contradicts the ADR-020 amendment's own "an identifier is the
host's to choose". Supporting string keys is #91, sequenced after this branch and #86 merge.

⚠️ **Superseded by #91: the key type is the host's.** Core reads a user identifier as the user model's own key
type, through `Permissions::userKey()`, instead of casting it to `int`. An integer-keyed model still refuses
`'5abc'` and a ULID, so the floor above holds where it was written for; a model keyed by ULID or UUID keeps its key
a string through `Role`, `Permissions`, `GuardedOrgMembership`, `RevokesRoleAssignments` and the Roles resource.
The cast was not a harmless narrowing: PHP reads a string's leading digits, so `(int) '01J…'` is `1`, and wherever
it ran it named somebody else. The skeleton keeps `foreignId` on `role_user`, `org_user` and `site_user` and names
`foreignUlid`/`foreignUuid` as the host's alternative. `tests/UlidHost` runs RBAC against that schema in the same
process as the rest of the suite, and the base test case rebuilds the database when the host changes.

⚠️ **A BULK publish is deliberately still allowed**, and that is not an oversight to be swept up with this.
`Entry::query()->update(['status' => 'published'])` is a supported write that this project audits and
versions on purpose (`AuditLogTest` and `recordBulkRevision()` both say so), and it carries no acting
identity to check a permission against. Authorization belongs to the routes people use; the builder's job is
that nothing happens untraced.

⚠️ **And the INSTANCE write does ask, which reverses what the paragraph above concluded.** The sentence that
lost is kept because it was published: I argued the form's stale-read window could not be exploited, since
Eloquent writes dirty attributes and an instance loaded as `published` submitting `published` writes no
status at all. Review's third framing of it steps around that entirely — an instance loaded as **draft**,
with the stored row published while validation ran and demoted again before the save, submits a `published`
that IS dirty, and the write lands. So the transition is decided inside the write now, from the locked row,
and the form's `in` rule is the courtesy that returns a validation error rather than an exception.

What survives from the reasoning that lost is the SCOPE, and it is what makes the two compatible: the guard
asks only of an instance write — `exists` and a key, the same discriminator the stale-row guard uses — so the
bulk write above arrives on a prototype and is untouched. A test asserts that it still works, beside the one
asserting the instance write is refused, because a limitation nobody asserts is a limitation that quietly
becomes a defect.

So a link the record **already holds** keeps its value and loses its title: it renders as `Entry #12 — you may not view this entry type`, which discloses nothing the form did not already hand over, and the id stays in the selection so an unrelated edit saves. The exception is deliberately narrow — **this record, this field, and the field's own target types still apply** — because a forged id must still fail validation rather than reach `EntryRelation::guardTargetType()` as an exception after the entry has saved.

### Administering roles, and where that screen lives

⚠️ **A holder who is no longer a member keeps a label, and loses their name.** `role_user` carries no
membership constraint — the consequence list below says so in as many words — so somebody removed from an org
can keep an assignment that resolves nothing. The form hydrates that id, the org-scoped user query cannot see
it, and Filament validates a multiple select's submitted options through the label resolver: the owner could
not rename the role or change a grant until they noticed the one chip that would not save. The id is named
and the person is not, and only an id that is ALREADY assigned gets that treatment, so it cannot become a way
to add somebody the org cannot see. `FieldValueRenderer::relationLabels()` makes the same trade for the same
reason — the second time this exact shape has appeared, which is what makes it a pattern rather than a bug.

⚠️ **And "already assigned" was not narrow enough, which review found in the next round.** The fallback
filtered `role_user` on the user id alone, so an id holding ANY role — another role, another org — earned a
label; Filament accepts a labelled option and the form assigns every submitted id, so the exception that
keeps a record saveable became a way to add somebody this org cannot see, and a way to ask whether an
arbitrary id holds a role anywhere. It is scoped to the role being edited now: a labelled id is one that role
already holds, and assigning it again is what `assignTo()` is already idempotent about. The pattern survives
the correction and gains a second half — **name the id, withhold the person, and only for the row in hand.**

⚠️ **Issue #84 said the assignment screen would live in the skeleton, and this reverses it on evidence.** The argument was that `role_user` references a `users` table core did not create and must not own — which still holds: **core owns no user model.** What changed is that it does not need one. `Permissions::userModel()` asks the **panel's own auth provider**, which is the same lesson review taught about the membership check: the provider cannot be wrong about which model it loads, and `config('auth.providers.users.model')` was a guess that failed open.

The alternative cost more than it bought. A resource in the skeleton needs a navigation entry; navigation is supplied explicitly by `KitsunePanel` (ADR-012); so letting a host add one means opening an extension point in core **before the extension API exists**, which is exactly what Standing Principle #1 keeps shut until v1.2.

**An org may not lose its last held owner role.** Refused by `Role` itself rather than by the form, because an org that loses its last owner cannot get one back: schema editing and role administration are both owner-only, so the only person who could restore the flag is the one who just removed it. The guard asks whether this change takes the **last held** one rather than whether the result has any — a fresh install mid-seed has an owner role nobody holds yet, and the second question would refuse to let a seeder correct one.

⚠️ **And it was a check-then-act that two requests could both pass, which review found.** "This org still has somebody who can administer it" is a count across `roles`, `role_user` and the host's membership pivot, so no constraint expresses it and there is nothing to fall back on: two transactions demoting the last two held owner roles could each read the OTHER, pass, and commit. Each change is individually safe and the pair locks the org out permanently. `removeFrom()` was worse than the others — its check ran **before** its transaction opened, so whatever it read was released before the write.

The owner reads are now **locking reads inside the write's own transaction**. Both transactions want a lock on the same row set — the org's owner roles — so the second waits and then re-reads; and a locking read is also what makes the re-read CURRENT under MySQL's and MariaDB's REPEATABLE READ, where an ordinary read answers from a snapshot that may predate the demotion this change queued behind. `Site::lockHostClaim()` records the same pair of reasons. The membership query is deliberately **not** locked: locking the host's `users` rows would put this guard in a lock order with every unrelated write to them.

What the suite measures is that the clause is emitted, that the check runs at a transaction depth inside the write, and that the re-read sees another connection's committed demotion. That `FOR UPDATE` makes the second transaction wait is the engine's guarantee, and re-testing it here would be asserting InnoDB.

⚠️ **Only a write of the flag itself is a demotion**, which reading the stored value made necessary to say: an ordinary role in memory while another transaction promotes its row arrives with the stored flag true and the instance's false, and a RENAME was then refused as though it were a demotion. Eloquent's update payload does not contain `is_owner` at all in that case — it preserves the owner rather than removing it. A guard that refuses a safe operation teaches people to route around it, and this is the one somebody meets while trying to fix their own org.

⚠️ **And the guards read the STORED owner flag, not the instance's.** Review found the stale-instance half of the same race: an ordinary role held in memory while another transaction promotes that row to the org's only owner keeps `getOriginal('is_owner')` false, so `refuseIfLastOwner()` returned immediately and the stale instance deleted the row that had just become the org's last administrator. `removeFrom()`'s guard had the same early return on `$this->is_owner`, after which the raw pivot delete runs with nothing behind it. Both read the stored flag under the same lock as the decision now — the rule the whole family of findings produced, applied to the one place where the TIMING rather than the caller was the forger.

⚠️ **The count excludes an ASSIGNMENT, not a person** — the other half of the same review round. A member holding two owner roles who gives one up is still an owner through the other, and excluding them from every owner role reported nobody left and refused a safe removal. Being told "this would lock you out" while demonstrably not is what teaches somebody to reach past the model.

⚠️ **And the count itself asked the wrong model, which is the same finding one layer down.** Membership was
tested through whatever user model the installation resolves — but `role_user.user_id` means whatever table
it REFERENCES, and a host running two panels has two user models on two tables with two sequences. An id
matching an unrelated row made a phantom owner out of a departed holder, and the last real owner's removal
then passed a check that had already been fixed to require membership. `Permissions::roleIdsFor()` refuses
assignments resolved through the wrong model; this count now refuses to answer at all, because the two guesses
are not symmetric — a wrong "somebody is left" locks an org out permanently, and there is no recovery path.

⚠️ **And the SELECTOR was the third place that check belonged.** `roleIdsFor()` refuses assignments resolved
through a model the pivot does not reference and the owner count refuses to answer through one — while the
holder picker went on listing that model's users, and the form assigns whatever ids come back. The panel would
show one name and the grant would land on whoever holds that id in the table `role_user` actually references.
The control is disabled with the reason on it, and `syncHolders()` asks the same question again at the write,
because a disabled control is a rendering decision and the raw form state is submitted by the browser.

⚠️ **And the picker assumed a user schema core does not own**, which review found once the model was the right
one. It searched and ordered on `name` and `email` whatever the table had, so a host whose users carry `username`
met a database error instead of a list. It now asks the table: `name` and `email` where they exist, an exact id
where neither does. People are labelled through Filament's `HasName` when the model implements it — the contract
the panel already names the signed-in user by — so a host describes its users once, to Filament, not twice.

⚠️ **And disabling a control does not stop it NAMING people**, which is the same finding one layer in. The
label resolver still went to that model, so a form nobody could change still stated that an unrelated person
held real authority. The assignment is real and the identity is not available — two different statements — so
the id is shown and the name is withheld.

⚠️ **The role form is one transaction, which it was not.** Filament wraps `save()` and its `afterSave` hook
already; the panel simply does not enable it. So an edit that changed grants and then tried to take the last
owner away committed the role row and every grant, with their audit rows, before `syncHolders()` threw — a
form reporting a failure that had already half happened, which is the same partial-authority-change shape the
deletion observer produced a round earlier. Enabled on these pages rather than panel-wide: changing the
failure semantics of every action in the admin at once is a decision with its own evidence to gather.

⚠️ **And the bulk delete was the same thing on the list page.** Filament deletes selected records one at a
time and `Role::delete()` opens a transaction of its own, so a selection holding a deletable role and then
the org's last held owner role deleted the first — its assignments and its grants with it — and then threw.
The operator is told the operation failed while part of it is permanently gone. `databaseTransaction()` is
Filament's own opt-in for exactly that, and it is off by default.

### Consequence

- **Core's RBAC enforces nothing until the host application has run the skeleton's `role_user` migration.** Already true of org scoping, so it is a pattern rather than a new hole — but it is written down here rather than left in somebody's memory.

- **Deleting a user revokes their assignments through the audited path, or it does not happen.** Review found
  the cascade: `role_user.user_id` references the host's users table, so deleting a user removed every
  assignment they held with no `role.unassigned` row and without consulting the last-owner guard — one
  deletion could leave an organisation unable to administer roles or edit its schema, permanently, with
  nothing in the log. `RevokesRoleAssignments` is an observer the host attaches (`#[ObservedBy]`, the same
  declarative shape as the scope attributes, because core owns no user model); it revokes through
  `Role::removeFrom()` and lets the last-owner guard refuse the deletion outright. The migration's foreign key
  is `restrictOnDelete()` beside it, so a host that has not attached the observer fails loudly instead of
  quietly losing an owner. ⚠️ Soft deletes are deliberately untouched: a trashed user cannot authenticate, so
  the assignment confers nothing, and revoking it would make a restore return somebody with no authority.

- **And removing somebody's MEMBERSHIP is the same authority change, guarded the same way.** Review found
  every guard on this branch protecting one half: `Permissions` requires an assignment AND membership of the
  org, so `$user->orgs()->detach($orgId)` takes the last owner's authority away exactly as removing their
  role would — while firing no model event, consulting no guard and writing no audit row. The surviving
  `role_user` row then resolves nothing and the organisation cannot administer itself. `GuardedOrgMembership`
  refuses that removal, and the host opts in the way it opts into the deletion observer, because core owns no
  user model. ⚠️ A relation rather than an observer, because Laravel fires no events for `attach()` and
  `detach()` — not even with a pivot model; `GuardedBelongsToMany` made the same move for entry relations for
  the same reason. ⚠️ And it refuses only the removal that leaves nobody: an ordinary member's departure, and
  a departure that leaves another effective owner, both go through.

  ⚠️ **The cross-org sweep is ordered, too.** Each iteration of the deletion observer takes an org mutex
  through `removeFrom()`, so two users holding roles in the same pair of organisations could be swept in
  opposite orders and hold each other's rows. `(org_id, id)` is a shared order every sweep follows — the same
  lock-ordering lesson as the owner sweep, one layer out.

  ⚠️ **And membership is authority in both directions, which the guard's first version treated as a refusal
  and nothing more.** Review found the successful paths unrecorded: detaching a role holder took every grant
  they held with no row, and attaching them again gave it all back — the assignments survive — just as
  silently, while a memo taken before either went on answering the old way for the rest of the request. Both
  directions are now one transaction with the org row first, write one row per person under the org the
  membership belongs to (`org.member_removed` / `org.owner_removed`, `org.member_added` / `org.owner_added`,
  owner-ness in the action as for assignments), and drop the permission memo once they commit. Only a role
  holder's membership writes a row, because a member with no role there gains or loses nothing `Permissions`
  resolves. The relation's pairs are sorted by `(org, user)` before any lock, for the reason the sweep is:
  two detaches naming the same orgs in opposite orders held each other's rows. ⚠️ Ordering them turned up a
  worse defect on the same line — a detach with no ids read the user's own pivot column for the org ids, so
  `$user->orgs()->detach()` asked the guard about the wrong organisation and removed the last owner's
  membership with every other.

  ⚠️ **And the guard asked about membership in the wrong place twice more, which review found.** It read
  holders through the user model's membership scope, which constrains to whatever org the *context* names — so
  a detach made from another org, or from a console sweep with no org at all, counted nobody as a member of the
  org being left and let its last owner go. It now asks under that org and gives the caller's context back. And
  a detach of every membership deleted every row the user held by the time of the delete, not the ones it had
  read and checked, so a membership another transaction attached in between went with no lock, no check and no
  audit row. The delete names what was read.

  ⚠️ **And two more doors went around the relation's own methods, which review found last.** Laravel's `sync()` detaches and
  then attaches, each committing alone, so a sync whose attach failed had already committed and audited a removal;
  `sync()` and `toggle()` are one transaction now. And `updateExistingPivot()` wrote the pivot row directly, so
  moving a membership's `org_id` in place skipped every guard above; `org_user` is nothing but its two keys, so a
  key change there is refused with the way to make it.

  ⚠️ **And the sweep is one transaction, which review found it was not.** A user holding roles in two
  organisations could have the first revoked and audited and the second refused by the last-owner guard: the
  deletion failed and the person kept their account while permanently losing authority the refusal existed to
  protect — two individually correct operations, wrong together, for the third time on this branch. What one
  transaction still cannot cover is stated rather than implied: `Model::delete()` opens none of its own, so a
  host that needs the revocation and the deletion to be atomic wraps the call.

  ⚠️ **A trashed org's assignments are still rows.** `Org::query()` excludes soft-deleted orgs, so the sweep
  skipped those roles, the pivot survived, and the restrictive key then refused the deletion with a database
  error rather than an audited revocation or a stated refusal. Resolved `withTrashed()`.

  ⚠️ **And `setOrg()` clears the site**, so a sweep through another organisation left the request with no site
  at all — after which everything site-scoped fails closed and later audit rows lose their attribution. The
  site is captured and restored with the org.

  ⚠️ **The test schema mirrors the reference migration**, because it did not: the fixture cascaded while the
  skeleton restricts, so the suite could never exercise the backstop and would have silently erased an
  assignment the observer missed. A fixture more forgiving than the shipped schema tests a different
  application.

- **RBAC requires integer user keys, and that is a constraint on the HOST rather than a preference.**
  `role_user.user_id` is a bigint foreign key to the host's `users` table, so an installation whose users
  carry UUIDs or ULIDs cannot express an assignment at all. `Permissions` fails closed on it — a non-numeric
  identifier resolves no grants and no owner bypass, rather than coercing to `0` and collecting whatever
  that id holds — and a test pins that direction so the limitation cannot drift into a silent one.

  ⚠️ **The audit columns are strings and this is not, which is deliberate.** `audit_log` records whoever
  ACTED, through any guard and any model, and its insert shares a transaction with the write it records — so
  an identifier it cannot hold costs the write, not just the attribution. An assignment is a row in a pivot
  whose column type the host's schema fixes, and widening it is a change to the skeleton's migration, every
  signature in the assignment path and the holder picker: a piece of work, not a patch, and one that belongs
  to whoever decides whether UUID-keyed hosts are in scope before 1.0.

  ⚠️ **Superseded by #91, and corrected.** Integer keys are no longer required: an identifier is read as the user
  model's own key type, and both the pivot and the audit columns now hold whatever the host's key is. The
  integer-host floor this bullet pinned survives — a non-numeric identifier still resolves nothing there — but the
  reason given for it was wrong. A cast would not have coerced `'018f…'` to `0`; PHP reads leading digits, so it is
  `18`, and the test that pinned the floor now makes user 18 a real member and owner and refuses the impostor. The
  earlier test had no membership scope, so it resolved nothing whatever the key did.
- **`role_permissions` is `#[Unscoped]`, and the reason is the one `EntryRelation` and `EntryRevision` give**: it is reached only through `Role`, which is `#[OrgScoped]` and enforces it. Its index leads with `role_id` rather than a scope key, which satisfies invariant 4 by the invariant's own argument — a role is globally unique and belongs to exactly one org, exactly as a site does.
- **A `role_user` row pairing a user with a role in an org they do not belong to resolves nothing**, because resolution runs through the org-scoped `Role` query under the current org context *and* asks membership of the user model. Asserted from the attacker's side.

  ⚠️ **Membership is asked of the authenticated instance's own class, not of `config('auth.providers.users.model')`** — review found the config lookup failing open. A panel may authenticate through a provider that is not named `users`; the name is the host's to choose. Pointed elsewhere, the check read an unrelated model or found none and took a permissive fallback, and a `role_user` row then conferred grants on somebody who was not a member of the org at all. An `Authenticatable` that is not an Eloquent model now resolves **nothing**, because "cannot check" reading as "allowed" is the wrong direction for the one question with no framework safety net.

- **Every guarantee about the owner flag is enforced at the BUILDER, not only in a lifecycle hook.** Review named the idiom: `Role::query()->update(['is_owner' => …])` dispatches no event, so holders gained the bypass with no audit rows and a memoised answer stayed stale. `GuardedRoleBuilder` refuses a bulk write touching `is_owner` or `org_id`, and refuses a bulk delete outright — deleting a role revokes it from every holder by cascade, and that audit is per holder too. This is the **third** time the project has learned that a guard belongs where the write is: `AuditedBuilder` for entries, `GuardedStorageBuilder` for field storage, this for roles.

  ⚠️ **And `update()` was one door of four, which is the same lesson arriving one API call along.** Review found three more: `increment()`, `decrement()` and their `…Each()` plurals carry an `$extra` map of ordinary assignments and forward straight to the query builder, so `increment('id', 0, ['is_owner' => true])` was a bulk promotion under another method's name; and Eloquent sends `forceDelete()` to the query builder rather than through `delete()`, so the roles and their cascading assignments went with no per-holder revocation audits and no cache invalidation. The arithmetic family is refused **without** consulting the instance-save flag, because no instance save writes through it — `performUpdate()` calls `update()` — so there is no legitimate path there to stand aside for.

  ⚠️ **And the condition that decides whether to ask was itself forgeable.** It required `getKey()` to be
  non-null while the write uses `getKeyForSaveQuery()`, so nulling the attribute in memory after an org
  switch turned the guard off and left `saveQuietly()` updating the row the instance was loaded from. The
  same defect, in the same shape, was found in `AuditedBuilder` in the same round: a guard is only as
  reachable as the condition in front of it, and a condition reading a mutable attribute is one more
  forgeable proof.

  The same hole existed generically: `ScopedBuilder`'s arithmetic overrides checked the scope keys and not `columnsRequiringModelSave()`, so `Site::query()->increment('id', 0, ['base_url' => …])` landed values that `update()` refused, leaving `canonical_host` describing the previous URL. Fixed there rather than copied per builder.

- **A stale instance may not change the owner flag.** `EnforcesScope` revalidates a scope key only when it is *dirty*, so an org A role retained after a worker moved to org B could still be promoted — and the audit row was then written under B, or dropped silently with no context at all. The four authority helpers already refused that; the flag was the fifth way authority changes and was not asking.

  ⚠️ **And a stale instance may not be SAVED at all, which is the same finding one step further out.** The guard above fires only when `is_owner` is dirty, and `EnforcesScope` validates the value being *written* against the current context — so a role loaded under A, with `org_id` then set to B while the context is B, passed everything and **moved**, carrying its grants and its assignments, taking A's last owner with it and conferring authority in B with nothing in the log. The opposite move — one of the current org's roles pushed into another's — is refused by `EnforcesScope::guardScopeKey()`, and `RoleIsolationTest` asserts that rather than this list claiming it. ⚠️ **Its first fix compared `getOriginal('org_id')`, which review found forgeable too** — `syncOriginal()` is public — so this and the five authority helpers all ask `OrgScope`'s own query about the stored row instead, which is the one answer a caller cannot arrange.

- **The builder's proof is consumed by the attempt, however the attempt ends.** `saved` clears the flag that tells `GuardedRoleBuilder` an instance save's guards have run, and an aborted save never reaches `saved` — a failing audit insert in that listener (a context naming a concurrently deleted site is enough) left the instance still claiming it. A caller catching that and retrying through `saveQuietly()`, which fires no listener at all, would have presented the proof for a write nothing checked. Cleared in a `finally` around both `save()` and `delete()`, which is the rule `DerivesGuardedColumns` already records for the four `RequiresModelSave` models.

- **Deleting a role records the revocation for **every** holder, not only an owner's.** The database cascades `role_user` for any role, and a role carrying ordinary grants is authority too, so `role.unassigned` is what its holders lost. ⚠️ **The ORDER was wrong for a round**, and this bullet said so: the rows went in before the delete, which a `deleting` observer returning `false` turned into a false record the transaction then committed. The holders are read first — the cascade takes them with the role — and the rows are written once the delete has succeeded, all inside the one transaction. See the revocation bullet below.

- **Deletion is the fifth authority path and asks the same org question as the other four.** Eloquent's instance delete writes by primary key without reapplying the global scope, so a role that outlived a context switch could be deleted from another org — with its revocation audits attributed to that org.

- **Each authority change and its audit row are one transaction** — including an ordinary `save()`, because the owner audit runs in `saved`, which is after the row commits. With several holders a failure there could leave a **partial** trail, which is worse than none because it reads as complete. Review found the write split in two: under autocommit the grant landed, and an audit insert failing after it — a context naming a site that was concurrently deleted is enough, since `audit_log.site_id` is a foreign key — left the caller with an exception and the authority change in place. `AuditedBuilder` puts an entry's insert and its audit row in one transaction for exactly this reason, and a write that is not an entry needs the same. An unaudited authority change is the thing ADR-020 refuses.
- **A permission check is not a tenancy check, and `EntryPolicy` now makes both.** Review found it asking only about the record's `type_handle`: `SiteScope` constrains the query that LOADS an entry and says nothing about the object afterwards, and an instance update or delete writes by primary key without reapplying it. So in a worker or a multi-site command, a record loaded under site A survived a context switch and a user in site B holding the same `entry.{handle}.update` authorised the write — B's grant spent on A's row, with the audit attributed to B. **An owner was the worst case**, because `isOwner()` answers for the current org and would have said yes about anybody's row.

  The policy therefore asks whether the current scope's own query would return the record — `site_id` matching, or `site_id IS NULL` inside the same org (ADR-021's org-shared case) — and a test pins its answer to the scope's for every shape, because a second encoding of a scope's clause that is allowed to drift is worse than none. It applies to a row that **exists**: an unsaved instance names nothing, and its scope keys are the insert's business, which `EnforcesScope` already guards.

- **`role_permissions` has no public write surface, which `#[Unscoped]` alone did not give it.** The
  declaration's justification is about READS — every read goes through the org-scoped `Role` — and review
  found it covering writes by implication: nothing narrows a direct write either, and the table deliberately
  carries no `org_id` for a clause to narrow. So `RolePermission::query()->delete()` revoked every org's
  grants in one call and `RolePermission::create([...])` attached one to another org's role, with no
  validation, no audit row and no memo flush. The model's own docblock said a reviewer should treat a bare
  query as a defect, which is attention rather than enforcement. `GuardedGrantBuilder` refuses every write
  that did not come through `Role::grant()` or `revoke()` — the paths that ask the org question — and the
  discriminator is a narrow window rather than a per-instance flag, because `firstOrCreate()` builds its own
  instance and a flag armed on the model in hand never reaches it.

  ⚠️ **Twice more before it held.** The first version's window opener was a PUBLIC method on
  `RolePermission`, so the capability stayed public — any caller could hold it open around a write of their
  own. The flag is private static on `Role` now, armed inline by `grant()` and `revoke()` and exposed only as
  a reader. And the builder's docblock claimed the insert-or-ignore family was "already refused by
  `ScopedBuilder` for every model", which is false for this one: `refuseBulkCreate()` returns early for
  anything that is not `RequiresModelSave`, and `guardEveryInsertedRow()` inspects scope keys, of which an
  `#[Unscoped]` table has none. Four doors — `insertOrIgnore()`, `insertUsing()`, `insertOrIgnoreUsing()`,
  `insertOrIgnoreReturning()` — measured open. A claim about somebody else's code is the kind that rots
  quietly, so the write surface is now enumerated in a test that fails when Laravel grows a method it has not
  been taught about.

- **A numeric user id is not an identity.** The memo and the membership check both learned to carry the
  authenticated model's class; the assignment lookup still matched on `user_id` alone. A host running two
  panels through two providers has two user models on two tables with two independent sequences, so both have
  a user 1 — and the second model's user 1 was handed the first's roles the moment they belonged to the
  current org. What says whose ids `role_user` holds is the table its `user_id` **references**, read from the
  schema rather than from a config key, because the skeleton's `constrained()` is what decides. **A host whose
  `role_user` declares no foreign key gives nothing to compare, and that case is allowed rather than
  refused** — breaking RBAC outright on a schema that is merely undocumented would be the worse failure, and
  the narrower exposure is recorded here rather than implied.

  ⚠️ **And the table name alone still conflated two databases**, which review found next: an identity
  connection whose table is also called `users` matched, while `role_user` and the foreign key live on the
  default one — so membership was checked in one database and assignments read from another. The connection is
  part of the comparison now. **The audit target asks the same question**: `assignTo()` writes the
  FK-referenced identity, so resolving the row from the panel's provider could name an unrelated person with
  the same id. A null target beats a wrong name, and the row is still written.

- **The proof that an instance's guards ran is not something a caller can present.** It was a public boolean,
  and `Builder::getModel()` is public — so `$q = Role::query(); $q->getModel()->authorityGuarded = true;
  $q->update(['is_owner' => true])` promoted every matching role with no per-holder audit and no cache
  invalidation. `RequiresModelSave`'s docblock records the same attack on `exists` and `getIncrementing()`,
  measured, twice; this is the third. The proof is now two private facts with no setters: the lifecycle
  listeners record that this instance's guards ran, and `performUpdate()`/`performDeleteOnModel()` record the
  write they ran for. A quiet save has the second and not the first; a hand-armed builder can have neither.

- **Which org a role belongs to is asked of the stored ROW, not of the attribute.** `$role->org_id` is a
  mutable property, so after retaining an org A role, code in org B could set it to B in memory and every
  authority path passed — while the writes they perform go by primary key, so A's grants and assignments
  changed and the audit row named B. `getOriginal()` is no better, because `syncOriginal()` is public too.
  The guard asks `OrgScope`'s own query whether the row this key names is one the current context may see,
  which also refuses a transfer in flight, and which every save of an existing role now asks as well. It
  honours `withoutScopeBecause()`, like every other write guard in the tenancy layer.

  ⚠️ **Amended: it does NOT honour it any more, and the sentence above is left standing because the reasoning
  was published.** `withoutScopeBecause()` suspends the SCOPE and cannot suspend `Auditor`, which derives the
  audit row's org from the context — so an authority change made under the hatch committed with a row naming
  the wrong org, or with no row at all, which is the unaudited authority change ADR-020 refuses outright.
  Nothing in the codebase called it, so nothing needed it: the way to act on another org's role is to
  establish that org's context, which is also what makes the audit true. The rule the two attempts produced
  is narrower than "every guard gets a hatch" — **a hatch is only safe where the thing it suspends is the only
  thing the guard protects.**

- **A policy answers before the write, and an instance may not write over a row that moved since.** Review
  pointed out the window no policy can close by itself: `Gate` runs in one transaction and the write happens in
  another, so a row retyped or moved into another site in between was authorised by the answer for what it used
  to be. **The consequence was measured before the fix was chosen**, because it is narrower than it sounds — a
  stale instance saving an unrelated field writes only that field (`update "entries" set "title" = ?,
  "updated_at" = ? where "id" = ?`), so the denormalised `type_handle` keeps whatever the other transaction set
  and nothing drifts. What is left is one edit, or one DELETION, by somebody authorised for that row a moment
  earlier; the deletion is why this is a guard rather than a documented limitation.

  `Entry::refuseIfTheRowMovedUnderneath()` reads the stored row under a lock inside the write's own transaction
  and refuses when `site_id`, `org_id` or `entry_type_id` differ from what the instance loaded. It compares what
  was LOADED rather than what is being written, so a legitimate retype still works — making the change yourself
  makes the column dirty, and the comparison is against the database. **Authorization proper stays at the panel
  boundary**: re-asking `Permissions` in the write path would need the acting identity, which a seeder, an
  importer and a console command do not have, so the window is closed by refusing the write rather than by
  re-deciding the permission.

  ⚠️ **And revision restore went around it, which review found.** `restoreRevision()` locks the row and calls
  `refresh()` before its save, so an entry moved between the caller's authorization and that lock came back
  carrying its new `site_id` — the originals the guard compares against were already the moved row's, and the
  restore wrote content into a site nobody had authorised it for. It now takes its lock through
  `refuseIfTheRowMovedUnderneath()` itself — by the original key, comparing the type as well as the scope keys —
  after a first fix that copied the guard and left the type out; and it refuses outright a restore through an
  instance whose key has been edited, which had locked one row, checked another's revision and written a third.
  The History button asks the stored row whether a restore would publish, not the instance, so the button and the
  guard cannot disagree across somebody else's demotion.


  ⚠️ **And it went into two doors when there are six.** Review found the arithmetic family walking past it:
  Eloquent sends `$entry->increment()` to `setKeysForSaveQuery($this->newQueryWithoutScopes())->increment()`,
  which is an instance write by the original key, with the scope removed, that never passes through
  `update()`. The guard is one method called from all six now — the same shape as the `is_owner` arithmetic
  finding one model over, which is twice this family has been found through the same door.

  ⚠️ **And the publication guard repeated the mistake one round later.** The transition check went into
  `update()` alone, so `increment('id', 0, ['status' => 'published'])` published without it — Laravel's
  `$extra` map is a set of ordinary assignments, which is the same sentence this file already carried about
  `is_owner`. Three guards have now been added to `update()` and forgotten at the arithmetic family; the
  helper is called from all six doors for each of them.

  ⚠️ **And the condition asked for the wrong key.** `getKey()` decided whether to check while the write uses
  `getKeyForSaveQuery()`, so nulling the `id` attribute in memory turned the guard off and left the delete
  pointing at the row it was loaded from — the tampered instance was the one instance that skipped the
  check. The third time this project has met the original-key rule: `Role`'s edited primary key, the policy's
  `getKeyForAuthorization()`, and now the builder's own condition.

  ⚠️ The discriminator is `exists` and a key rather than `isPerformingModelSave()`, and a soft delete is why:
  `runSoftDelete()` builds its own query and calls `update()` directly, outside `performUpdate()`, so the save
  identity skipped the one case that destroys something. Measured — the soft delete went through while the
  update and the force-delete were refused.

- **An owner transition is audited once per UPDATE, not once per `save()`.** `wasChanged()` outlives the write that set it: Eloquent refreshes `$changes` in `finishSave()`, and a later `save()` with nothing dirty never calls `performUpdate()` — so `$changes` still described the previous write and the transition was recorded again. Measured: one promotion and three no-op saves produced four `role.owner_assigned` rows per holder. A trail that grows every time somebody calls `save()` reports authority changes that did not happen, to whoever is reading the log to find out what did. The model now carries a one-shot proof that an update actually ran, consumed by the `saved` listener whether or not the flag moved.

  ⚠️ **And it is a fact about the ROW, not about the instance's originals** — the same guard, one concurrency
  step further out. Two requests that both load a non-owner role and both set the flag serialise on the lock,
  and the second one writes `true` over `true`: nothing transitions, but `wasChanged()` compares its own stale
  original and says it did, so every holder got a second `role.owner_assigned`. The stored flag is captured
  under the write's own lock now and compared with the value written. A log that reports two promotions where
  one happened fails the same question as one that reports none.

- **`assignTo()` asks whether the assignment already exists INSIDE the lock.** It asked before the transaction, so two requests assigning the same person to the same role both passed, the first inserted, and the second collided with the `(role_id, user_id)` primary key — where the documented behaviour is to be idempotent and silent. "Already holds it" has to be asked where the answer cannot change underneath.

  ⚠️ **And a ROLLBACK undoes the grant without undoing the memo**, which review found one layer out from
  that. `grant()` flushes when its own transaction commits — inside a caller's transaction that is a
  savepoint release, and the outer transaction can still roll back. A check made in between memoises the
  uncommitted grant, nothing flushes it again, and a caller that catches the rollback and carries on keeps
  authorising against a grant that no longer exists. Laravel announces the rollback, so the memo is dropped
  when it happens: cheaper than refusing to memoise inside a transaction, which would cost a read per check
  on every write path for a window that only opens when somebody rolls back and then continues.

  ⚠️ **Inside the lock is not the same as current**, which review found next. Under MySQL's default
  REPEATABLE READ a plain `select` answers from the transaction's snapshot, and inside a caller-owned outer
  transaction that snapshot predates this method — so the role lock makes the second request wait for the
  first to commit, and its non-locking read still cannot see what committed. The check is `lockForUpdate()`
  now, which reads the latest committed version. `insertOrIgnore()` would also be atomic and was rejected on
  driver divergence: MySQL's `INSERT IGNORE` downgrades a foreign-key violation to a warning, so an id naming
  nobody would be reported as "already holds it" while Postgres refused it.

- **The policy's memo carries the type the instance was loaded with**, and the body uses it. Keyed on the row and the scope alone, a request that checked an entry as an article, saw it retyped, and then RELOADED the model got the memoised article handle — and the reloaded instance's originals match the retyped row, so the write guard had nothing to refuse either. An instance whose loaded type no longer matches the stored row is stale, and a stale instance is refused; within one request a stale instance keeps its memoised answer and the WRITE is what refuses it, which is the layering rather than a hole.

  ⚠️ **The site and org too, sent the round after the type.** A row authorised in one site, MOVED to another
  without being retyped and then reloaded produced the identical key, so the first site's handle came back
  with no read — and the reloaded instance's originals match the new site, so the write guard had nothing to
  refuse either. Fixing one column and not the other two was the mistake: the memo now carries every column
  `refuseIfTheRowMovedUnderneath()` compares, because it is one rule and not three.

- **The role row is the mutex for everything that changes its authority.** Each operation was locally
  transactional and the PAIR still lost a row from the trail: an assignment inserted the pivot and read the
  owner flag as false, recording `role.assigned`, while a concurrent promotion could not see the uncommitted
  pivot and audited no holder at all. Both committed, and the person was an owner with nothing in the log
  saying so — the per-person guarantee above, defeated by two individually correct operations. A promotion
  writes the role row, so it takes that lock on its own account; `assignTo()` and `removeFrom()` take it
  explicitly **before** touching `role_user`, and the holders read takes it from the other side so a
  transition waits for an assignment in flight rather than counting past it.

  ⚠️ **And the ORG row is the mutex above it, because the owner sweep locks a SET.** Review found the cycle:
  a demotion locks its own role and then `effectiveOwners()` locks every owner role in the org, so two
  demotions of different roles each hold a row the other wants — the database resolves that as a deadlock
  rather than as one success and one last-owner refusal, which is the serialisation these guards were
  written for, defeated by the ORDER they acquire locks in. One row every such operation takes FIRST turns a
  cycle into a queue, and the org is the natural one: the invariant is "this organisation still has somebody
  who can administer it". ⚠️ Taken from the CONTEXT rather than the role's stored row, because reading that
  would be another role lock before this one — every path has already established the role belongs to the
  current org. ⚠️ And not in `assignTo()`, which locks its own role and sweeps nothing: it cannot be half of
  a cycle, and a lock taken for tidiness is contention with no invariant behind it.

  ⚠️ **The first version of that fix was in the wrong place and the first version of its test could not tell.**
  The mutex went inside the last-owner guard — after `refuseIfNotCurrentOrg()` and `storedOwnerFlag()`, both
  locking reads of the role — so the first role lock was already taken. And the test compared the first org
  lock with the first role lock across the WHOLE test, so a removal's mutex satisfied the assertion on a
  demotion's behalf: reverting the demotion's mutex left it green. Each operation is measured in its own
  window now.

- **A vetoed deletion clears the proof it earned.** `performDeleteOnModel()` clears the guard proof in a
  `finally`, and an application observer returning false means that method is never entered — so the proof
  survived on the instance and a later `saveQuietly()` supplied the other half, after which a quiet write to
  `is_owner` or `org_id` skipped the org check, the per-holder audit and the cache invalidation. The same
  family as every other finding here — a proof outliving the write it was earned for — reached through
  somebody else's veto rather than through a forged attribute.

- **A quiet save is still asked which org's row it is touching.** "Every save of an existing role asks it" was
  true of noisy saves only: `saveQuietly()` and `updateQuietly()` suppress the `saving` listener, and what was
  left refused the per-row COLUMNS alone — so a quiet `name` or `handle` change on another org's role went
  through, written by primary key. The builder asks the row question itself now, for an instance write that
  has lost its proof; a genuine bulk update arrives with a prototype that does not exist and is narrowed by
  the global scope, which is the distinction every guard on this model has had to make.

- **A revocation is recorded only once the deletion has succeeded.** The rows went in first, which read as
  correct until an application observer returning `false` from `deleting` aborted the delete: the role and
  every assignment survived while the log said their authority was revoked, and a retry added another set of
  false rows. The holders still have to be READ first, because the database cascades `role_user` away with the
  role — so the read comes before and the write comes after, inside one transaction.

- **The owner flag and the status are read under every spelling the database writes.** Two guarantees above
  held for one spelling of a column. `GuardedRoleBuilder` compared `last(explode('.', $column))` exactly and
  `AuditedBuilder` looked `status` up as `status` and `entries.status`, while SQLite, MySQL and MariaDB match
  column names without regard to case. Measured before the fix: `Role::query()->update(['IS_OWNER' => true])`
  promoted every role it matched with no per-holder audit, and so did a proven save of `IS_OWNER`, which the
  lifecycle hooks — asking about `is_owner` by name — never saw; `update(['STATUS' =>
  'publíshed'])` stored a value outside the closed vocabulary; and `$entry->update(['STATUS' => 'published'])`
  published an article for somebody holding `update` and not `publish`. Both builders compare through
  `ResolvesWrittenColumns` now, the one comparison ADR-021's amendment of 2026-09-19 describes.

- **We own the resolution cache, the wildcard semantics, and the bugs in both.**

---

## ADR-034 — The skeleton trusts a proxy on its own host, for the client address only

**Status:** Decided · 2026-09-15

Raised while designing stage (an Ubuntu VM behind a Cloudflare Tunnel managed from the dashboard) and alpha (a new
Laravel Forge server at `alpha.kitsunecms.org`). Three request values decide behaviour that matters, and nothing in the
repository configured how any of them is derived:

- Kitsune picks the public Site from `Request::getHost()` (ADR-021, `ResolveSiteFromRequest::resolve()`).
- Filament keys its admin login throttle on `request()->ip()`, and counts every attempt, successful ones included.
- Every absolute URL the admin emits — Filament and Livewire assets, the post-login redirect — follows `isSecure()`.

Laravel's `TrustProxies` runs on every request. With no list it trusts nobody, with three exceptions: it trusts
**every** address when the request's Host ends in `.on-forge.com` or `.on-vapor.com`, or when `LARAVEL_CLOUD=1`.
Measured against the skeleton's own bootstrap, that produced three failures:

- **Behind cloudflared on the same host, every visitor was `127.0.0.1`.** One login throttle bucket for the whole
  internet: any five sign-in attempts within 60 seconds lock the owner out until that window ends, and five a minute
  keep them out.
- **On a Forge server, the vanity hostname every site receives let any client choose its own `ip()`** through
  `X-Forwarded-For`, and rewrite the scheme and prefix of generated URLs (`http://alpha-x.on-forge.com/evil`).
- **The obvious fix is worse.** `trustProxies(at: loopback)` with Laravel's default header set trusts
  `X-Forwarded-Host`, `-Port` and `-Prefix`; a request for `stage-he.kitsunecms.org` resolved as
  `stage-fr.kitsunecms.org`. A forwarded header chose the Site.

### Decision

`skeleton/bootstrap/app.php` calls `trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_FOR)`.

- **The list is a constant, not configuration.** A same-host proxy connects from loopback. A server with nothing in
  front of PHP sees a loopback peer only when a process on that host connects to it, and that process is trusted (see
  *Cost, stated*).
- **Only the client address is taken from a forwarding header.** The scheme comes from the web server: on both
  supported hostnames TLS terminates in nginx — `stage*.kitsunecms.org` behind a tunnel that dials
  `https://127.0.0.1:443`, and `alpha.kitsunecms.org` directly, DNS-only — so PHP already sees `HTTPS=on`. Host, port
  and prefix are never taken from a forwarding header either, from any peer: neither `X-Forwarded-Host`, `-Port` and
  `-Prefix` nor RFC 7239 `Forwarded`. A Cloudflare Tunnel in front of Kitsune must not set `httpHostHeader`, which
  rewrites `Host`.
- **A proxy on any other address** — a load balancer, a container network — must connect from addresses only the
  operator controls, append the connecting address to `X-Forwarded-For` or overwrite it, reach the web server over TLS,
  and have its address resolved there before PHP. For nginx: `set_real_ip_from <that proxy's addresses>`,
  `real_ip_header X-Forwarded-For`, `real_ip_recursive off`. PHP then sees the visitor as `REMOTE_ADDR`. A proxy that
  passes a client's value through lets the client choose it, loopback included, and so `ip()` (realip's step inferred
  from nginx's documentation; `ip()` measured against this bootstrap with that result as `REMOTE_ADDR`). The realip
  module fixes only the address: a proxy that talks plain HTTP to the web server leaves `HTTPS` unset, so every URL is
  `http://`, and that setup is unsupported.
- **Cloudflare's proxy is not such a proxy.** Every Cloudflare account connects from the same ranges, and a Worker on
  any zone, or a Snippet on a paid one, can set the forwarded address of a request to that zone's origin (inferred from
  Cloudflare's documentation, not measured). realip over those ranges would let another account choose `ip()` wherever
  its request reaches the site: through a web server that answers names it does not serve, or when that account is on
  Enterprise, whose Origin Rules can override `Host` (documented). So `set_real_ip_from` never lists Cloudflare's
  ranges, and a hostname in the operator's own zones that Cloudflare proxies reaches Kitsune through a tunnel on the
  same host, as stage does.
- **The `on-forge.com` name Forge gives every site must not reach Kitsune through Cloudflare.** Cloudflare's edge
  proxies it to the server: measured, it resolves to Cloudflare addresses, answers with `server: cloudflare`, and
  presents one shared `*.on-forge.com` certificate. Laravel's automatic trust used to trust every peer there, so the
  leftmost, client-written `X-Forwarded-For` entry became `ip()` and a forwarded scheme was believed. Under this ADR
  that name would see a Cloudflare edge address as `ip()` (inferred, not measured at the origin), and its scheme depends
  on an edge-to-origin hop Kitsune does not control. It is in Forge's zone, not the operator's, so no tunnel can front
  it (inferred: a tunnel's public hostname has to be in a zone on the tunnel owner's account). Forge's changelog says
  the domain "can be disabled" but not what disabling changes, so the requirement is that a request for the name through
  public DNS, which reaches Cloudflare's edge, does not reach the site. A request sent straight to the server with that
  `Host` never passes through Cloudflare and is trusted for nothing: `ip()` is its own address (measured against this
  bootstrap).

### Why not configuration

⚠️ **`env()` cannot supply it on a web request.** There the `withMiddleware` closure runs when the HTTP kernel is
resolved, before that kernel's bootstrappers load `.env`: measured, `env()` was null there with a `.env` present and no
config cache, so `trustProxies(at: env(...))` silently trusts nothing and leaves the vanity-host trust-all on. A feature
test boots the console kernel first, which loads `.env`, so a test of that call would pass while production trusts
nothing.

**`config('trustedproxy.proxies')` could, and is not worth it.** It needs the `config/` directory the skeleton
deliberately does not have, it cannot narrow the header mask, and no target environment varies the value.

**nginx realip alone was rejected** as the fix for stage. It leaves the `.on-forge.com` trust-all on every Forge
install, and it leaves the default header mask one `trustProxies()` edit away from choosing the Site.

### Why `X-Forwarded-For`, and nothing else

- For a request its edge proxies directly, Cloudflare **appends** the connecting address to `X-Forwarded-For`
  (documented). cloudflared adds nothing and forwards what it receives (read in cloudflared's source), so a same-zone
  Worker or Snippet subrequest can set that value.
- Symfony drops trusted addresses anywhere in the chain and returns the rightmost untrusted one. A client-written
  left-hand entry — `6.6.6.6`, or a forged `127.0.0.1` — is never the answer.
- **`X-Forwarded-Proto` is not needed, and trusting it would cost something.** Neither target relies on it, and a
  trusted forwarded scheme lets any process that reaches PHP over loopback choose `isSecure()` and the scheme of the
  URLs its request generates: measured, a forged `X-Forwarded-Proto: http` from loopback made an `HTTPS=on` request
  insecure. cloudflared dialing the web server over plain HTTP is therefore unsupported, and fails visibly, with
  `http://` URLs, rather than silently trusting a forwarded scheme.
- No combined preset fits: `HEADER_X_FORWARDED_AWS_ELB` also trusts Proto and Port, and `HEADER_X_FORWARDED_TRAEFIK`
  trusts Host, Proto, Port and Prefix.

| Rejected | Why it lost |
|---|---|
| `trustProxies(at: loopback)` with the default header set | `X-Forwarded-Host` chose the Site; `-Port` and `-Prefix` rewrote every URL. Measured. |
| `trustProxies(at: loopback)` for `X-Forwarded-For` and `-Proto` | No target needs the forwarded scheme, and any loopback process could choose `isSecure()`. Measured. |
| `trustProxies(at: '*')` | Trusts every address, so the leftmost, client-written `X-Forwarded-For` entry became `ip()` even through the tunnel; with the default mask the client also chose the scheme and the host, and so the Site. Measured. |
| `trustProxies(at: env(...))` | Null when a web request resolves the kernel, and a test loads `.env` first, so no test would catch it. Trusts nothing and keeps the trust-all. |
| A `config/trustedproxy.php` | Reintroduces `config/`, cannot narrow the headers, for a value nothing varies. |
| nginx realip with no application change | Leaves the Forge vanity-host hole and the default mask in place. |
| A `kitsune.trusted_proxies` setting in core | A core provider boots after the skeleton's `withMiddleware` closure, so its default would silently override an installation's own line — for a value no target environment varies. |
| nginx realip over Cloudflare's ranges | Every Cloudflare account connects from them, so another account's Worker could choose the address wherever its request reaches the site (see *Decision*); with `real_ip_recursive on`, nginx also walks left into client-written entries when the appended address is itself a Cloudflare address. Both inferred. |

### Cost, stated

- ⚠️ **An install on Laravel Cloud loses Laravel's automatic trust.** Cloud does not document whether its proxy reaches
  PHP with `HTTPS` set or keeps the client's `X-Forwarded-For`, so the effect there — possibly `http://` URLs, or one
  shared address for every visitor — is inferred, not measured, and such an install reviews this line. The skeleton
  targets self-hosting (ADR-026); the trade is deliberate.
- ⚠️ **Every process on the host that can reach PHP over loopback is trusted for `X-Forwarded-For`.** A local client — a
  health check, a script, `curl http://127.0.0.1` — sets `ip()` by sending the header, which gives it nothing it could
  not already do. That trust must not reach anyone who cannot already run a process on the host. Symfony takes the
  rightmost `X-Forwarded-For` entry that is not a trusted address, so on every request a relay on the host forwards on
  another client's behalf, that entry has to be the client's address, written by whatever accepted the client's
  connection. A relay that passes the client's header through breaks that, and so does a loopback hop anywhere before
  the entry is written: a TCP relay in front of an appending HTTP proxy leaves the client's own entry as `ip()`, and an
  overwriting proxy behind cloudflared makes every visitor `127.0.0.1` again (measured end to end against this bootstrap
  through a local TCP relay and HTTP proxy, with PHP's built-in server in place of nginx and a cloudflared-shaped header
  in place of cloudflared). On the host, Kitsune therefore supports two shapes: cloudflared dialing the web server
  directly, where that entry is the one Cloudflare's edge appended, and no relay at all. A proxy on another address is
  the realip case in *Decision*. Anything else that relays other clients' connections to the web server from the host is
  unsupported, SSH port forwarding included. A login restricted by `ForceCommand`, a `command=` key or a certificate's
  `force-command` still forwards a client's header to loopback (measured locally with OpenSSH 10.3p1; `ChrootDirectory`
  inferred), and OpenSSH has more such paths than any list keeps up with. So the supported hosts refuse SSH forwarding
  for every login with `DisableForwarding yes`. It refused the forward for a shell login, for a `force-command`
  certificate, under a `Match` block setting `AllowTcpForwarding yes`, and when it was set only in an `Include`d file; a
  `Match` block setting `DisableForwarding no` undoes it (all measured locally with OpenSSH 10.3p1). An operator who
  wants SSH port forwarding on a host reviews this line. Before this ADR, a loopback peer's `X-Forwarded-For` was
  honoured only on a vanity host or under `LARAVEL_CLOUD=1`, and then from every peer.
- ⚠️ **A proxy on another address still needs the web server's help.** Without nginx realip, visitors arriving through
  the same proxy address share a login-throttle bucket; unless the proxy reaches the web server over TLS, every URL is
  `http://`. Cloudflare's proxy must not have its address resolved, because every account shares its ranges, so a
  hostname it proxied without a tunnel would share buckets by edge address (inferred). That is why the operator's own
  proxied hostnames use a tunnel, and why the `on-forge.com` name, which no tunnel can front (inferred; see
  *Decision*), must not reach Kitsune through Cloudflare.
- **An IPv4-mapped loopback peer (`::ffff:127.0.0.1`) is not loopback.** A web server in front must not listen on a
  dual-stack socket (`ipv6only=off`), or the shared bucket silently returns.
- **An installation owns its `bootstrap/app.php` after `create-project`.** One with a different topology changes
  this line there.

### Enforced by

`tests/Core/Http/SkeletonProxyTrustTest.php` drives the real `skeleton/bootstrap/app.php` in a child process and
asserts:

- behind a loopback proxy, `ip()` is the address the proxy appended;
- the scheme follows the web server's `HTTPS` and ignores `X-Forwarded-Proto`, even from loopback;
- host and root ignore `X-Forwarded-Host`, `-Port`, `-Prefix` and RFC 7239 `Forwarded` from the trusted peer;
- no other peer is trusted, on a `.on-forge.com` host included;
- `LARAVEL_CLOUD=1` does not re-enable trust-everything.

Every case but the scheme case fails against the bootstrap before this ADR. The scheme case fails against a mask that
adds `X-Forwarded-Proto`, and the host-and-root case also fails against Laravel's default mask and against a mask that
adds `Forwarded`.

**Not enforced by anything yet, because they are properties of a host and not of Kitsune.** The suite cannot check
them and no committed deploy script or runbook does either;
[#111](https://github.com/adamgreenwell/kitsune/issues/111) tracks committing one that does. Each item states what must
hold. The runbook is to test each against the running services wherever it can, because checks written against
configuration text kept passing hosts that still broke an item.

- the web server passes its `$remote_addr`, `HTTPS` and the one `X-Forwarded-For` it received to PHP. A client's
  `X_Forwarded_For` header reaches PHP as a second `HTTP_X_FORWARDED_FOR` under `underscores_in_headers on` or
  `ignore_invalid_headers off` (read in nginx's source), and PHP-FPM keeps the later of the two (measured). A
  `fastcgi_param HTTP_X_FORWARDED_FOR` instead replaces the header nginx received, so PHP sees only the param's value,
  or no `X-Forwarded-For` at all when the param is literally empty or an `if_not_empty` value turns out empty (read in
  nginx's source);
- every cloudflared process on the host runs this one tunnel, dials the web server itself at `https://127.0.0.1:443`,
  and never sets `httpHostHeader`;
- the tunnel routes its hostnames to that service only, with no bastion, `tcp://` or socks-proxy service and no
  private-network route that can reach the host's loopback, and no Worker in the account is bound to the tunnel, through
  a Workers VPC Service or a VPC Network (documented; Workers VPC is in beta);
- no Worker or Snippet on the zone, on any route or path, fetches the origin with an `X-Forwarded-For` value it copied
  from the client or chose itself, or without the entry Cloudflare's edge appends (a Worker sees no `X-Forwarded-For`
  on the request it receives, documented);
- on every tunnel hostname, the request Filament's login throttle reads `ip()` on, the Livewire update POST the login
  form submits (read in the vendored sources), reaches the web server with the requester's address, as Cloudflare saw
  it, as the last `X-Forwarded-For` entry (for an IPv6 visitor, a pseudo-IPv4 address if Pseudo IPv4 overwrites
  headers, documented);
- a proxy on any other address in front of the web server connects from addresses only the operator controls, appends
  the connecting address to `X-Forwarded-For` or overwrites it, reaches the web server over TLS, and is resolved with
  nginx realip: `set_real_ip_from` lists only that proxy's addresses, `real_ip_header` is `X-Forwarded-For`, and
  `real_ip_recursive` is off. The address realip resolves is never `127.0.0.1` or `::1`, so nothing on that proxy's host
  relays other clients to the proxy over loopback, SSH port forwarding included. A host with no such proxy has no
  `set_real_ip_from`;
- every hostname in the operator's own zones that Cloudflare proxies to this host is a tunnel route, and
  `set_real_ip_from` never lists Cloudflare's ranges;
- a request for a Forge site's `on-forge.com` name through public DNS does not reach Kitsune;
- nginx never listens with `ipv6only=off`;
- nothing on the host but that tunnel's connector relays other clients' connections to the web server, directly or
  through another relay;
- sshd, as it is running, refuses port forwarding for every login on every address and port it listens on, sessions
  opened before its last reload included; the supported hosts set `DisableForwarding yes`.

---

## ADR-035 — A release is built by one script both servers run, pinned to the commit stage rehearsed

**Status:** Decided · 2026-09-15 · **Amended 2026-09-17** — the path repository's stated reason expired when `kitsune/core` was published; the decision did not, and the reason that always mattered is now written down

Stage rehearses alpha (#111). Stage is an Ubuntu VM with a zero-downtime layout built by hand; alpha is a Laravel Forge
site with zero-downtime deployments. If the two servers built a release differently, a green stage deploy would say
little about alpha, so the build is one committed file.

### Decision

`deploy/release.sh` builds one release in place, from the root of a fresh checkout, before it is activated. Stage runs
it from `deploy/stage-deploy.sh`, and alpha from the Forge deploy script quoted in its header. Activation is not its
job.

- **The release is pinned.** `DEPLOY_SHA` is a required input, and the checkout must be exactly that commit with no
  tracked file changed. Stage checks out the sha it deploys. Forge's `$CREATE_RELEASE()` clones the branch tip
  (inferred: its docs do not say which commit it checks out), and the deployment hook's `sha` parameter, which Forge
  passes as `FORGE_VAR_SHA` (documented), names the commit stage rehearsed. Forge's "Deploy Now" and push to deploy
  carry no sha, so they deploy nothing.
- **Nothing a public deploy must not do.** No `--seed` or `db:seed`: the skeleton's `DatabaseSeeder` creates accounts
  whose password is `password`, and ADR-026 forbids a default administrator. No `migrate:fresh`, which drops every
  table. No `key:generate`: a new key invalidates every session and everything encrypted with the old one. The operator
  writes the shared `.env`, and the script never creates one.
- **The environment is judged by what Laravel loads,** not by the text of `.env`: `APP_ENV=production`, debug off, an
  https `APP_URL`, secure session cookies, `pgsql`, a working `APP_KEY`, and no `LARAVEL_CLOUD` in any form, because
  nothing on these servers has a reason to set it. On `1` Laravel runs its Cloud bootstrappers and changes how scheduled
  commands handle output (read), and ADR-034's explicit proxy list already replaces the proxy trust it would switch on.
  A variable in the deploy environment wins over `.env`, so it is judged too.
- **Composer installs with `--no-scripts`,** and the script runs `package:discover` itself once the `.env` has parsed.
  phpdotenv's message for a malformed line quotes the value, and Composer's scripts would boot Laravel into the
  deployment log before anything proved the file parses (measured). A parse failure reports the line, never the message.
- **The first boot is the environment check,** which names a boot exception's class and where it was thrown, and
  withholds its message, because a provider's can quote configuration. Artisan prints that message, so nothing runs
  artisan before this check, `package:discover` included. The boot builds the missing package manifest itself (read), so
  package providers boot under the same redaction.
- **kitsune/core is a copy of this checkout's `packages/core`,** installed through a path repository with symlinks off.

  > ⚠️ **Amended 2026-09-17 — the stated reason expired, and the decision did not.** This read "because it is not on
  > Packagist yet (#8)". It is now: `kitsune/core` v0.1.0 publishes from `Kitsune-CMS/core`, and #8 is closed. The path
  > repository stays, for the reason that was always the stronger one and was never written down: **a release pinned to
  > a commit must install that commit's core.** `DEPLOY_SHA` names what stage rehearsed, and resolving `kitsune/core`
  > from Packagist would install whatever the constraint resolves to at deploy time — a different build from the one
  > that was proven, and one that could not be built at all before its tag was published. Step 7 already refuses a
  > release where core arrived as anything but a copy; that check is what this reason is enforced by.
  >
  > What genuinely changes now that the package is published: a consumer who is not this repository — the skeleton in
  > someone else's application, a plugin author — installs `kitsune/core` from Packagist like any other package. That
  > was the point of #8. It is not the deploy path's problem.
- **Caches are built one command at a time:** `config:cache`, `event:cache`, `route:cache`, `view:cache` and
  `icons:cache`. Never `optimize` or `filament:optimize`, which exit 0 when one of their tasks fails (read). A
  provider-registered optimize task the script does not know is refused.
- **Compiled views stay in the release.** `view:cache` runs `view:clear` first (read), which in the shared storage would
  delete the live release's compiled views while it serves them. `VIEW_COMPILED_PATH` points this release's cached
  config at its own `skeleton/bootstrap/cache/views`, which goes when the release is pruned.
- **The release proves it serves before the schema changes:** the config, route and event caches exist, the environment
  holds against the cached config, and `GET /up` through the HTTP kernel answers 200. Only then does it migrate. The two
  steps that need the new schema come after: `kitsune:audit-patterns --strict` and `kitsune:schema-sync`, the latter as
  a report.
- **Stage activates as Forge documents:** a link to the new release, the newest four releases kept, and no PHP-FPM
  reload, which Forge documents as unnecessary for zero-downtime deployments. The rest is stage's own choice: one
  `rename(2)` of a new link over `current`, a lock against overlapping deploys, and never pruning the active release.
  A failed or interrupted run removes its release only when `current` does not name it. Bash runs a trap only after
  the interrupted command returns (read), so a flag set after the rename could still say "not activated" once the
  rename had happened. That Forge's own activation behaves the same is inferred until the first alpha deploy.

### Rejected

| Rejected | Why it lost |
|---|---|
| A build written separately for each server | A green stage deploy would not describe alpha's. |
| `php artisan optimize` | It exits 0 when a task fails, and it runs `filament:optimize`, which docs/architecture.md skips. |
| Composer's scripts during install | They boot Laravel before the `.env` is proven to parse, and the parser's message can print a secret. |
| A composer.lock saved on each server | Each server would freeze at its first resolve, and stage and alpha would drift apart unseen. Both logs print the PHP and Composer versions instead. |
| Compiled views in the shared storage | `view:cache` empties that directory while the live release serves from it. |
| Refusing to deploy while the site is in maintenance mode | The marker is shared, so the new release activates down anyway, and fixing forward is why one deploys then. |
| Reloading PHP-FPM on stage by default | Forge documents a reload as unnecessary for zero-downtime deployments, and a reload would hide a stale-path problem alpha would show. |

### Cost, stated

- ⚠️ **Alpha deploys a rehearsed commit only while it is still the tip of its branch.** Promote to alpha before anything
  else merges, or re-stage the new tip.
- ⚠️ **Migrations run before activation, and a rollback does not revert them.** A gate that fails after migrate leaves
  the new schema under the old release, so Kitsune's migrations have to stay compatible with the release before them.
- ⚠️ **An `.env` edit changes nothing until a redeploy,** because each release serves the config it cached. On alpha, a
  redeploy of the live commit works only while that commit is still the branch tip, so rotating a secret after `main`
  has moved deploys new code.
- **These scripts check none of ADR-034's host conditions.** They stay unenforced, and #111 still tracks the runbook
  that checks them.
- **Each release resolves dependencies fresh,** so stage and alpha can resolve differently when a release lands between
  their deploys.
- **Unverified until the first alpha deploy:** which commit `$CREATE_RELEASE()` checks out; that Forge's release is
  under `$FORGE_SITE_ROOT/releases/`, keeps `.git`, and belongs, with the site root and `.env`, to the deploying user;
  and that Forge's activation matches stage's.
- **shellcheck does not run** on either script yet.

### Enforced by

`tests/Core/Release/ReleaseScriptTest.php` and `tests/Core/Release/StageDeployScriptTest.php` run the real scripts
against throwaway checkouts and site roots. They assert the exact step order; the refusals of a bad commit, checkout,
input, ownership or environment; the compiled-view path, and that a new release neither writes nor deletes a view in the
shared storage; that a boot failure's message never reaches the log; atomic activation, and that a TERM arriving during
the rename leaves the release `current` names; the lock; and retention. PHP is stubbed for every step except the three
application checks, `package:discover` and the four framework caches, which run on real PHP against a minimal fixture
app that boots neither Filament nor Kitsune, so migrate and Kitsune's gates never run for real. No test provokes a failed link, an unreadable `.env`
behind the shell's own check, the internal check-mode guard, a PHP really missing an extension, or stage's check that
the clone checked out `DEPLOY_SHA`.
---

## ADR-036 — Chat support runs as its own service, and Kitsune talks to it over the network

**Status:** Decided · 2026-09-17 · **Enlarges v1.1 (ADR-011); the estimate moved with it**

Support chat is wanted for the two real installs driving this platform: a trade publisher whose readers ask about a
subscription, and a marketplace whose buyers and sellers ask about an order. Those scenarios are the maintainer's and are
not in this repository, so nothing below argues from them by number — what they contribute is the requirement, which is
that a signed-in person can reach support from a Kitsune site and an agent can answer.

Wayfindr is already that product: live chat, cobrowsing, ticketing and a help centre, `AGPL-3.0-or-later` for its server
and MIT for its browser widget. So the question was never whether to build chat. It was where its code runs.

| Rejected | Why it lost |
|---|---|
| Port Wayfindr's server into Kitsune's process | AGPL §5(c) makes a combined program AGPL unless the copyright holder relicenses first, and ADR-004's open-core line cannot absorb that. It is also 91,198 lines of application PHP and 119 Blade views, kept by hand against a product that has moved 817 commits since its last release, and none of it could ship before v1.2's API freeze. |
| Extract a shared support domain both hosts consume | There is no domain layer to extract: a message is created in six separate places. **Licensing is not the obstacle** — Wayfindr's own ADR 0001 already reserves `packages/laravel-sdk` under MIT for exactly this, on the reasoning that integration packages should be permissive. The obstacles are that the package is an empty placeholder, and that its ports would be designed against one real host and one imagined one. |
| Build chat inside Kitsune instead | Duplicates a working product to avoid a network call, and puts a second realtime stack inside the floor ADR-027 defends. |

⚠️ **Commercial interest, disclosed per ADR-023.** Wayfindr is the maintainer's other product. This decision makes it
Kitsune's only supported chat path, which is an adoption route into it, and the interests are not symmetric: KaaS could
operate that second service for an org, while a self-hoster provisions a database server, queue, scheduler and
realtime server themselves — the asymmetry ADR-027 exists to watch, pointing the same way ADR-026 already discloses. It
is decided anyway because building a second chat product is the worse engineering answer, and because the alternative
that avoids the conflict — no chat at all — serves nobody. The asymmetry is named here rather than left to be found.

### Decision

**Wayfindr runs as its own application** — its own database, queue, scheduler and realtime server — and Kitsune requires
none of them. ADR-027's floor is untouched for an install that does not want chat, and an install that does adds a
second service rather than a second set of requirements.

**It runs a published release, unmodified.** ⚠️ That rule is not satisfiable today and the gap is the schedule, not a
detail, and it has two halves that must not be confused. **Built but unreleased:** the API writes and the outbound
webhooks this module would call exist on `main` and are in no published release — Wayfindr's only one is `v0.7.0`
(25 August 2026), which `main` is 817 commits past, and which predates both. **Not built anywhere:** signed visitor
identity and per-subject erasure and export, the two gaps named at the end of this ADR. So waiting for a release is
necessary and not sufficient: a release closes the first half, and upstream development has to close the second. Every
claim here was read at `main` `13541b3d`. Running a fork in the meantime would take on AGPL §13's source-offer duty for
a service Kitsune's users reach over a network.

**`kitsune/support` will be the only Kitsune-side code**: a first-party module that links a Site to a Wayfindr site,
puts the widget on that Site's public pages, mints the visitor identity, receives webhooks, and fans erasure and export
out to every mapped site. None of it exists, and two things it needs do not exist either — the v1.1 theme layer it would
inject through, and encrypted module settings for the secrets it would hold.

**Kitsune never stores a transcript.** It stores references: the Wayfindr site id, support codes, and a per-site subject
key, `HMAC(K_subject, <the Site's stable identifier> ":" <the reader's stable identifier>)`, with `K_subject` held only
by Kitsune. Wayfindr therefore cannot link one reader across a publisher's brands, and Kitsune can, which is exactly
what an erasure request has to reach. The join is never Wayfindr's own visitor id: its identity merges re-anchor a
visitor's rows onto a surviving id and record the old one as an alias, so that id answers a lookup without being a
stable key to store.

⚠️ **Two things that formula needs, and neither exists.** `sites` has an auto-increment `id`, an org-unique `handle` and
a globally-unique `slug`, and no immutable public identifier: a slug can be renamed, and renaming one would silently
re-key every subject derived from it. And `K_subject` cannot be rotated in place — rotation means re-deriving and
re-mapping every subject on both sides, and losing it means erasure can no longer be targeted at all, which makes it
backup-critical in the way `APP_KEY` is. The module chooses and records both before it writes a single subject.

**Identity crosses the boundary signed, and only one way.** Kitsune signs a short-lived token for the reader it has
authenticated, on the Site that resolved the request, and Wayfindr verifies it before binding the visitor. The reader
model that makes this possible is ADR-037.

**Agents work in Wayfindr's own dashboard, and Kitsune is neither an identity provider nor a host for Wayfindr's UI.**
The admin links out rather than embedding: `e2e/admin.spec.js` holds the admin pages it visits to requesting nothing
from another host, and ADR-027's floor names external services as something core does not require. Making Kitsune an
OIDC provider would also be new public surface before v1.2, which CONTRIBUTING forbids.

**The module is free**, under Kitsune's own `MPL-2.0`. ADR-004 says the free/paid line is declared once and never moves,
and this is that declaration. The value sits in Wayfindr, which is AGPL and free, and in an MIT widget, so a paid thin
client would be trivially reproducible anyway.

**Promotion into core is closed, not merely unplanned.** Wayfindr needs a database server and always-on workers, and
ADR-027 says core may require neither for a default single-site install. Promoting chat into core would mean amending
that ADR, which this one does not do.

**Erasure is not shipped as the operator's problem.** ADR-020 rejects leaving compliance to the operator because it
ships a legal liability to every self-hoster, and a first-party module that cannot erase would do exactly that. So the
module is not released as generally available until a subject's erasure and export reach the second service. Anyone
running it before then — the maintainer, on the two real installs — is told plainly, in the module and in its docs, that
chat-side erasure and export are manual, and that gap is a release blocker rather than a footnote.

**Scope is customers talking to staff.** Buyer-to-seller and dispute threads are not this: a Wayfindr agent sees every
conversation on a site they support, and a ticket has a single requester, so per-transaction visibility and legal holds
belong to a marketplace module rather than here.

### What would reopen the shared package

Any one of these, and none is a schedule:

1. After Wayfindr's erase, export and provisioning APIs ship, network fan-out proves unworkable in practice for a
   multi-site publisher.
2. Evidence justifies promoting chat into core, which would mean amending ADR-027 first.
3. Wayfindr passes 1.0 and a second real host needs its domain in-process.

### Open, and deliberately not decided here

- **How the widget reaches a page.** Server-rendered into the theme's markup, or loaded client-side. The first puts a
  per-reader token into a cacheable document; the second costs a request and needs the token fetched from a same-origin
  endpoint. The theme layer and Kitsune's caching (both v1.1) decide this together, and neither exists.
- **What the site does when Wayfindr is unreachable**, for the widget and for the webhook receiver's replay.
- **Which identifier is stable**, per the warning above.

### Enforced by

**Nothing, and saying so is the point.** No module exists; Phase 3's registry, manifest and settings store come first,
and the paragraphs above are a decision rather than a description. AGENTS.md #14 exists because consequences written
before the code read as done.

When it lands, these are the claims a test can hold: core's manifest names no Wayfindr package; a Site with no link
renders no widget; nothing is injected without a consent record or under `Sec-GPC`; the webhook receiver verifies a
signature over the raw body before parsing and sets the site context before any scoped write; and an erasure reaches
every mapped site and records a pseudonymous receipt.

⚠️ **Two things this ADR depends on do not exist in Wayfindr**, read at `13541b3d`. It neither signs nor verifies a
host's statement about who a visitor is: `external_id` arrives from the browser as a plain field, and the first visitor
to present an unclaimed value keeps it. And it has no per-subject erasure, and no subject-access export — a dashboard
CSV of visitor directory rows exists, which is a contact list rather than a subject's conversations, messages, tickets
and attachments. Both are upstream work this integration waits on, and an ADR that assumed them would be describing a
product that does not ship.

---

## ADR-037 — A reader is not a panel user, and gets a guard of their own

**Status:** Decided · 2026-09-17 · **Enlarges v1.1 (ADR-011); the estimate moved with it**

Kitsune has no concept of a person who is not staff. The scenarios driving the platform need one: readers who register
for gated downloads and manage subscriptions in one place, buyers and sellers, and the signed-in visitor chat recognises
(ADR-036). The skeleton's `User` is the panel user, and its `canAccessPanel()` returns `true` for every row, so a reader
stored there is one missing check away from the admin.

| Rejected | Why it lost |
|---|---|
| A flag or role on the panel user | The protection would be one `canAccessPanel()` edit, and the blast radius is the whole admin. RBAC assignment is per org (ADR-033) and a reader holds no org role, so the two populations do not share the question the table exists to answer. The reader population is also the larger one by orders of magnitude, sitting in the table the admin authenticates against. |
| A reader model in core | Core owns no user model and does not need one (ADR-033): `role_user` references a `users` table core did not create, and `Permissions::userModel()` asks the panel's own auth provider instead. The same reasoning applies unchanged. |
| Defer readers; offer anonymous chat and no accounts | Registration, a preference centre, gated downloads and recognised chat all reduce to this one missing concept, and it does not get smaller by waiting. |

### Decision

**Readers get their own guard, provider and model, provided by the host**, exactly as the panel user is. Core owns none
of them.

**The host declares which guard is the reader guard; core never guesses.** This is the one mechanism the panel-user
precedent does not hand over: Filament tells core which panel is handling the request, and `Permissions::userModel()`
asks *that panel's* provider for its model. Nothing announces a reader guard the same way, and ADR-033 already records
what guessing costs — a `config('auth.providers.users.model')` fallback that failed open on a host not shaped like the
skeleton. So the host declares the guard explicitly, and core fails closed when nothing has: no declared reader guard
means no reader, not a guessed one.

**A reader cannot reach a panel.** The reader guard is not a Filament guard, and `canAccessPanel()` stays a question
only a panel user is ever asked.

**One identity per org, and everything else per site.** A reader is one row that belongs to exactly one org, so a
publisher's brands are one account rather than one per brand, while consent, subscriptions, entitlements and profiles
hang off it per site. That makes a reader `#[OrgScoped]`, carrying its own `org_id` — **not** the panel user's
`#[OrgScopedThroughPivot]`, which exists because staff membership is many-to-many and one person may work for several
orgs. A reader deliberately may not: an identity that spanned orgs would cross the customer boundary the attribute
table calls the one with no safety net.

⚠️ **One identity is not automatically one session, and how far a session reaches depends on the URL strategy.**
ADR-021 lets a Site be a path prefix, a subdomain or its own domain (`sites.url_strategy`). A cookie already spans
path-prefixed Sites on one host, and sibling subdomains can share one set on the parent domain; only Sites on unrelated
domains need anything more. So "manage every brand in one place" is satisfied outright for the first two shapes, and
only the third raises single sign-on — a separate decision with its own cost, which this ADR does not make and which
v1.1 should not build machinery for before it knows which shape an install uses.

**Kitsune is not the customer record.** Where an audience platform is the custodian, Kitsune keeps its own internal
identifier and that platform's customer id. Email is an attribute rather than the identity, because it changes on either
side — including by a customer-service edit Kitsune never sees — and a change propagates both ways.

**Consent is a precondition, not a later nicety.** A per-reader profile for recommendations may be built only behind
explicit consent that names what it spans, including across brands; `Sec-GPC` declines it; retention is bounded; and
erasure reaches the profile as well as the support service's copy (ADR-036). ADR-020 already promised consent records,
subject-access export and erasure tooling for v1.1, and the roadmap had omitted all three — this ADR is why they are
back on it.

⚠️ **ADR-020's `pii_class` does not reach these columns, and pretending otherwise would be the failure this log keeps
recording.** That classification lives on `field_storage` — a row the schema engine creates when an org defines a
runtime field — and its fail-closed enforcement is over that table. A reader model supplied by the host has ordinary
migration columns, which the mechanism cannot see. So the reader model records its own classification, in a form the
erasure and export tooling can read, and whether that becomes an extension of `pii_class` or a second declaration is
decided when that tooling is built rather than asserted here.

### Enforced by

**Nothing yet.** No guard, provider or model exists, and no roadmap phase scheduled one before this ADR.

When it lands: a reader cannot reach any panel route, asserted from the reader's side rather than the panel's; core
refuses to resolve a reader when no guard is declared; a profile cannot be written without a consent record; `Sec-GPC`
declines one; and erasing a reader removes the profile and calls the support fan-out.

---

## Open questions

- Storage benchmark at 10k / 100k / 1M entries
- Blueprint rollback semantics when content already exists
- Revision storage growth — full-JSON snapshots get expensive; consider diffs
- Do relations target the translation group or a specific locale row (ADR-017)? Group-targeting with an optional locale override is the leading candidate
- **Name/trademark clearance** — no PHP/CMS collision, but Mozilla's support platform and a Rust ActivityPub project both use "Kitsune." Confirm availability in software/SaaS classes **before** spending on a logo.

  **Mark settled provisionally, 2026-09-12 — ADR-032**, on the same pattern this bullet already set for the domain. The mark is used; the filing waits here. The bullet's own phrasing was the problem: "before spending on a logo" reads as a bar on designing one, when the expenditure it protects against is registration.

  **Domain settled provisionally, 2026-09-07: `kitsunecms.org`.** `kitsune.org` is held by another party and is being pursued; acquiring it would make it a redirect, not a rename. Naming the domain now unblocks ADR-026's installer, which cannot be served from a URL that might later move — a checksum-pinned script behind a redirect is exactly what that ADR refuses. **What runs at that domain, and when, is settled by ADR-030:** the site is Kitsune's first install, so it waits for Phase 5's Marketing Site blueprint to apply cleanly at the ADR-027 floor rather than being stood up on something else in the meantime.
- KaaS deployment topology beneath the org- and site-aware core — now an ops decision, not architecture, though ADR-020 gives it a legal input via data residency

**Unverifiable, do not cite:** the free/paid split of Filament's plugin directory. Filters exist; counts are not published.
