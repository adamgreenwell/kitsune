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


## Why the thinking is public too

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
| [`docs/accessibility-inventory.md`](docs/accessibility-inventory.md) | **What is inherited.** Measured WCAG and RTL conformance, what Filament supplies, and the gaps that are Kitsune's to build |

## Shape of the thing

- **A thin module kernel** — registry, hooks, lifecycle, RBAC, audit
- **A runtime schema engine** as the flagship first-party module: define entity types and fields in the admin, no PHP required
- **Multi-tenant from the kernel up**, enforced fail-closed rather than left to plugin authors
- **Blueprints** — installable bundles of entry types with their fields, roles with their grants, and entry type availability, applied into an org so a fresh install isn't a blank canvas. Seed content is a separate, opt-in phase, because seeding a value locks that field's shape ([ADR-039](docs/decision-log.md))
- **Headless-capable core** with an optional Blade theming layer
- **Source-agnostic migration adapters**, so moving in from an existing CMS is a plugin rather than a rewrite

### Stack

Not a plan. This is what CI runs on every pull request and every push to `main`: PHP 8.4 and 8.5 across SQLite, PostgreSQL, MySQL and MariaDB, plus a bare-clone job declaring no service containers at all.

| | |
|---|---|
| PHP | `^8.4` |
| Laravel | 13.x |
| Admin | Filament `^5.4` — Livewire + Alpine, server-rendered |
| Database | PostgreSQL primary; MySQL 8.0+ / MariaDB 10.6+; SQLite for small single-site installs |

## Running it locally

Measured on a clean clone, not written from memory — four commands, under a minute on a warm cache:

```bash
composer install                       # the monorepo: core, the skeleton's dev tooling, the test suite
composer skeleton:install              # the skeleton's own dependencies, the browser suite's test module (switched off), .env, app key and SQLite file
php skeleton/artisan migrate --seed    # schema and a small demo organisation
php skeleton/artisan serve             # http://127.0.0.1:8000/admin
```

Sign in as `alpha@kitsune.test` with the password `password`. The seeded organisation is **Golfdom**, with a
second organisation and a user of its own — `rival@kitsune.test` — because most of what is interesting about
the scoping kernel is only visible when there are two orgs to keep apart.

Readers — the people a site's paid or members-only content is for — are not staff and never reach the admin
([ADR-037](docs/decision-log.md)). The seeded reader `subscriber@kitsune.test` signs in at
`http://127.0.0.1:8000/golfdom/account/sign-in` with the password `correct-horse-battery-staple`; Rival has a reader
with the same address and a password of its own, `rival-horse-battery-staple`, at `/rival/account/sign-in`, because
one address in two organisations is two readers.

### Reader accounts

A reader's sign-in pages are core's, placed by one line in the skeleton's `routes/web.php`; the reader's model,
table and guard are the skeleton's (`App\Models\Reader`). They answer 404 until an operator switches them on for an
organisation or a site, and they live under a site's public address — which a site `kitsune:blueprint apply` creates
does not have until its `base_url` is set (today, through `php skeleton/artisan tinker`):

```bash
php skeleton/artisan kitsune:readers status --org=myblog          # is the guard usable, and what each site serves
php skeleton/artisan kitsune:readers mode sign-in --org=myblog    # off, sign-in or open; --site=<handle> for one site
```

`find`, `export` and `erase` act on one reader — for an access or erasure request — and read the reader's address at
a prompt, or from the first line of standard input, never from an option, which other users on the machine can read;
`--reader=<id>` names one by number instead. `erase --force` deletes the reader's account and every entitlement they
hold, in one transaction. After restoring a backup, run each `erase` again: the restore brings the reader back.

The pages' views and words are core's (`kitsune::readers`), and a site may override them under
`resources/views/vendor/kitsune/readers/` and `lang/vendor/kitsune/`, with no promise they keep their shape before
v1.1's theme layer.

**An installation made before reader accounts** gets core's half with `composer update` and nothing else — the skeleton
is copied at `create-project`, so its half is four edits to make by hand: `app/Models/Reader.php`, the
`0001_01_01_000020_create_readers_table.php` migration, the three settings in `AppServiceProvider::readerConfig()`
(called from `register()`), and the `ReaderRoutes::register();` line in `routes/web.php`, above the catch-all.

### Starting from nothing

The demo data is one way in; a blog of your own is the other — an empty installation, then one command:

```bash
php skeleton/artisan migrate
php skeleton/artisan kitsune:blueprint apply blog --org=myblog --owner=you@example.com
php skeleton/artisan serve
```

That creates the organisation `myblog`, its first site, and you as its owner, then applies the Blog blueprint.
You are asked for a password twice, hidden — at least 15 characters — and it is never shown; where it could not be
hidden, as over `ssh` or `docker exec` without `-t`, the prompt is refused rather than shown. In a script, pipe it
in instead with `--owner-password-stdin --no-interaction`, for example `< owner-password.txt` from a file only you
can read; it is never accepted as an argument or an environment variable, which other users on the machine can
read. `--owner` is refused on an installation that already has an organisation or an account — `migrate --seed`
above included — because it creates the *first* owner and nothing else.

For a site of pages instead, apply `marketing-site` — and either blueprint can be added to the other's organisation
afterwards, without `--owner`:

```bash
php skeleton/artisan kitsune:blueprint apply marketing-site --org=mysite --owner=you@example.com
php skeleton/artisan kitsune:blueprint apply blog --org=mysite
```

For a library of files — pictures, documents, anything Kitsune stores — apply `dam`: on an empty installation with
`--owner`, as above, or into an organisation that already has either of the others, without it:

