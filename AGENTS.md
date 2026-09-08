# Kitsune — agent guidelines

Rules an AI coding agent gets wrong here by default. Each one is enforced somewhere — CI, a build-failing test, or code review — so violating them wastes a round trip rather than shipping.

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) for the human version and [`docs/decision-log.md`](docs/decision-log.md) for why any of it is true.

---

## 1. Never write the word "tenant" in Kitsune's own code

Kitsune has **two scoping levels**, and "tenant" is ambiguous between them:

- **Org** — the customer. Billing and user boundary.
- **Site** — anything with its own base URL. An org owns many.

There is also **SiteGroup** (the brand), but it exists for settings inheritance and does not scope rows.

Say `Org` or `Site` explicitly. Reserve "tenant" for the Filament API boundary only — `tenantMiddleware()`, `Filament::getTenant()`, the `{tenant}` route parameter (ADR-021).

## 2. Every model declares its scope, and org has no safety net

```php
#[SiteScoped]              // entries and most content — Filament's tenancy scopes these
#[OrgScoped]               // billing, settings, shared media — KITSUNE scopes these
#[OrgScopedThroughPivot]   // users: membership is many-to-many, so there is no org_id
#[Unscoped]                // genuinely global: modules, system entry types
```

A model with none of the four **throws in development and refuses to serve in production**. Fail closed.

⚠️ **The attribute is a declaration, not an enforcement.** It does nothing unless the model also `use`s `EnforcesScope` — `User` carried `#[Unscoped]` and no trait for two phases, so it was labelled correctly and completely unconstrained. If you add the attribute, add the trait.

**The part that matters:** Filament's tenancy segment is the Site, so its automatic global scope enforces *site* isolation only. **Org is a level Filament does not model at all.** An org-scoped model gets **no framework scope whatsoever** and must receive a Kitsune-authored one. A cross-org leak would be caught by nothing Filament does.

Every org scope **fails closed with no context**, which is why the authentication path needs an explicit carve-out (`OrgAwareUserProvider`) — a user is resolved before any org exists. Put such a carve-out where the query is actually built: Laravel's user provider constructs its own, so a carve-out written as a method on `User` would read correctly and never run.

## 3. Never Laravel's `unique` or `exists`

They do not go through Eloquent, so they ignore global scopes and leak across boundaries. Use `scopedUnique()` and `scopedExists()`. This fails the build.

## 4. Composite indexes lead with the scope key

`site_id` for `#[SiteScoped]`, `org_id` for `#[OrgScoped]`. Leading with `site_id` covers org isolation transitively — a site is globally unique and belongs to exactly one org.

## 5. Never raw SQL in a field type — and there are three drivers

Postgres, MySQL **and SQLite**. All three differ on generated columns, and SQLite differs structurally: it cannot `ALTER TABLE ADD COLUMN` a STORED generated column at all, taking a VIRTUAL one indexed as an expression index. Go through the driver abstraction; `generatedColumnType()` takes the driver for exactly this reason.

## 6. Anything reachable from a URL is untrusted

`{type}` in `admin/{tenant}/c/{type}` is user input. Middleware 404s unknown types, types belonging to another org, and types disabled for the current site. Reserved handles (`create`, `edit`, `delete`, and every Filament page segment) are rejected at type-creation time.

If you add a route parameter, it gets the same treatment.

## 7. Every PHP file carries the MPL Exhibit A header. Never Exhibit B

```php
<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
```

Adding the "Incompatible With Secondary Licenses" notice would make Kitsune incompatible with GPL, LGPL and AGPL code. A PR containing it is rejected.

## 8. Foreign keys are enforced in tests, and were not

SQLite does not enforce them unless asked and Testbench does not ask, so the default suite exercised **no** `cascadeOnDelete`, `nullOnDelete` or `constrained()` in any migration — while the PostgreSQL and MySQL legs silently did. `TestCase` now sets `foreign_key_constraints` for SQLite.

If a test that passes on SQLite fails on an engine leg with a constraint error, the constraint is the truth and the SQLite pass was the illusion.

## 9. Tests, and the one that looks arbitrary

