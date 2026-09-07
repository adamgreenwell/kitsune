# Kitsune

> A multi-purpose content and application platform for Laravel.
> **Status: pre-alpha, under active development.**
> The [roadmap](docs/roadmap.md) tracks what exists today, item by item.
> Nothing is released and nothing is stable.
>
> **[kitsunecms.org](https://kitsunecms.org)**

A [kitsune](https://en.wikipedia.org/wiki/Kitsune) is a supernatural fox spirit in Japanese folklore, possessing high intelligence, long life, and magical powers. In Japanese myth the word literally means "fox," but it describes a complex entity belonging to the class of supernatural beings known as *yōkai*.

The name is the pitch: **Kitsune is meant to become whatever you need it to be.** A content management system, a digital asset manager, an e-commerce store, an ERP — built from the same kernel, configured rather than forked.

---

## Three pillars

1. **Moldable** — becomes what you need by configuration, not by forking.
2. **Privacy first** — GDPR and CCPA obligations are achievable by construction. If you self-host, you're the data controller; making that workable is core's job, not yours.
3. **Accessible to everyone** — physical (real assistive-technology testing, not a boilerplate conformance claim), linguistic (usable and contributable-to without English), and economic (a one-person project and a thousand-person publisher both fit).

These conflict, deliberately. Maximum flexibility fights ease of use; audit trails fight erasure; enterprise features tax small installs. [`docs/decision-log.md`](docs/decision-log.md) names each conflict where it lands rather than resolving it silently — because a pillar that never costs anything is a slogan, not a commitment.


## Why this repository exists before the code does

This project is being built in the open from the first commit, including the parts that are just thinking.

Every architectural decision is recorded in [`docs/decision-log.md`](docs/decision-log.md) — **including the options that lost and the specific reason each one lost.** Some of those decisions have already been reversed by evidence, and the reversals are still in there. That's deliberate: an ADR that only records what survived is a marketing document, not an engineering record.

If you're evaluating whether to build on Kitsune later, the decision log is the honest answer to "do these people know what they're doing, and will they tell me when they're wrong."

## The documents

| Doc | What it answers |
|---|---|
| [`docs/decision-log.md`](docs/decision-log.md) | **Why.** Every decision, every rejected alternative, and the reason it lost |
| [`docs/roadmap.md`](docs/roadmap.md) | **What and when.** Phased plan to v1.0 and beyond, with honest timelines |
| [`docs/architecture.md`](docs/architecture.md) | **How.** Data model, admin routing, tenancy security model, module and migration contracts |
| [`docs/field-types.md`](docs/field-types.md) | **The field contract.** Storage strategies, the four faces every field type must answer, and the twelve types shipping in v1.0 |

## Shape of the thing

- **A thin module kernel** — registry, hooks, lifecycle, RBAC, audit
- **A runtime schema engine** as the flagship first-party module: define entity types and fields in the admin, no PHP required
- **Multi-tenant from the kernel up**, enforced fail-closed rather than left to plugin authors
- **Blueprints** — portable, installable bundles of entity types, fields, roles and seed content, so a fresh install isn't a blank canvas
- **Headless-capable core** with an optional Blade theming layer
- **Source-agnostic migration adapters**, so moving in from an existing CMS is a plugin rather than a rewrite

### Planned stack

| | |
|---|---|
| PHP | `^8.4` (CI primary on 8.5) |
| Laravel | 13.x |
| Admin | Filament `^5.4` — Livewire + Alpine, server-rendered |
| Database | PostgreSQL primary; MySQL 8.0+ / MariaDB 10.6+; SQLite for small single-site installs |

## Licensing

Kitsune core is licensed under the **[Mozilla Public License 2.0](LICENSE)**.

In plain terms:

- **Use it commercially.** Sell it, host it, build a business on it. No revenue caps, no seat limits, no "competing product" clauses.
- **Modify a core file → publish that file.** MPL's copyleft is *file-level*, so improvements to Kitsune itself come back.
- **Write a plugin in your own files → it's entirely yours.** Sell it closed-source if you like. A plugin that calls Kitsune's APIs without copying Kitsune's code is not a Modification under MPL §1.10.
- **No SaaS clause.** MPL has no network provision. Host it for customers without obligation.

> **Contributors:** do **not** add the Exhibit B "Incompatible With Secondary Licenses" notice to any source file. Kitsune uses plain MPL-2.0 deliberately, so that it stays compatible with GPL/LGPL/AGPL code. Exhibit B's presence in `LICENSE` is just part of the standard license text — it is not activated unless a file opts in.

### The name and the domain

The project lives at **[kitsunecms.org](https://kitsunecms.org)**. `kitsune.org` is held by someone else and is being pursued; if it lands, this becomes a redirect rather than a rename, and nothing that references the project has to move.

The **Kitsune name and logo are separate from the code license.** The code is yours to use; the name is not. A trademark policy will land alongside the first release. Short version, and it will not surprise anyone who knows the WordPress Foundation's: you may say your work is *built for Kitsune*; you may not name your fork, product, or domain Kitsune.

## Contributing

Feature PRs aren't open yet — the extension API is deliberately unstable and undocumented until v1.2, and inviting plugin authors before it settles is the single most reliable way to wreck an ecosystem (see Standing Principle #1 in the decision log).

**Issues and discussion are open, in any language.** Especially valuable right now: prior-art knowledge from anyone who has shipped a runtime schema engine or watched one fail, and holes in the reasoning — the decision log makes falsifiable claims, so falsify one.

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — what is useful today, the invariants that fail the build, and why there is a CLA
- [`GOVERNANCE.md`](GOVERNANCE.md) — who decides, the disclosed bus factor of one, and the binding commitments about what will never happen to the licence

The CLA text is the one piece still outstanding; it will be published before the first external PR is accepted.

## Prior art, gratefully

Kitsune's design is substantially shaped by studying what worked and what hurt in Drupal, October CMS, Winter CMS, Statamic, Directus, Strapi, Payload, Backdrop and ClassicPress. The specifics — with citations — are in the decision log's Standing Principles. Several of those projects paid for these lessons the hard way.
