# Kitsune — Development Roadmap

**Companion to:** [`decision-log.md`](decision-log.md) (*why*) and [`architecture.md`](architecture.md) (*how*). This doc is the *what and when*.
**Created:** 2026-09-07 · **Revised:** 2026-09-07 (post-spike)
**Assumes:** solo or very small team, part-time.

> **Revision history.** Draft 1 claimed 12–18 months to v1.0 — wrong by ~3–4x, corrected. It specified `filament/filament:^5.0` and PHP 8.2 — both wrong, corrected. It built the admin on `ResourceConfiguration` — **disproven by a measurement spike**, replaced (ADR-012). It framed the WordPress importer as the go-to-market wedge — over-weighted one example, generalized (ADR-007).

---

## The two rules that matter most

**1. Do not publish a stable extension API until the core has stopped moving.** The most damaging failure mode across every CMS surveyed was breaking the extension ecosystem with a rewrite. The defense isn't designing the API perfectly up front — nobody manages that. It's keeping it explicitly **unstable and undocumented for third parties** until the schema engine and tenancy model have settled. Every pre-1.0 release says so loudly.

**2. v1.0 is the schema engine and tenancy. Everything else is post-1.0.**

---

## Stack baseline

| Component | Choice | Note |
|---|---|---|
| PHP | **`^8.4`**, CI primary on **8.5** | 8.3 is already security-only. Property hooks matter for the `Entry` model — see ADR-013 |
| Laravel | 13.x | Security to 2028-03-17 |
| Admin | **`filament/filament:^5.4`** | First release supporting Laravel 13 |
| Frontend (admin) | Livewire + Alpine | Via Filament |
| Database | PostgreSQL primary, MySQL 8.0+ / MariaDB 10.6+, **SQLite** for small installs | SQLite needs VIRTUAL generated columns rather than STORED — it cannot add STORED via ALTER TABLE. Driver abstraction handles it |
| Testing | **Pest**, plus **Playwright** for a narrow browser layer | Three layers per ADR-024. The Pest layer must stay runnable on a bare clone with SQLite — no Docker, no Node |
| Local + CI environments | **Docker** | Backs the three-engine matrix, and the same image backs the self-host installer (ADR-026) |
| Static analysis | PHPStan / Larastan, level 6+ from day one | Property hooks make this actually work |
| License | plain MPL-2.0 — **never** Exhibit B | |

---

## Scope: what v1.0 actually is

The original phase list was roughly the **union** of what October CMS, Statamic and Payload each shipped over 2+ years with full-time teams:

| Project | To 1.0 | Team |
|---|---|---|
| Statamic v2 | ~2 years | small team, already experts, existing revenue |
| Statamic v3 | **9 months in beta alone** | 3 full-time devs |
| Payload | ~18 months | funded, full-time |
| October CMS | ~2 years | 2 devs |

Those consumed 5,000–15,000 hours. 12–18 months part-time is ~1,200. **So: cut scope, or accept 3–4 years.** This roadmap cuts scope.

**v1.0 = a multi-tenant Laravel app where a non-developer defines entity types in the admin and gets working CRUD, permissions and blueprints.** That alone is a real, defensible product.

---

# Pre-1.0

## Phase 0 — Legal and repo foundations

*1–2 weeks. Cheap now, expensive-to-impossible later.*