- **Every model** — a test asserting it declares a scope
- **Every scope boundary** — written from the attacker's side. **Two** boundaries: cross-site within one org, and cross-org. The second has no framework safety net and is the more important
- **Every field type** — storage round-trip, validation, cross-org, index creation on all three engines
- **Every admin route shape** — at least one **browser** test loading a page *outside* `/c/{type}`

That last one has a reason. During the relation-manager spike the PHP suite was **seven-of-eight green while the dashboard returned 500**: Filament calls `getUrl()` on each Resource's navigation item while rendering the sidebar, which throws on any page outside `/c/{type}`, and the suite only ever requested pages inside it.

**The load-bearing risk in this architecture is URL generation across page boundaries, which is exactly the seam a feature test does not cross.**

## 10. Do not raise the resource floor

The designed floor is **1 vCPU, 1 GB RAM, SQLite, no container runtime, no external services** (ADR-027).

Core may not *require*, for a default single-site install: a container runtime, a separate database server, Redis or Memcached, a search daemon, Node at runtime, or an always-on worker. Any of those may be **supported and recommended at scale**; none may be **required to reach onboarding**.

A feature that is better with Redis may use Redis when present and must work without it.

## 11. The default test suite must run on a bare clone

SQLite, no Docker, no Node, no services. A CI job enforces this. If it goes red, **the fix is never to add services to that job** — it is to fix the test that reached for one.

Node belongs to the Playwright job alone.

## 12. Amend the ADR; do not route around it

A pull request that contradicts a decision-log entry without amending its ADR is closed **regardless of how good the code is**. Changing a settled decision means amending the ADR with the old reasoning left visible.

This binds the maintainer identically. ADR-026's recommended default was flipped by ADR-027 twenty minutes after it was written, by amendment rather than a quiet edit.

## 13. `once()` keys must be values the body actually uses

`once()` hashes the closure's captured variables, and hashes an **object** by `spl_object_id` — a handle PHP recycles the moment the object is collected. A memo keyed on a `Site` cannot reliably tell two sites apart inside one process. Under PHP-FPM the process dies between requests and it never bites; under Octane or a queue worker it does.

Capturing an extra scalar purely to fix the key does not work either: **Pint strips unused `use` variables**, and it did — silently reverting the fix. So pass scope keys rather than models (`EntryTypeAvailability::enabledMapFor()` takes `$siteId, $siteGroupId, $orgId` for this reason), and the key becomes correct because the body genuinely uses it.

## 14. A field type's schema and its validation drift by default

Overriding `scalarValidationRules()` is what you reach for when a constraint
is needed; overriding `scalarApiSchema()` is optional and easy to forget. So
they come apart, and the failure is quiet in the worst direction: a generated
client accepts payloads the API rejects, or is handed an enum no value can
satisfy.

Review found this **four separate times in one PR** — the select enum, the
multi-select enum, the scalar cardinality bound, the relation cardinality
bound — and each was fixed as an instance rather than as a class.

**When a field type constrains a value, publish the constraint.** `enum` for
option keys, `maxLength` and `pattern` for text, `maxItems` for a finite
cardinality, bounds for a number. If a constraint cannot be expressed in the
schema, say so in the type's docblock rather than leaving the schema silently
wider than the rule.

## 15. Measure; do not reason

Standing Principle #9, and it has cost real time when ignored:

- `ResourceConfiguration` was reasoned into the roadmap and disproven by a two-hour spike
- `original_request()` was assumed global; it is namespaced, and the fatal only fires on Livewire updates
- `ramsey/composer-install@v3` was assumed to exist; it does not, and every CI job would have failed
- GitHub's slug algorithm was assumed to collapse repeated hyphens; it does not, and 27 links would have broken
- The navigation memo was assumed to key on the site; it keyed on a recyclable object handle. A probe showed the id reused on the very next allocation

**If a claim is cheap to verify, verify it.** One command beats a confident guess.

---

## Where things live

| | |
|---|---|
| `packages/core/src` | `kitsune/core` — split-published, read-only mirror |
| `tests/Core` | the Pest suite (root, because Pest resolves its directory from the project root) |
| `skeleton/` | the installable app, published as `kitsune/kitsune` |
| `e2e/` | Playwright specs |
| `docs/` | source of truth for every decision |

Run the full gate before committing: `composer ci`, then `vendor/bin/pint --test skeleton --config skeleton/pint.json`.
