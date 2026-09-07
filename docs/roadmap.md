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
| Testing | Pest | |
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
- [ ] **Trademark**: file "Kitsune" in software/SaaS classes; design and register the logo. Publish `TRADEMARK.md` modeled on the WordPress Foundation's
- [ ] **`CLA.md` + CLA bot wired in before the first external PR.** The only irreversible item in the project
- [x] **`GOVERNANCE.md`** — stated BDFL with a disclosed bus factor, binding licence and open/paid commitments, staged succession, and a disclosed commercial conflict of interest (ADR-023)
- [x] `CONTRIBUTING.md`
- [ ] `CODE_OF_CONDUCT.md` and `SECURITY.md` with a real disclosure address — ⚠️ both are already linked from `GOVERNANCE.md` and `CONTRIBUTING.md`, so those links are currently broken
- [ ] Repo: monorepo, `kitsune/core` + app skeleton, split-published to Packagist
- [ ] CI: Pest, PHPStan, matrix across PHP 8.4/8.5 and Postgres/MySQL/SQLite
- [ ] **Name clearance before spending on a logo** — Mozilla's support platform and a Rust ActivityPub project both use "Kitsune"

## Phase 1 — Remaining spikes

*1–2 weeks. Cheapest way to find out the plan is wrong.*

The routing question is **already settled** — ADR-012 was resolved by a working instrumented spike, and `ResourceConfiguration` was disproven. What's left:

- [x] **Relation managers under an extra route parameter.** ✅ **Cleared 2026-09-07** on Laravel 13.30.1 / Filament v5.7.8. Both `HasMany` and `BelongsToMany` relation managers work under `/c/{type}`; they register no routes of their own. Two new non-optional requirements fell out — see the ADR-012 amendment
- [ ] **`ManageRelatedRecords` pages under `{type}`** — the remaining slice of the above. A different construct that *does* register its own route; not yet tested
- [ ] **Generated-column parity across Postgres, MySQL and SQLite.** Syntax and JSON path operators all differ, and SQLite needs VIRTUAL rather than STORED columns. Prove the driver abstraction holds before building field types on it
- [ ] **Accessibility and RTL audit of Filament v5.** How much WCAG conformance and RTL layout do you inherit versus build? Automated checkers won't answer this — drive the entry editor with a screen reader. Same afternoon as the RTL render check
- [ ] **Storage benchmark** at 10k / 100k / 1M entries on all three engines — include the row multiplication from translation (ADR-017) and one `entries` row per media asset (ADR-016)

**Done when:** you have numbers, written down.

## Phase 2 — Tenancy kernel, fail-closed

*5–7 weeks. Build before anything that could get it wrong.*

ADR-012 removed the boot-order collision structurally — the route table no longer depends on org or site state. What remains is enforcement.

- [ ] `Org`, `SiteGroup` and `Site` models; site resolution middleware; site context (ADR-021)
- [ ] **`#[SiteScoped]` / `#[OrgScoped]` / `#[Unscoped]` mandatory on every model.** Undeclared = exception in dev, refuse-to-serve in prod. **Fail closed**
- [ ] Base model applying the right global scope from the attribute
- [ ] ⚠️ **A Kitsune-authored global scope for `#[OrgScoped]` models.** Filament's tenancy is the Site, so it enforces site isolation and gives org-scoped models **no scope at all**
- [ ] Settings resolution: org → site group → site — sparse overrides, shallow merge, **origin shown in the admin** (ADR-022)
- [ ] `entry_type_availability` — per-site entry types on the same inheritance
- [ ] Resolved-config cache, invalidated down the hierarchy on write
- [ ] `scopedUnique()` / `scopedExists()` as form-layer **defaults**
- [ ] **`IdentifyEntryType` middleware, `isPersistent: true`** — validates `{type}` exists, belongs to the current org, *and* is enabled for the current site (ADR-022), **404s otherwise**, and sets `URL::defaults(['type' => ...])`. `{type}` is user-controlled URL input; this is a security boundary, not a convenience
- [ ] Reserved type-handle list (`create`, `edit`, `delete`, + Filament page segments), enforced at type creation
- [ ] Every composite index leads with its scope key — `site_id` for `#[SiteScoped]` models, `org_id` for `#[OrgScoped]` (ADR-021)
- [ ] **Two deliberately hostile tests** — cross-site within one org, and cross-org. The second has no framework safety net

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
- [ ] **Generated-column indexing** driven by `field_storage.is_indexed`, behind the Postgres/MySQL driver abstraction
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
- [ ] Installer and upgrade tooling

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

---

## Sequencing risks

1. **Phase 4 is where solo projects die.** The schema engine is hard and has no visible payoff until Phase 5. Ship Blueprints immediately after, or the whole thing feels broken
2. **Do the Phase 1 spikes first.** Relation managers are the highest-risk unknown left
3. **Benchmark storage in Phase 4, not later.** Drupal's join explosion took seven years to even get filed
4. **Filament's major cadence is now yours.** Roughly annual. Budget a major upgrade inside the v1.0 window
5. **Support load is a ~3x multiplier**, arriving exactly when you get users
6. **Don't charge before there's an adoption base**
7. **Measure, don't reason, about framework internals.** ADR-012 was reasoned into the plan and then disproven by two hours of instrumented spike. Assume the same is true of anything else load-bearing