- [x] **`LICENSE`** — plain MPL-2.0 (no Exhibit B; make sure no contributor ever adds it)
- [x] Public repository from the first commit, planning docs included (ADR-014)
- [ ] **Trademark** ([#2](https://github.com/adamgreenwell/kitsune/issues/2)): file "Kitsune" in software/SaaS classes; design and register the logo. Publish `TRADEMARK.md` modeled on the WordPress Foundation's
- [ ] **`CLA.md` + CLA bot wired in before the first external PR** ([#3](https://github.com/adamgreenwell/kitsune/issues/3)). The only irreversible item in the project
- [x] **`GOVERNANCE.md`** — stated BDFL with a disclosed bus factor, binding licence and open/paid commitments, staged succession, and a disclosed commercial conflict of interest (ADR-023)
- [x] `CONTRIBUTING.md`
- [ ] `CODE_OF_CONDUCT.md` ([#4](https://github.com/adamgreenwell/kitsune/issues/4)) and `SECURITY.md` with a real disclosure address ([#5](https://github.com/adamgreenwell/kitsune/issues/5)) — ⚠️ both are already linked from `GOVERNANCE.md` and `CONTRIBUTING.md`, so those links are live-broken on a public repo
- [x] Repo: monorepo with `packages/core`. Layout follows `laravel/framework` — tests at the root, because Pest resolves its test directory from the project root with no configuration hook
- [x] Installable app skeleton — `skeleton/`, published as `kitsune/kitsune`. SQLite by default, no Node, no Vite, no `config/` directory (Laravel's defaults plus `.env` suffice). Verified booting and rendering ([#6](https://github.com/adamgreenwell/kitsune/issues/6))
- [ ] Split-publish `kitsune/core` to Packagist ([#8](https://github.com/adamgreenwell/kitsune/issues/8))
- [x] CI: Pint, PHPStan level 6, and Pest across PHP 8.4/8.5 × Postgres/MySQL/SQLite. **All nine jobs green 2026-09-07.** The engine matrix is not decorative — `TestCase` selects its connection from the environment and `EngineMatrixTest` round-trips against whichever driver is configured
- [x] Playwright browser job — 4 smoke tests against the skeleton, one browser, Node confined to that job ([#7](https://github.com/adamgreenwell/kitsune/issues/7)). When Phase 4 lands the admin, CONTRIBUTING's standing regression test (a page loaded from *outside* `/c/{type}`) goes here
- [x] ⚠️ **Bare-clone guard** — a CI job declaring no service containers at all, running the default suite on PHP 8.4 and 8.5. **Passed on first run**, so ADR-024's pillar-three mitigation is verified rather than promised. If it ever goes red the fix is never to add services to it, but to fix the test that reached for one
- [x] `laravel/boost` as a **dev** dependency. Never a runtime dependency of `kitsune/core` (ADR-025)
- [ ] Kitsune's own guidelines file, encoding the invariants an agent violates by default ([#9](https://github.com/adamgreenwell/kitsune/issues/9))
- [ ] **Name clearance before spending on a logo** ([#1](https://github.com/adamgreenwell/kitsune/issues/1)) — Mozilla's support platform and a Rust ActivityPub project both use "Kitsune"

## Phase 1 — Remaining spikes

*1–2 weeks. Cheapest way to find out the plan is wrong.*

The routing question is **already settled** — ADR-012 was resolved by a working instrumented spike, and `ResourceConfiguration` was disproven. What's left:

- [x] **Relation managers under an extra route parameter.** ✅ **Cleared 2026-09-07** on Laravel 13.30.1 / Filament v5.7.8. Both `HasMany` and `BelongsToMany` relation managers work under `/c/{type}`; they register no routes of their own. Two new non-optional requirements fell out — see the ADR-012 amendment
- [x] **`ManageRelatedRecords` pages under `{type}`.** ✅ **Cleared 2026-09-07.** They register their own route and work — `/c/{type}/{record}/related` returns 200, Livewire updates return 200, every generated URL carries a populated `{type}`, and Attach/Detach operate. ADR-012's URL contract has no untested corners left
- [x] **Generated-column parity across Postgres, MySQL and SQLite.** ✅ **Cleared 2026-09-07.** `SchemaDriver` plus three implementations; the same parity suite passes on all three engines. SQLite takes VIRTUAL rather than STORED, which inverts the cost model in its favour. `values` is reserved on two of the three and must be quoted
- [ ] **Accessibility and RTL audit of Filament v5** — 🟡 **Accessibility half done 2026-09-07; RTL half NOT done.** ⚠️ **The screen-reader pass remains open and cannot be automated** ([#12](https://github.com/adamgreenwell/kitsune/issues/12)).

  Automated axe scanning at WCAG 2.1 A + AA across five admin page shapes — dashboard, entry list, create, edit, related records — reports **zero violations at any impact level**, including minor and moderate. That is a strong inherited baseline from Filament, and it now runs on every CI build so it cannot silently regress.

  ⚠️ **RTL is NOT verified, and an earlier version of this entry wrongly implied it was.** The test visited the English site and asserted `dir="ltr"`, which would stay green even if every RTL layout in the admin were broken. An actual render check needs the admin served under an RTL locale, which needs the locale switcher that does not exist yet.

  What *is* measured is **readiness**, a weaker claim: whether the shipped CSS would mirror if direction flipped. **535 logical properties against 139 physical — about 79% direction-agnostic.** A first count reported 18 physical because it missed bare `left`/`right`, `border-left`/`right` and `text-align`, which made the CSS look far more RTL-ready than it is. CI now enforces the ratio rather than mere presence.

  **What is inherited versus built — the question ADR-018 asked:** the machine-checkable *accessibility* baseline is essentially all inherited. RTL readiness is good but not absolute, and 139 direction-sensitive declarations would need review before claiming RTL support.

  **Two halves stay open and neither can be automated:** whether the entry editor is *usable* with a screen reader, and an actual RTL render check once a locale switcher exists
- [x] **Storage benchmark** — ✅ **2026-09-07** via `php artisan kitsune:benchmark-storage`, run with the write-amplifying decisions switched on. At **100k rows with one generated column**, every read is far inside the 200 ms Phase 4 target:

  | | SQLite | PostgreSQL | MySQL |
  |---|---|---|---|
  | insert 100k | 3306 ms | 7865 ms | 6348 ms |
  | `count(*)` | 42 ms | 22 ms | 81 ms |
  | list page (type + status) | 20 ms | 18 ms | 102 ms |
  | slug lookup (full unique index) | 0.4 ms | 10.6 ms | 1.6 ms |
  | generated-column range | 1.8 ms | 3.1 ms | 1.4 ms |
  | table + indexes | 39 MB | 48 MB | 61 MB |

  ADR-017's fan-out (10k × 10 locales) costs the same as 100k flat rows, so translation multiplies volume rather than adding a per-row penalty.

  ⚠️ **An earlier version of this table was wrong**, and the corrections are worth keeping: the slug probe omitted `entry_type_id` and so used only a prefix of the `(site_id, entry_type_id, slug)` index — and with multiple locales searched for a row that never existed, timing a miss. SQLite's size excluded index B-trees, and MySQL's came from cached `information_schema` statistics with no schema filter, reporting **131 KB for 100k rows**. Numbers a benchmark reports confidently are still wrong if the probe is wrong.

  **Still open:** the 1M run, and media-as-entries (ADR-016) at scale
- [x] ⚠️ **Resource-floor benchmark (ADR-027)** — ✅ **2026-09-07** via `php artisan kitsune:benchmark-floor`, verified inside a container limited to **1 vCPU and 1 GB**, not merely on the dev machine:

  | | value |
  |---|---|
  Measured with **1,000 entries in scope**, which matters — see the correction below.

  | | constrained (1 vCPU / 1 GB) | unconstrained |
  |---|---|---|
  | peak memory per request | 38.5 MB | 40.5 MB |
  | workers fitting in half the floor | 13 | 12 |
  | list page (25 rows) | 1.9 ms | 1.2 ms |
  | entry with relations | 2.2 ms | 1.9 ms |

  Memory barely moves between the two, which is the point: **peak memory per request is the part that transfers between machines**, while wall-clock is a property of the host.

  ⚠️ **The first version measured nothing.** It established no site context, so `SiteScope` added `WHERE 1 = 0` and every sample timed an empty result set — and the advertised `--entries` option was never read. The same shape of mistake as the storage benchmark's index probe, caught the same way, in review.

  `Kitsune::FLOOR_VCPU` and `FLOOR_MEMORY_MB` are asserted by a test, so raising the floor is a visible code change rather than a drift

**Done when:** you have numbers, written down.

## Phase 2 — Tenancy kernel, fail-closed

*5–7 weeks. Build before anything that could get it wrong.*

ADR-012 removed the boot-order collision structurally — the route table no longer depends on org or site state. What remains is enforcement.

- [x] `Org`, `SiteGroup` and `Site` models; `Context` carrying the current org and site (ADR-021). Deliberately Filament-independent — core is headless-capable, so the API and console get the same enforcement
- [x] Site resolution middleware — `SetKitsuneContext` mirrors Filament's resolved tenant into Kitsune's own `Context`, which is what the global scopes read
- [x] **`#[SiteScoped]` / `#[OrgScoped]` / `#[Unscoped]` mandatory on every model.** Undeclared throws at boot. **Fail closed**
- [x] `EnforcesScope` applying the right global scope from the attribute, and stamping the scope key on create
- [x] ⚠️ **`OrgScope`, Kitsune-authored, for `#[OrgScoped]` models.** Filament gives them no scope at all
- [x] Settings resolution: org → site group → site — sparse overrides, shallow merge, provenance on every value (ADR-022). Admin rendering of it comes with the panel
- [x] `entry_type_availability` — per-site entry types on the same sparse inheritance as settings; resolution batched so navigation costs one query rather than one per type
- [x] Resolved-config memoisation, invalidated on write
- [x] `scopedUnique()` / `scopedExists()` — go through Eloquent so global scopes apply, and are wired into the entry form. Laravel's `unique` would tell one org that another holds a slug; its `exists` would accept another org's id and let the app write a reference to it
- [x] **`IdentifyEntryType` middleware, `isPersistent: true`** — validates `{type}` exists, belongs to the current org, *and* is enabled for the current site (ADR-022), 404s otherwise, and sets `URL::defaults(['type' => ...])`. Verified in a browser as an authenticated user: own type 200, global system type 200, another org's type **404**, unknown type **404**
- [x] Reserved type-handle list, **enforced** at save time rather than merely known — the exception says why, since the collision is with the URL contract and escaping cannot fix it
- [x] Every composite index leads with its scope key — `site_id` for `#[SiteScoped]` models, `org_id` for `#[OrgScoped]` (ADR-021), as written in the tenancy and schema migrations
- [x] **Two deliberately hostile test groups** — cross-site within one org, and cross-org, both written from the attacker's side. Includes the org-shared case, where a bare `site_id IS NULL` check would expose every org's shared media

## Phase 3 — Kernel

*4–6 weeks.*

- [ ] Module registry: discovery, enable/disable, dependency resolution, ordering
- [ ] Module manifest with **mandatory `tenancy:` declaration**; kernel refuses to load without it
- [ ] Hook/event system with a documented naming convention
- [ ] Install/upgrade/uninstall lifecycle with migrations and rollback
- [ ] Settings store backing the org → site group → site resolution (ADR-022)
- [ ] RBAC: roles, permissions, per-org assignment. Permissions named `entry.{type}.{action}`
- [ ] Audit log — **actor, action and target only, never payloads** (ADR-020), so erasure can reach everything it must
- [ ] One hardcoded entity type end to end as a normal module, to prove the stack

## Phase 4 — The schema engine

*4–7 months. The hardest thing in the project, and where solo projects die.*

Data model is specified in [`architecture.md`](architecture.md) §3.

- [ ] `entries` table: one `Entry` model, type discriminator, JSON values, promoted `title`/`slug`/`status`
- [ ] `entry_types` / `field_storage` / `fields` — the Drupal storage/config split
- [ ] **`field_storage.pii_class`, fail-closed** — an unclassified field does not save (ADR-020)
- [ ] Entry types designate a **subject identifier** field, so "everything about this person" is answerable
- [ ] **Field-level redactable revisions** — erasure must reach revision history
- [ ] **`is_locked` guard from day one** — storage definitions lock once data exists
- [ ] **Field type registry** — text, rich text, number, boolean, date, select, relation, media, repeater, JSON. Each maps to storage + Filament form component + table column + API representation
- [x] **Generated-column indexing** driven by `field_storage.is_indexed`, behind the Postgres/MySQL/SQLite driver abstraction — `SchemaManager`, green on all three engines. Indexing is refused with a reason for promoted, relational, non-indexable and multi-value fields, and capped at 20 generated columns on the shared `entries` table.

  ⚠️ **A cross-org defect was caught before it shipped and cost an ADR.** The first implementation named the column `idx_{handle}`, but `entries` is one table shared by every org while `field_storage` is `UNIQUE (org_id, handle)` — so two orgs each defining `price` would collide silently, one casting the other's data to the wrong type and either able to drop the other's column. Columns are now named for their projection, `idx_{handle}__{type}`, and dropping is reference-counted ([ADR-028](decision-log.md)). `php artisan kitsune:schema-sync` is the drift repair path, since DDL implicitly commits on MySQL and a row write cannot share a transaction with its schema change
- [ ] `entry_relations` table — a real table, not JSON, so reverse lookups and referential integrity work
- [ ] `EntryResource` with the `{type}` route parameter, per ADR-012 and [`architecture.md`](architecture.md) §2
- [ ] Memoized `Panel::navigation()` closure, cached per site *(it fires 5× per request)*
- [ ] `EntryPolicy` resolving per-type authorization against `type_handle`
- [ ] Entity type builder UI
- [ ] Revisions and drafts

**Done when:** a non-developer builds a working "Products" entity with ten field types, relations and permissions entirely through the admin, on 100k rows, with no query over 200ms.

## Phase 5 — Blueprints

*3–4 weeks. Do not skip — this is what turns the schema engine into a product.*

Drupal spent ~a decade proving a runtime schema engine *without* opinionated starting configurations is harder to use than a fixed-schema CMS, then shipped "Recipes." Skip their decade.

- [ ] Blueprint format: portable bundle of entity types, fields, roles, permissions, settings, seed content
- [ ] Apply/install flow, idempotent and reversible
- [ ] First-party: **Blog**, **Marketing Site**, **DAM Starter**
- [ ] Blueprints are a **kernel primitive**, not a module

**Done when:** fresh install to working blog is one click, under 60 seconds.

## Phase 6 — v1.0 hardening

*6–8 weeks.*

- [ ] Security review, especially every tenancy boundary
- [ ] Documentation site
- [ ] Semantic versioning commitment and published upgrade policy
- [ ] Staffed security disclosure process
- [ ] **One-command self-host installer** (ADR-026) — paste one command on a fresh Ubuntu LTS box, end at the onboarding screen:
  - [ ] **Native path** (recommended default, ADR-027) — PHP-FPM + SQLite at the resource floor, `ondrej/php` PPA supplying `^8.4`
  - [ ] **Docker path**, fully supported and equal in quality, reusing the Phase 0 CI image — for scale, reproducibility, or anyone preferring a container to a PPA
  - [ ] Defaults to **SQLite** — no database server, user, password or tuning
  - [ ] Versioned + **checksum-pinned** over HTTPS, signed releases, never served from a redirect
  - [ ] Docs lead with **download-inspect-run**; the `curl | bash` one-liner is offered alongside, not instead
  - [ ] **Never creates a default admin account** — onboarding creates the first user interactively
  - [ ] **Reports nothing**, including install-succeeded pings (GOVERNANCE commitment #7)
  - [ ] **Idempotent** — re-running upgrades rather than clobbers, and detects an existing install
- [ ] Upgrade tooling

### 🎯 v1.0

---

# Existing-site migration — parallel track, not a phase

A known, bounded set of existing sites doesn't need a product-grade importer. It needs *those specific sites* moved — a throwaway script against a known dataset. **Weeks, not months.**

Run it once Phase 4 lands, and let what you learn inform the real adapter framework later. Biggest scope saving available, and the actual goal still arrives early.

---

# Post-1.0

## v1.1 — API and theming

*2–3 months.*

- [ ] REST API generated from schema, site-scoped, per-entity permissions
- [ ] Token auth + scopes (Sanctum); consider Laravel 13's first-party JSON:API resources
- [ ] Blade theme layer: template hierarchy, theme discovery, per-site selection
- [ ] Menus, routing, slugs, redirects
- [ ] Media library + Flysystem
- [ ] Caching, correctly scope-keyed (org and site)

## v1.2 — Plugin SDK and API freeze

*2–3 months. Not a feature — a public contract that constrains every future refactor.*

- [ ] Audit and **deliberately narrow** the public surface
- [ ] `kitsune/plugin-sdk`: base classes, a two-org / two-site fixture, assertion helpers
- [ ] **Validation CLI** failing on unscoped queries, missing tenancy declarations, unsafe validation rules. Run in CI for every submitted plugin
- [ ] Written deprecation policy: deprecate, never remove within a major
- [ ] Document the **two-tier reality** — third-party Filament plugins register their own Resources against their own models, so they sit *beside* the schema engine, not inside it
- [ ] ⚠️ **Tenancy-audit every allowlisted third-party plugin.** Most Filament plugins are not tenancy-aware
- [ ] **`kitsune/plugin-guidelines`** (ADR-025) — the invariants in Boost's guidelines format, so an AI-assisted plugin author inherits the scope attribute, `scopedUnique()`, the driver abstraction and the reserved handles *before* the validation CLI ever runs. The CLI catches a violation; guidelines prevent it

## v1.3 — Migration adapter framework

*3–5 months.*

Source-agnostic by design — see [`architecture.md`](architecture.md) §6. Every source maps into one canonical IR; adapters never touch Kitsune internals.

- [ ] `MigrationAdapter` interface + canonical intermediate representation
- [ ] `migration_map` (source id → Kitsune id) making imports **resumable and re-runnable**
- [ ] Dry-run mode with a full report before anything is written — required of every adapter
- [ ] **WordPress adapter** first: direct-DB import (better fidelity), WXR as low-fidelity fallback, `acf-json`/PHP field-group parsing (where the schema actually lives), media re-hosting, redirect map generation
- [ ] **CSV/JSON adapter** second — trivial to build, covers a long tail
- [ ] Further adapters as demand appears

## v1.4+ — KaaS control plane

*Separate private repo. Proprietary. 3–4 months.*

The control plane — not the license — is the moat.

- [ ] Org and site provisioning and lifecycle
- [ ] Billing and subscriptions (Cashier + Stripe)
- [ ] Usage metering and plan limits
- [ ] Backup, restore, per-org export (**ethical requirement, not a feature**)
- [ ] Custom domains + TLS automation
- [ ] **Per-org plugin allowlisting.** Don't let arbitrary customer plugins run unrestricted on shared infrastructure
- [ ] Support tooling, status page, monitoring
- [ ] First paid first-party modules, free/paid line **declared publicly and never moved**
- [ ] A curated directory page. **Do not build a marketplace**

---

## Honest timeline

**v1.0: roughly 13–19 months part-time**, front-loaded with a 1–2 week spike that could still invalidate parts of the design cheaply. Full original scope lands in **year 3**.

Not discouraging — it's what the comparables actually cost, and Filament is a head start none of them had.

**There is no external deadline, and scope is not cut to hit a date.** These numbers are a cost estimate, not a schedule: they exist because the first roadmap was wrong by 3–4x (ADR-011) and an honest number is the only defence against that recurring. Where a phase grows because a decision made it grow — Phase 6 absorbing the installer of ADR-026, Phase 0 absorbing the test matrix of ADR-024 — **the phase absorbs it and the estimate moves.** Do it right, once. What must never happen is the estimate staying still while the work grows underneath it.

---

## Sequencing risks

1. **Phase 4 is where solo projects die.** The schema engine is hard and has no visible payoff until Phase 5. Ship Blueprints immediately after, or the whole thing feels broken
2. **Do the Phase 1 spikes first.** Relation managers are the highest-risk unknown left
3. **Benchmark storage in Phase 4, not later.** Drupal's join explosion took seven years to even get filed
4. **Filament's major cadence is now yours.** Roughly annual. Budget a major upgrade inside the v1.0 window
5. **Support load is a ~3x multiplier**, arriving exactly when you get users
6. **Don't charge before there's an adoption base**
7. **Measure, don't reason, about framework internals.** ADR-012 was reasoned into the plan and then disproven by two hours of instrumented spike. Assume the same is true of anything else load-bearing