```bash
php skeleton/artisan kitsune:blueprint apply dam --org=mylibrary --owner=you@example.com
php skeleton/artisan kitsune:blueprint apply dam --org=mysite
```

It adds one media type, **Assets**, recording who holds the rights to each file, the licence it is used under and when
that licence ends, and three roles — Asset manager, Asset contributor and Asset viewer — that an owner assigns under
Roles. Worth knowing before they do:

- An upload is **private** — behind sign-in, to whoever may view Assets — until someone makes it public.
- An **Asset contributor can make files public**, any of the organisation's and not only their own: uploading needs
  that permission ([ADR-042](docs/decision-log.md)). Only an Asset manager deletes.
- **Nothing acts on the licence's end date.** It is recorded; nothing is hidden or withdrawn when it passes.
- **Rights holder is personal data.** Choose it as the Assets type's *Subject identifier*, on its page under Entry
  types, so that a photographer's access request finds every file crediting them.
- A Blog or Marketing Site writer needs **Asset viewer** beside their own role to pick an asset in a relation field.

When a newer core ships a newer version of a blueprint, the same `apply` command upgrades it: it adds what the new
version declares, never changes or removes anything the organisation already has — its owner's edits included — and
refuses, saying why, a version that would ([ADR-039](docs/decision-log.md)).

To take a blueprint back out of an organisation:

```bash
php skeleton/artisan kitsune:blueprint reverse blog --org=mysite
```

It removes what the blueprint created while nothing holds data for it — and otherwise refuses, saying what is in the
way and writing nothing ([ADR-039](docs/decision-log.md)).

⚠️ **The public side is a placeholder, and that is the plan rather than a gap.** `http://127.0.0.1:8000/` and a
site's own path, such as `/golfdom`, render one page that says so and links to the admin — nothing public renders
an entry yet; a reader's account pages are the only other public pages. Kitsune's first release is the admin; a
public site that renders entries is theming, which ADR-011 in the [decision log](docs/decision-log.md) moved to v1.1.

⚠️ **`composer install -d skeleton` on its own does not work, and the reason is temporary.** `kitsune/core`
is not on Packagist yet ([#8](https://github.com/adamgreenwell/kitsune/issues/8)), so the skeleton resolves
it through a path repository that `composer skeleton:install` writes and then reverts — which keeps the
committed `skeleton/composer.json` honest about what a real installation will look like. Run the script, not
the bare install, and the error you would otherwise get is *"kitsune/core could not be found in any
version"*.

### Running the tests

```bash
vendor/bin/pest                        # the whole suite, SQLite in memory, no services required
vendor/bin/pint --test                 # formatting: the monorepo
vendor/bin/pint --test skeleton --config skeleton/pint.json   # and the skeleton, which has its own config
vendor/bin/phpstan analyse             # level 6, no baseline
```

⚠️ **Both Pint invocations, because the root config excludes `skeleton`.** The skeleton is an installable
Laravel application with Laravel's own conventions, so it is formatted against its own config — and running
only the first command passes locally while failing CI on any skeleton change.

The browser suite needs Node and a browser binary, and it starts its own server:

```bash
npm ci
npx playwright install chromium
npx playwright test
```

The four-engine matrix CI runs is reproducible locally with the containers in `compose.yaml`:

```bash
docker compose up -d

vendor/bin/pest                                                    # SQLite, the default — no container
DB_CONNECTION=pgsql   DB_PORT=55432 DB_DATABASE=kitsune DB_USERNAME=kitsune DB_PASSWORD=kitsune vendor/bin/pest
DB_CONNECTION=mysql   DB_PORT=53306 DB_DATABASE=kitsune DB_USERNAME=kitsune DB_PASSWORD=kitsune vendor/bin/pest
DB_CONNECTION=mariadb DB_PORT=53307 DB_DATABASE=kitsune DB_USERNAME=kitsune DB_PASSWORD=kitsune vendor/bin/pest
```

⚠️ **`DB_PORT` is not optional and its absence looks like a code regression.** The containers publish on
non-default ports; without it every test fails at setup with *"Connection refused"*, which reads as a broken
suite rather than a missing variable.

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

The **Kitsune name and logo are separate from the code license.** The code is yours to use; the name is not. A trademark policy — `TRADEMARK.md` — will land alongside the first release. Short version, and it will not surprise anyone who knows the WordPress Foundation's: you may say your work is *built for Kitsune*; you may not name your fork, product, or domain Kitsune.

## Contributing

Feature PRs aren't open yet — the extension API is deliberately unstable and undocumented until v1.2, and inviting plugin authors before it settles is the single most reliable way to wreck an ecosystem (see Standing Principle #1 in the decision log).

**Issues and discussion are open, in any language.** Especially valuable right now: prior-art knowledge from anyone who has shipped a runtime schema engine or watched one fail, and holes in the reasoning — the decision log makes falsifiable claims, so falsify one.

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — what is useful today, the invariants that fail the build, and why there is a CLA
- [`GOVERNANCE.md`](GOVERNANCE.md) — who decides, the disclosed bus factor of one, and the binding commitments about what will never happen to the licence

The CLA text is published — [`CLA.md`](CLA.md), derived from the Apache Individual CLA v2.0 with a corporate section — and it carries its own *not yet in force* warning. The signing bot ships **switched off** until counsel has reviewed the agreement, because a bot collecting signatures against an unreviewed text produces a record that looks like consent and may not be.

## Prior art, gratefully

Kitsune's design is substantially shaped by studying what worked and what hurt in Drupal, October CMS, Winter CMS, Statamic, Directus, Strapi, Payload, Backdrop and ClassicPress. The specifics — with citations — are in the decision log's Standing Principles. Several of those projects paid for these lessons the hard way.
