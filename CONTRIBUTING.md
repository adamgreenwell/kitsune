# Contributing to Kitsune

Thanks for looking. Read the short version first, because it will save you time:

> **Kitsune is pre-alpha and there is no code yet.** Feature pull requests aren't being accepted, and the extension API is deliberately unstable until v1.2. If you build a plugin against anything you find here before then, it will break, and that is a promise rather than a risk.

What *is* wanted right now is further down under [What's useful today](#whats-useful-today).

---

## Start with the decision log

[`docs/decision-log.md`](docs/decision-log.md) is the most important document in this repository. It records every architectural decision, every alternative that was considered, and the specific reason each one lost.

Read it before opening an issue or a PR. Most "why don't you just…" questions are already answered there, usually with the evidence that settled them.

Two entries are reversals — decisions that were made, then disproven, and are still recorded as failures rather than quietly edited out:

- **ADR-003** — Inertia + React was chosen, then reversed to Blade + Livewire on plugin-ecosystem evidence
- **ADR-012** — `ResourceConfiguration` was chosen, then disproven by an instrumented spike

That is the standard the log is held to. It is an engineering record, not a highlight reel.

### Changing an architectural decision

**Amend the ADR. Don't route around it in a pull request.**

If you think a settled decision is wrong, open an issue titled `ADR-0XX: <what should change>` and make the case. Bring evidence — measurements, prior art, a failing case. If it holds up, the ADR gets amended with the new reasoning and the old reasoning stays visible.

A PR that quietly contradicts a decision without amending its ADR will be closed regardless of how good the code is. The log only stays useful if it stays true.

---

## What's useful today

Concretely, in rough order of value:

1. **Prior-art knowledge.** Have you shipped a runtime schema engine, a multi-tenant CMS, or a plugin ecosystem? Have you watched one fail? The Standing Principles in the decision log came from studying Drupal, October, Winter, Statamic, Directus, Strapi, Payload, Backdrop and ClassicPress. If you know where a body is buried, please say so.
2. **Holes in the reasoning.** The decision log makes falsifiable claims. Falsify one.
3. **The open questions.** Listed at the bottom of the decision log. The highest-risk unknown right now is whether Filament relation managers survive an extra route parameter.
4. **Documentation fixes.** Typos, broken links, unclear passages.

### A note on the wiki

The [GitHub wiki](https://github.com/adamgreenwell/kitsune/wiki) holds two kinds of page and they never overlap.

**Generated pages** — the ADR index and roadmap status — are built from `docs/` by `php bin/wiki-sync.php` and carry a do-not-edit banner. Edit the source document and regenerate; a fix applied to the wiki is overwritten on the next sync.

**Hand-written pages** cover ground `docs/` does not: orientation, FAQs, how-tos. Those are edited in the wiki directly.

`docs/` is the source of truth either way. It is versioned with the code and gated by the amendment rule above; nothing gates a wiki page. **If a wiki page contradicts `docs/`, the document wins.**

**Issues and discussions are open. Feature PRs are not.**

---

## Language

**Open an issue in whatever language you're comfortable writing in.** "Open source" meaning "open to everyone who reads English" isn't the goal here.

One honest caveat, so nobody is caught out: the maintainer reads English natively and gets by in German and Italian. Anything else goes through machine translation. In practice that means:

- Replies may be slower
- Nuance, idiom, humour and tone will sometimes be lost, in both directions
- **If a reply reads as blunt, dismissive or confusing, it is far more likely the translation than the intent.** Please say so and we'll try again
- For **security reports and anything touching the CLA or licensing**, English is safer if you can manage it — a mistranslation there has consequences that a misunderstood feature request doesn't. If you can't, still report it; we'll work it out carefully

None of this is a reason to write in English if you'd rather not. It's just so you know what you're getting.

---

## The Contributor License Agreement

Kitsune requires a signed CLA before any pull request is merged. Here is the honest reason, because a project that asks you to sign something owes you a straight explanation:

**Kitsune may be dual-licensed in the future.** The core is [MPL-2.0](LICENSE) and will stay open source — that is not in question. But some organizations cannot accept copyleft of any kind, and selling them a separate commercial license is a legitimate way to fund maintenance. Doing that requires holding or being licensed all rights in the codebase.

**In practical terms: signing the CLA means your contribution could end up in a commercially licensed version of Kitsune that someone pays for.** You keep the copyright to your work. You grant the project the rights it needs to relicense.

If that is not acceptable to you, that is a completely reasonable position, and you should not sign. Contribute via issues and discussion instead — that is genuinely valuable and requires nothing.

**Why this is settled now rather than later:** the first PR merged without a CLA permanently forecloses the option for that code, absent tracking every past contributor down for a signature. Some never answer. Some say no. It is one of very few decisions in this project that cannot be reversed, which is why it is being made before it can be made badly. MongoDB, Grafana and Elastic all did the same thing early.

**Why not a DCO instead:** a Developer Certificate of Origin certifies you have the right to submit the code. It does not grant relicensing rights, so it would quietly close the door the CLA exists to keep open.

**The text is written: [`CLA.md`](CLA.md)**, derived from the Apache Individual CLA v2.0 with a corporate section, and it carries its own "not yet in force" warning. This paragraph used to say the text *would* be published, which had been stale since the file landed.

What is genuinely still missing is counsel's review, and the signing bot ships **switched off** until that happens — `.github/workflows/cla.yml` is gated on a repository variable rather than commented out, so enabling it is a settings change. A bot collecting signatures against an unreviewed agreement produces a record that looks like consent and may not be.

---

## Licensing rules for code

Kitsune core is **plain MPL-2.0**. Two rules matter, and both are easy to get wrong.

### 1. Never add the Exhibit B notice

The `LICENSE` file contains both Exhibit A and Exhibit B because that is the standard MPL-2.0 text. **Exhibit B is an opt-out and Kitsune does not take it.**

Adding this to a source file would make Kitsune incompatible with GPL, LGPL and AGPL code:

```
This Source Code Form is "Incompatible With Secondary Licenses", as
defined by the Mozilla Public License, v. 2.0.
```

Don't. A PR containing it will be rejected.

### 2. Every source file gets the Exhibit A header

```php
<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
```

### Why this matters to plugin authors

MPL's copyleft is **file-level**, which is the whole reason it was chosen:

- **Modify a Kitsune core file** → that file stays MPL and your changes must be published
- **Write a plugin in your own new files** → it's entirely yours. Sell it closed-source if you want

A plugin that *calls* Kitsune's APIs without copying Kitsune's code is not a "Modification" under MPL §1.10. So: **extend through hooks and interfaces, never by patching core.** Your file boundary is your license boundary.

---

## Rules that fail the build

These are not style preferences. They are correctness and security invariants, enforced in CI.

### Every model declares its scope

Kitsune has three structural levels — **Org** (the customer; the billing and user boundary), **SiteGroup** (the brand) and **Site** (anything with its own base URL) — but only **two of them scope data** (ADR-021). SiteGroup exists for settings inheritance, not for row ownership. So every model belongs to an org, to a site, or to neither.

Filament's tenancy scopes Resources automatically **and nothing else** — a model without a Resource gets no scoping at all, and Filament's own source says *"Filament does not guarantee multi-tenant security; it is your responsibility to implement correctly."*

So the kernel enforces it instead of trusting anyone to remember:

```php
#[SiteScoped]              // entries and most content — Filament's tenancy scopes these
#[OrgScoped]               // billing, settings, shared media — KITSUNE scopes these
#[OrgScopedThroughPivot]   // users: many orgs through a pivot, so there is no org_id to compare
#[Unscoped]                // genuinely global: modules, system entry types
```

**A model with none of the four throws in development and refuses to serve in production.** Fail closed, always. There is no fifth option and there is no opting out.

⚠️ **The attribute is a declaration, not an enforcement.** It does nothing unless the model also `use`s `EnforcesScope`. `User` carried `#[Unscoped]` and no trait for two phases — labelled correctly and completely unconstrained. If you add the attribute, add the trait.

⚠️ **Read this part twice.** Filament's tenancy segment is the **Site**, so its automatic global scope enforces *site* isolation only. **Org is a level Filament does not model at all**, which means an `#[OrgScoped]` model gets **no framework scope whatsoever** — it must receive a Kitsune-authored global scope. A cross-org leak in `users`, shared media or org settings would be caught by nothing Filament does. This is the invariant with no safety net under it.

**Do not write the word "tenant" in Kitsune's own code.** Filament calls its route segment a tenant; Kitsune means a Site. Say `Org` or `Site` explicitly, and reserve "tenant" for the Filament API boundary — `tenantMiddleware()`, `Filament::getTenant()`, the `{tenant}` route parameter.

### Validation uses the scoped variants

Laravel's `unique` and `exists` rules don't use Eloquent, so they ignore global scopes. Using them leaks information across scope boundaries — site B discovers a slug exists because site A holds it, and site A may belong to a different org.

Use `scopedUnique()` and `scopedExists()`. They're the defaults in Kitsune's form layer; you have to go out of your way to get this wrong, so don't.

### Every composite index leads with its scope key

`site_id` for `#[SiteScoped]` models, `org_id` for `#[OrgScoped]` ones, and the pivot's `(foreign_key, org_id)` for `#[OrgScopedThroughPivot]`. No exceptions.

Leading with `site_id` is sufficient for org isolation too: a site is globally unique and belongs to exactly one org, so the guarantee holds transitively on a narrower index (ADR-021).

### Anything reachable from a URL is untrusted

`{type}` in `admin/{tenant}/c/{type}` is user-controlled input. Middleware 404s unknown types, types belonging to another org, and types disabled for the current site (ADR-022). If you add a route parameter, it gets the same treatment.

### Tests

New behaviour needs a test. Test-driven development is the working discipline here, not an aspiration (ADR-024).

**Anything touching a scope boundary** needs a test that asserts the boundary holds, written from the attacker's side. There are **two** boundaries and both need covering:

- **Cross-site within one org** — site A cannot reach site B's data
- **Cross-org** — org A cannot reach org B's data. This is the one with no framework safety net, so it is the more important of the two

**Every model** needs a test asserting it declares a scope. An undeclared model fails the build anyway; the test says so in a readable way.

**Every field type** needs the five from [`docs/field-types.md`](docs/field-types.md) §9: storage round-trip, validation, a cross-org boundary test, and index creation on all three engines.

**Every admin route shape** needs at least one **browser** test that loads a page *outside* `/c/{type}`.

That last one looks arbitrary until you know where it came from, so here is the story. The relation-manager spike found a bug that 500d the dashboard, and **the feature suite was 7-of-8 green while it did.** Filament calls `getUrl()` on each Resource's navigation item while rendering the sidebar; under ADR-012's design that throws on any page *outside* `/c/{type}`. The suite only ever requested pages *inside* it, so nothing ever rendered the failing case. It took seconds to find in a browser.

The lesson generalises: the load-bearing risk in this architecture is **URL generation across page boundaries**, and that is precisely the seam a feature test does not cross. When you add a route parameter, assume it leaks somewhere you are not looking, and go look with a browser.

---

## Code standards

| | |
|---|---|
| PHP | `^8.4` — property hooks and asymmetric visibility are used deliberately, see ADR-013 |
| Style | Laravel Pint, default preset. `vendor/bin/pint` before pushing |
| Static analysis | PHPStan / Larastan level 6+. No new baseline entries without justification in the PR |
| Tests | **Pest** for unit and feature, **Playwright** for the browser layer (ADR-024). Note Pint above is a *formatter*, not a test tool |
| Running the suite | The Pest layer must run green on a bare clone — SQLite, no Docker, no Node. The engine matrix and browser layer are CI's job |
| AI tooling | `laravel/boost` is a **dev** dependency and must never become a runtime dependency of `kitsune/core` (ADR-025) |
| Database | Must pass on PostgreSQL, MySQL **and SQLite**. All three differ on generated columns — SQLite needs VIRTUAL rather than STORED — so go through the driver abstraction, never raw SQL in field types |

---

## Commits and pull requests

Commits follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add media field type
fix: scope entry relations to org
docs: clarify blueprint rollback
chore: bump filament to 5.5
refactor: extract field storage driver
test: add cross-org boundary assertions
```

Keep the subject under 72 characters. Use the body to explain **why**, not what — the diff already says what.

Pull requests should:

- Reference the issue or ADR they relate to
- Stay focused. One concern per PR
- Include tests
- Pass CI

---

## Security

**Do not open a public issue for a security vulnerability.**

Data isolation between orgs, and between sites within an org, is the highest-severity category in this project — a cross-org leak is the worst thing that can happen to Kitsune, and it will be treated that way.

`SECURITY.md` with a disclosure address will be published before the first release. Until then, contact the maintainer privately.

---

## Things that won't be merged

Not to be discouraging — just so nobody wastes an afternoon:

- **Anything contradicting a decision log entry** without amending the ADR first
- **A model without a tenancy declaration**
- **The Exhibit B notice**, anywhere
- **A new public API surface before v1.2.** The extension API is intentionally unstable; broadening it early is how ecosystems get broken later (Standing Principle #1)
- **Raw SQL in field types.** Postgres, MySQL and SQLite all differ; that's what the driver abstraction is for
- **Marketplace or plugin-directory infrastructure.** Standing Principle #5 — Winter CMS still hasn't shipped one 5.5 years after forking. Composer and Packagist do this job

---

## Governance

[`GOVERNANCE.md`](GOVERNANCE.md) — who decides, how maintainers earn commit rights, what happens if the maintainer disappears, and binding commitments about what will never happen to the licence — **is published now**, before there is anything to govern.

This is deliberate and it is not a formality. Winter CMS forked October CMS over *process*, five weeks before the license change everyone remembers as the cause. Licensing gets blamed; governance is usually the actual trigger. Better to write it down while it costs nothing.
