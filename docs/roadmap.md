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
| PHP | **`^8.4`**, tested on **8.4 and 8.5**; the alpha targets **8.5** | 8.3 is already security-only. Property hooks matter for the `Entry` model — see ADR-013 |
| Laravel | 13.x | Security to 2028-03-17 |
| Admin | **`filament/filament:^5.4`** | First release supporting Laravel 13 |
| Frontend (admin) | Livewire + Alpine | Via Filament |
| Database | PostgreSQL primary, MySQL 8.0+ / MariaDB 10.6+, **SQLite** for small installs | SQLite needs VIRTUAL generated columns rather than STORED — it cannot add STORED via ALTER TABLE. Driver abstraction handles it |
| Testing | **Pest**, plus **Playwright** for a narrow browser layer | Three layers per ADR-024. The Pest layer must stay runnable on a bare clone with SQLite — no Docker, no Node |
| Local + CI environments | **Docker** | Backs the four-engine matrix — SQLite, PostgreSQL, MySQL and MariaDB — and the same image backs the self-host installer (ADR-026) |
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
- [ ] **Trademark** ([#2](https://github.com/adamgreenwell/kitsune/issues/2)): file "Kitsune" in software/SaaS classes; design and register the logo. Publish `TRADEMARK.md` modeled on the WordPress Foundation's.

  **The mark is designed and provisionally adopted, 2026-09-12 — ADR-032.** Three marks on a ladder that abstracts rather than reduces: nine-tail lockup, compact fox, two-tail glyph. A one-tailed fox was rejected as generic at the size where distinctiveness matters most, and a single tail lost to the mirrored pair on a render test at icon sizes. **Filing still waits on clearance below.** The vector redraw is in progress; nothing in [`brand/`](../brand/README.md)'s manifest is built yet, and the palette is unmeasured by design rather than by oversight
- [ ] **`CLA.md` + CLA bot wired in before the first external PR** ([#3](https://github.com/adamgreenwell/kitsune/issues/3)). The only irreversible item in the project
- [x] **`GOVERNANCE.md`** — stated BDFL with a disclosed bus factor, binding licence and open/paid commitments, staged succession, and a disclosed commercial conflict of interest (ADR-023)
- [x] `CONTRIBUTING.md`
- [x] `CODE_OF_CONDUCT.md` ([#4](https://github.com/adamgreenwell/kitsune/issues/4)) and `SECURITY.md` ([#5](https://github.com/adamgreenwell/kitsune/issues/5)). Both exist, so the links from `GOVERNANCE.md` and `CONTRIBUTING.md` resolve — the ⚠️ that used to sit here warned they were live-broken, and it outlived the problem by several phases. `SECURITY.md` routes disclosure through GitHub's private advisory flow rather than an email address, which is encrypted in transit and keeps the report attached to its eventual fix
- [x] Repo: monorepo with `packages/core`. Layout follows `laravel/framework` — tests at the root, because Pest resolves its test directory from the project root with no configuration hook
- [x] Installable app skeleton — `skeleton/`, published as `kitsune/kitsune`. SQLite by default, no Node, no Vite, no `config/` directory (Laravel's defaults plus `.env` suffice). Verified booting and rendering ([#6](https://github.com/adamgreenwell/kitsune/issues/6))
- [x] Split-publish `kitsune/core` to Packagist ([#8](https://github.com/adamgreenwell/kitsune/issues/8)) — `.github/workflows/split-packages.yml` subtree-splits `packages/core` to the `kitsune-cms/core` mirror on a `v*` tag, and Packagist serves it from there. Shipped with **v0.1.0** (2026-09-17) and carried v0.1.1 and **v0.2.0**, the module kernel.

  ⚠️ **The tick was late, and the line being stale had a cost.** #8 closed when the split first ran, and this checkbox stayed empty — so ADR-038 and a commit message both named #8 as the reason a second first-party package could not exist, which was wrong twice over. What actually blocked one was three hardcoded `packages/core` paths in `composer skeleton:install`, `deploy/release.sh` and the split matrix; two are globs now, and the matrix still names `core` alone because publishing a second package is a decision rather than a consequence of tagging. A roadmap line nobody trusts is worse than one nobody wrote
- [x] CI: Pint, PHPStan level 6, and Pest across PHP 8.4/8.5 × SQLite/PostgreSQL/MySQL/MariaDB. **All nine jobs green 2026-09-07**, before MariaDB joined the matrix (ADR-024's amendment says why); thirteen jobs today, each with a timeout above its slowest green run. The engine matrix is not decorative — `TestCase` selects its connection from the environment and `EngineMatrixTest` round-trips against whichever driver is configured
- [x] Playwright browser job — 4 smoke tests against the skeleton, one browser, Node confined to that job ([#7](https://github.com/adamgreenwell/kitsune/issues/7)). When Phase 4 lands the admin, CONTRIBUTING's standing regression test (a page loaded from *outside* `/c/{type}`) goes here
- [x] ⚠️ **Bare-clone guard** — a CI job declaring no service containers at all, running the default suite on PHP 8.4 and 8.5. **Passed on first run**, so ADR-024's pillar-three mitigation is verified rather than promised. If it ever goes red the fix is never to add services to it, but to fix the test that reached for one
- [x] `laravel/boost` as a **dev** dependency. Never a runtime dependency of `kitsune/core` (ADR-025)
- [x] Kitsune's own guidelines file, encoding the invariants an agent violates by default ([#9](https://github.com/adamgreenwell/kitsune/issues/9)) — `AGENTS.md`, fourteen invariants. Several were added *because* something violated them: `once()` keys, foreign keys in tests, and amending an ADR rather than routing around it
- [ ] **Name clearance before *registering* a logo** ([#1](https://github.com/adamgreenwell/kitsune/issues/1)) — Mozilla's support platform and a Rust ActivityPub project both use "Kitsune".

  ⚠️ **This line used to say "before spending on a logo", and the wording hid a distinction that matters.** Drawing a mark and filing one are different expenditures: the first is cheap to reverse, the second is not. ADR-032 adopts a mark provisionally on that basis and leaves the filing gated here. The choice of mark is also a *clearance input* rather than only a downstream consequence — a distinctive nine-tail fan argues against confusion with the two existing "Kitsune" projects where a generic fox would argue for it

## Phase 1 — Remaining spikes

*1–2 weeks. Cheapest way to find out the plan is wrong.*

The routing question is **already settled** — ADR-012 was resolved by a working instrumented spike, and `ResourceConfiguration` was disproven. What's left:

- [x] **Relation managers under an extra route parameter.** ✅ **Cleared 2026-09-07** on Laravel 13.30.1 / Filament v5.7.8. Both `HasMany` and `BelongsToMany` relation managers work under `/c/{type}`; they register no routes of their own. Two new non-optional requirements fell out — see the ADR-012 amendment
- [x] **`ManageRelatedRecords` pages under `{type}`.** ✅ **Cleared 2026-09-07.** They register their own route and work — `/c/{type}/{record}/related` returns 200, Livewire updates return 200, every generated URL carries a populated `{type}`, and Attach/Detach operate. ADR-012's URL contract has no untested corners left
- [x] **Generated-column parity across Postgres, MySQL and SQLite.** ✅ **Cleared 2026-09-07.** `SchemaDriver` plus three implementations; the same parity suite passes on all three engines. SQLite takes VIRTUAL rather than STORED, which inverts the cost model in its favour. `values` is reserved on two of the three and must be quoted
- [x] **Accessibility and RTL audit of Filament v5** — ✅ **2026-09-08.** Written up in [`accessibility-inventory.md`](accessibility-inventory.md); ADR-018 amended with the result. ⚠️ **The screen-reader pass remains open and cannot be automated** ([#12](https://github.com/adamgreenwell/kitsune/issues/12)).

  Automated axe scanning at WCAG 2.1 A + AA across five admin page shapes — dashboard, entry list, create, edit, related records — reports **zero violations at any impact level**, including minor and moderate. That is a strong inherited baseline from Filament, and it now runs on every CI build so it cannot silently regress.

  **RTL is now verified by rendering it, and Filament passes.** The admin is served under `APP_LOCALE=ar` on a second server and compared against the LTR render of the same page: an element `n` pixels from the left edge in LTR must sit `n` pixels from the **right** edge in RTL. Drift was **0 on all five layout landmarks across three page shapes** at 1280px, with zero axe violations under RTL and no horizontal overflow. The sidebar is positioned with `inset-inline-start`, so the browser does the mirroring.

  ⚠️ **The blocker this entry recorded did not exist, and that is the lesson.** It said an RTL render check "needs the locale switcher that does not exist yet". `dir` comes from `__('filament-panels::layout.direction')`, so `APP_LOCALE` alone decides it — one `curl` disproved a claim that had parked half a spike. Reasoned, not measured; Standing Principle #9.

  ⚠️ **An earlier version had also implied RTL was verified when it was not.** That test visited the English site and asserted `dir="ltr"`, which would stay green even if every RTL layout in the admin were broken. The replacement can fail: forcing `direction: ltr` while leaving `dir="rtl"` moves the drift from 0 to **1216**.

  The **readiness** proxy stays alongside it, because it measures something the render check cannot — the mirror test compares five landmarks, so it sees direction failing at the document level and not a stray physical offset on an inner element. **535 logical properties against 139 physical, about 79% direction-agnostic.** A first count reported 18 physical because it missed bare `left`/`right`, `border-left`/`right` and `text-align`.

  **What is inherited versus built — the question ADR-018 asked:** almost everything, because Kitsune currently authors **one** Blade view and **zero** lines of CSS. With Filament cleared, every remaining RTL gap is ours, and they are enumerated rather than assumed absent: the skeleton's own page emitted no `dir` at all (**fixed**, `Kitsune::textDirection()`); `sites.locale` is applied by nothing, so direction resolves per process and one install cannot serve an RTL and an LTR site concurrently; and `dir` appears **exactly once** in Filament's whole view layer, so no field input carries `dir="auto"` and content always renders in the direction of the chrome.

  **One half stays open and cannot be automated:** whether the entry editor is *usable* with a screen reader, and whether the mirrored admin actually *reads* to someone who reads Arabic or Hebrew
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
- [x] ⚠️ **Resource-floor benchmark (ADR-027)** — first run **2026-09-07**, re-measured **2026-09-18** and now reproducible on demand with `bin/benchmark-floor.sh`. Measured with **1,000 entries in scope**, which matters — see the corrections below.

  **What a recorded figure depends on, and which of it is pinned.** Four inputs decide the numbers, and a figure can be re-checked exactly only when all four are the ones it was measured with:

  | input | recorded for these figures | pinned by |
  |---|---|---|
  | the interpreter | the floor image: PHP 8.4.25 with ext-intl (ICU 76.1) and ext-zip — CLI, `memory_limit=128M`, OPcache off | [`bin/benchmark-floor.Dockerfile`](../bin/benchmark-floor.Dockerfile): its base at the full digest `php@sha256:a545b9041fb0e378cb597b4d0509f77c6a4d996dd485763af92e5b7e59c469cc`; the ICU and libzip that apt installs are **recorded, not pinned** |
  | the dependency graph | lock `sha256:4733b982a17afd70`, resolved for PHP 8.4.25 — laravel/framework v13.32.0, filament/filament v5.8.2, livewire/livewire v4.4.5 | [`docs/benchmarks/floor.composer.lock`](benchmarks/floor.composer.lock), the graph these figures were measured with |
  | `kitsune/core` itself | the tree of the commit that last changed that lock | the checkout — the harness installs core from `packages/core` |
  | the host | not recorded | nothing; wall-clock is the host's, and peak memory is the part that transfers |

  So a figure is re-checked with the commit that recorded it checked out, and:

  ```bash
  bin/benchmark-floor.sh --entries 1000 --lock docs/benchmarks/floor.composer.lock
  ```

  — the floor image is built from its Dockerfile when no `--image` is named. Re-run that way on 2026-09-19: the same interpreter, ICU 76.1, the same lock hash, 40.5 MB and 12 workers in both columns.

  ⚠️ **For eleven days the floor was measured on an interpreter that could not run the application.** The official `php:8.4-cli` loads neither ext-intl, which `filament/support` requires, nor ext-zip, which `openspout/openspout` requires; the benchmark booted regardless, because its samples call neither. It surfaced on #126 when Composer was made to resolve for the image's PHP rather than the host's, and then — with `platform-check` on — refused the install outright. The harness now builds the floor image, resolves Composer for its PHP version, and runs Composer's own platform check *in the image* before anything boots, so an image missing an extension the graph requires is a refusal naming it rather than a measurement. With both extensions loaded the figures did not move — which says something about the metric as much as the image: `memory_get_peak_usage()` counts PHP's heap, not memory a native library such as ICU allocates for itself.

  ⚠️ **A default run does not pin the graph, on purpose.** Without `--lock` the harness resolves the graph fresh — which is what an operator installing today gets, and so what the floor is actually a claim about. A different number from a default run means the application or its dependencies changed; that is the regression the floor exists to catch, not a failure to reproduce. Every run names the graph it measured (the lock's hash and those three versions) in its header, and `--save-lock` keeps it. The digest is recorded whole because a truncated one pulls nothing (Codex, #126), and the lock because the digest pins the interpreter and not the application (Codex, #126).

  | | constrained (1 vCPU / 1024 MB) | unconstrained |
  |---|---|---|
  | framework bootstrap peak | 40.5 MB | 40.5 MB |
  | peak serving a request | 40.5 MB | 40.5 MB |
  | workers fitting in half the floor | 12 | 12 |
  | list page (25 rows) | 2.0 ms | 1.9 ms |
  | entry with relations | 2.3 ms | 2.1 ms |

  **Peak memory while serving is the part that transfers between machines**, while wall-clock is a property of the host.

  ⚠️ **These numbers are not comparable with 2026-09-07's, because the measurement was wrong then and is different now.** Not a regression and not an improvement — a different quantity. Three defects were found on 2026-09-18 by re-measuring, and all three are fixed:

  - **The reported peak included the benchmark seeding its own rows — and moving the sample did not remove it.** `memory_get_peak_usage()` was taken *after* `ensureVolume()`, so the figure grew with `--entries` and was printed as the cost of booting the framework. The first fix sampled bootstrap before seeding and took the serving peak after `memory_reset_peak_usage()`, and Codex found on #126 that this cannot work: the reset moves the recorded mark down to what the process still holds, and PHP keeps the heap an insert grew. Measured: a serving peak of 40.5 MB after seeding 100 entries and 42.5 MB after 1,000 or 5,000, for requests reading the same 25 rows. The harness now **seeds in one process and measures in another**, and refuses a measurement whose process seeded; the command reports how many entries each run inserted, and warns when a run's peak includes them. Measured that way the serving peak is 40.5 MB at 1,000 entries and at 5,000 — the framework's own footprint, which the 25-row samples fit inside.
  - **The scope line was the request echoed back, not an observation.** `ensureVolume()` returned the `--entries` argument it was handed, so "content in scope: 1,000 entries" could not disagree with it — and neither could the test named for the `WHERE 1 = 0` defect, which passed with `setSite()` deleted. It now returns `Entry::count()` through the scoped model, so a run that lost its site context reports 0 and the harness refuses it. Proven by deleting that line and watching the case fail.
  - **The old two-column gap was a confound.** 38.5 vs 40.5 MB compared a container against the dev machine, measuring two PHP builds as well as two limit sets. From one image, with a fresh copy of the application per run and only the limits changed, the peaks are identical.

  ⚠️ **What the pairing does and does not prove.** It is a *control*, not a stress test: neither limit binds one request — a PHP CLI process uses at most one CPU anyway, and 40 MB of 1024 MB is not pressure — so identical columns are the expected result, and a difference would mean the two runs differed in something other than their limits. The workers figure remains arithmetic from a single request, not an observation of twelve running at once.

  `Kitsune::FLOOR_VCPU` and `FLOOR_MEMORY_MB` are asserted by a test, so raising the floor is a visible code change rather than a drift — and `tests/Core/Release/FloorHarnessTest.php` runs the harness against stub binaries and reads the `docker` argv it builds, so the container size, the constants and the recipe the command prints to operators cannot drift apart.

  **Still open:** the same measurement under concurrency; under an FPM-shaped interpreter (OPcache on, a real `php.ini`) rather than bare CLI; and a worker's resident memory rather than PHP's heap — the workers figure divides the floor by the heap peak, and a real worker also holds the PHP binary, its extensions and whatever ICU loads, none of which that peak counts

**Done when:** you have numbers, written down.

## Phase 2 — Tenancy kernel, fail-closed

*5–7 weeks. Build before anything that could get it wrong.*

ADR-012 removed the boot-order collision structurally — the route table no longer depends on org or site state. What remains is enforcement.

- [x] `Org`, `SiteGroup` and `Site` models; `Context` carrying the current org and site (ADR-021). Deliberately Filament-independent — core is headless-capable, so the API and console get the same enforcement
- [x] Site resolution middleware — `SetKitsuneContext` mirrors Filament's resolved tenant into Kitsune's own `Context`, which is what the global scopes read
- [x] **`#[SiteScoped]` / `#[OrgScoped]` / `#[OrgScopedThroughPivot]` / `#[Unscoped]` mandatory on every model.** Undeclared throws at boot. **Fail closed**.

  The fourth arrived with [#21](https://github.com/adamgreenwell/kitsune/issues/21) and is recorded as an ADR-021 amendment: a user belongs to many orgs, so `OrgScope`'s `org_id = current` had no column to compare. ⚠️ And the attribute is a **declaration, not an enforcement** — it does nothing unless the model also `use`s `EnforcesScope`, which `User` did not for two phases
- [x] `EnforcesScope` applying the right global scope from the attribute, and stamping the scope key on create
- [x] ⚠️ **`OrgScope`, Kitsune-authored, for `#[OrgScoped]` models.** Filament gives them no scope at all
- [x] Settings resolution: org → site group → site — sparse overrides, shallow merge, provenance on every value (ADR-022). ⚠️ The admin rendering of that provenance is **not built** — the panel exists, but it has no org, site group or site resources to show it on; see Phase 3's settings store
- [x] `entry_type_availability` — per-site entry types on the same sparse inheritance as settings; resolution batched so navigation costs one query rather than one per type
- [x] Resolved-config memoisation, invalidated on write. ⚠️ **Ticked before it was true.** Until Phase 3's settings store landed on 2026-09-18 the resolver was bound nowhere and `forget()` was called only by its own test, so nothing invalidated on write — a sweep found the tick. What holds now: one memo per request (`scoped`), dropped automatically after every write through Eloquent to an org, site group or site — exactly the level written and the sites beneath it when the write is that model's own save, and the whole memo for a bulk, relation or escape-hatch write and for every delete, whose rows the builder cannot name — and by a transaction rollback. A write below Eloquent (`DB::table()`, `toBase()`, raw SQL) is not seen. The cross-request per-site cache ADR-022 also asks for is **deferred** (ADR-022 amendment)
- [x] `scopedUnique()` / `scopedExists()` — go through Eloquent so global scopes apply, and are wired into the entry form. Laravel's `unique` would tell one org that another holds a slug; its `exists` would accept another org's id and let the app write a reference to it
- [x] **`IdentifyEntryType` middleware, `isPersistent: true`** — validates `{type}` exists, belongs to the current org, *and* is enabled for the current site (ADR-022), 404s otherwise, and sets `URL::defaults(['type' => ...])`. Verified in a browser as an authenticated user: own type 200, global system type 200, another org's type **404**, unknown type **404**
- [x] Reserved type-handle list, **enforced** at save time rather than merely known — the exception says why, since the collision is with the URL contract and escaping cannot fix it
- [x] Every composite index leads with its scope key — `site_id` for `#[SiteScoped]` models, `org_id` for `#[OrgScoped]` (ADR-021), as written in the tenancy and schema migrations
- [x] **Two deliberately hostile test groups** — cross-site within one org, and cross-org, both written from the attacker's side. Includes the org-shared case, where a bare `site_id IS NULL` check would expose every org's shared media

## Phase 3 — Kernel

*4–6 weeks.*

- [x] Module registry: discovery, enable/disable, ordering — **[ADR-038](decision-log.md)**, landed 2026-09-21.
  Discovery reads what Composer installed; a `modules` row is the receipt and `kitsune:module` is the switch.
  Absent means disabled. ⚠️ **Dependency resolution is struck rather than scheduled**: it is Composer's, and the
  kernel adds none. Ordering is registration order and is explicitly *not* a contract, so nothing pins it beyond
  determinism
- [x] Module manifest with a **mandatory `scoping:` declaration**, in the package's own `composer.json`; the kernel
  refuses to load without it, and refuses a declaration the module's own models contradict — **[ADR-038](decision-log.md)**
  renamed the key from `tenancy:` (AGENTS.md §1) and made its vocabulary the one the scope attributes use

  **The check asks the query, not the label.** A model must carry the attribute, `use EnforcesScope`, AND compile
  a query that differs with its global scopes from without them — because two adversarial passes demonstrated
  seven bypasses of the weaker forms, the second round breaking the first round's fixes. A no-op scope filed
  under the right key, and a genuine scope stripped again in `newQuery()`, both satisfy every check that reads
  the registry rather than the SQL.

  ⚠️ **It cannot tell `unscoped:global` from `unscoped:through(M)`** by looking at a model: both are
  `#[Unscoped]`, and the difference is whether the table carries a scope column. Declaring both at once is
  refused rather than papered over
- [x] Hook/event system with a documented naming convention — **[ADR-038](decision-log.md)**, landed 2026-09-21.
  `ModuleEnabled` and `ModuleDisabled`, `final readonly`, observation only. **Two, not six**: after the v1.2
  freeze an event may be added and may not be removed, so shipping fewer is the reversible direction.

  ⚠️ **They are the only observability a host gets for a switch that changes the running application**, and that
  is a schema fact rather than an oversight: `audit_log.org_id` is `NOT NULL` (ADR-020), an installation-level
  act has no org, and `Auditor::record()` would record nothing. 🟡 Nothing in core listens to a kernel event
  yet, so the convention is a rule and two dispatches rather than a demonstrated integration
- [x] Install/upgrade/uninstall lifecycle with migrations and rollback — **[ADR-038](decision-log.md)**, landed
  2026-09-21. One command, `kitsune:module`, on the precedent that this repo splits commands by blast radius
  rather than by verb. Install verifies against the module's real models and leaves the switch **off**; enabling
  is a separate act, so an interrupted install cannot leave code running. Uninstall refuses while the module is
  enabled, then asks the **module** whether it may go — core cannot know what a module's content is, so it asks,
  and nothing is rolled back before that refusal
- [x] Settings store backing the org → site group → site resolution — **[ADR-022](decision-log.md), amended 2026-09-18**

  `SettingsResolver` is bound per request with defaults from `packages/core/config/kitsune.php`, which a host overrides with its own `config/kitsune.php`. `SettingsWriter` sets a key at an org, site group or site and reverts one by **removing** it, recording one audit row per change — the action and the level, never the key or the value (ADR-020). A change is recorded from the write's effect, so a repeat that stores nothing new records nothing. Invalidation is automatic for every write through Eloquent: `ScopedBuilder` drops exactly the level written and the sites beneath it after that model's own save, so a plain `$site->update(['settings' => …])` invalidates as the writer does, and drops everything after a bulk, relation or escape-hatch write and after any delete. `timezone` is validated on `saving` against the identifiers PHP lists (`DateTimeZone::listIdentifiers()` — the canonical IANA names, without aliases such as `US/Eastern`), and `settings` is a per-row column on all three models, so the bulk and quiet paths that would skip the check are refused under any spelling of the column — which also means a bulk `insert()` of orgs or site groups is refused now. Not refused: a write below Eloquent, or a bulk write inside `withoutScopeBecause()`; a value either stores blocks every later save of its row until it is reverted, and is refused by name rather than used to format a date.

  **The first consumer is timezone.** Every date and time the admin lists or takes is shown and entered in the current site's timezone, through `SiteTime` — a field holding several included — and stored UTC as before; with no site, the platform default. The dashboard's relative "Updated" column prints a duration, which is the same in every zone. A `date` is never converted — it lists in a `Cell::Date` of its own — because a date is not an instant.

  🟡 **Still open, and not claimed:** what the picker does with a wall-clock time the site's zone repeats or skips — today the second of two is saved as the first, even by an untouched save, and a skipped one moves forward silently — and with an open form whose site changes zone before it is saved; whether an org or site group change's audit row should carry the level's `site_id` rather than the actor's; the provenance admin UI with one-click revert that ADR-022 requires — the resolver carries the provenance and the writer is the revert, but there are no org, site group or site resources to put the screen on yet; the cross-request per-site cache, **deferred** until a measurement asks for it; `locked_keys`, ADR-022's own open question; encrypted setting values, which `kitsune/support` waits on (ADR-036); and folding `entry_type_availability` into the resolver, which still resolves on its own
- [x] RBAC: roles, permissions, per-org assignment. Permissions named `entry.{type}.{action}` — **[ADR-033](decision-log.md), [#81](https://github.com/adamgreenwell/kitsune/issues/81)**

  🟡 The **org-scoping half was done first** ([#21](https://github.com/adamgreenwell/kitsune/issues/21)). `User` is `#[OrgScopedThroughPivot]` through `org_user`, because membership is many-to-many and `OrgScope`'s `org_id = current` never applied.

  **Kitsune authors this rather than adopting `spatie/laravel-permission`**, and the decisive reason is AGENTS.md invariant 2: a vendor model cannot carry `#[OrgScoped]` or `use EnforcesScope`, and the package's `teams` feature models one scoping axis where Kitsune has two that scope data. ADR-033 states the cost — we own the resolution cache and its bugs.

  Three tables: `roles` and `role_permissions` in core because they are org-owned configuration, `role_user` in the skeleton because it references a `users` table core did not create. **A permission is a string**, not a foreign key, on ADR-015's argument applied to a different subject — authorization asks reverse questions constantly, and a JSON blob answers none of them. The cost of a string is a typo that stores and is never held, so `Permissions::validated()` fails closed on shape and on a registry of actions.

  ⚠️ **`entry.*.{action}` is a grant somebody writes, never one a role gets by default**, and it resolves at check time rather than being expanded at grant time — expanding it would cover exactly the types that existed when it was written, which is the one thing it is for not doing.

  ⚠️ **Two guarantees rest on argument rather than on a column, so both are asserted from the attacker's side.** Every read starts at `Role`, the only scoped model in the layer — `RoleIsolationTest` asserts that a direct `RolePermission` query sees every customer's grants, so the cost is visible rather than remembered. And **a role assignment is not membership**: `role_user` knows nothing about orgs, so resolution also asks membership through the user model's own scoped query. Removing that check fails a test.

  ⚠️ **Two things the vocabulary cannot express are owner-only, and that is a limitation rather than an omission.** `architecture.md` publishes five actions on **entries**, so editing the schema and administering roles have no permission to ask for — review found the entry-type builder still open to a copy-editor after this landed. Adding a subject widens the extension surface that Standing Principle #1 keeps shut until v1.2, so an org cannot delegate either without making somebody an owner.

  ✅ **Role administration landed 2026-09-13** ([#84](https://github.com/adamgreenwell/kitsune/issues/84)): a `RoleResource` in core defines a role, its grants and who holds it, owner-only and refused at the URL rather than merely unlinked. Grants and assignments are written through `Role::grant()` / `assignTo()` — the audited path — and as a **diff**, so the log records the change rather than the save.

  ⚠️ **#84 said assignment would live in the skeleton, and that was reversed on evidence.** Core owns no user model and still does not; it asks the **panel's** auth provider, which is the same lesson review taught about the membership check. Putting the screen in the skeleton would have needed an extension point in core's navigation before the extension API exists.

  **Still open:** blueprint seeding, which Phase 5 depends on.

  ⚠️ **The attribute had never been enforced.** `User` carried `#[Unscoped]` and did not `use EnforcesScope`, so it was labelled correctly and completely unconstrained — a model can pass the declaration sweep and still be globally readable. AGENTS.md invariant 2 now says so.
- [x] Audit log — **actor, action and target only, never payloads** (ADR-020), so erasure can reach everything it must.

  **What is absent from the table is the design**, and a test asserts the column list *exactly* — adding `changes`, `before`, `after` or `payload` fails the build rather than passing review on a busy day. `Auditor::record()` takes an action and a target and has **no parameter for a diff**, which is a stronger guarantee than a convention everybody agrees with and someone eventually breaks.

  Written at the **query builder**, not from the admin, so the API and the console are audited by the same code path — an audit trail that only covers the UI is one with a documented hole. Model events alone were not enough: `Entry::query()->update()` and its siblings compile straight to SQL and dispatch nothing per row, so the bulk path was untraced. They were also not *safe* alongside it, since `$entry->save()` is itself a builder write and recorded everything twice. One choke point, and the builder tells the cases apart on its own — a soft delete is an update that sets `deleted_at`, a restore is one that clears it.

  The actor is NULL when the system acts on its own; attributing a scheduled prune to whoever happened to be logged in would be a lie in the one place that must not hold one.

  🟡 `erasure_log` (ADR-020 primitive 5) has its table and stores the target and the **replacement**, never the original — enough to replay on a restore, disclosing nothing. Wiring it to `redactField()` waits on the erasure work in [#30](https://github.com/adamgreenwell/kitsune/pull/30)
- [x] One hardcoded entity type end to end as a normal module, to prove the stack — **`packages/person`**,
  landed 2026-09-21. Monorepo-only: no mirror, no Packagist entry, and the split matrix is untouched, so it
  ships to nobody until that is a decision somebody makes.

  **It ships no models and no migrations**, which is the point rather than a shortcut: a person is an entry,
  because `Permissions::ACTIONS` has one subject and a module cannot ask for its own before v1.2. The whole
  footprint is an `entry_types` row and a `field_storage`/`fields` pair per field — so `scoping: []` is a claim
  the verifier can check by sweeping the package and finding nothing to scope.

  Crossed in a browser by `e2e/person-module.spec.js` (AGENTS.md §9), which loads the **dashboard** rather than
  the module's own page, because ADR-024's incident was URL generation across page boundaries.

  🟡 **Recorded rather than worked around:** `EntryResource` hardcodes `title`, `slug` and `status`, so a person
  is created under a required "Title" and offered a slug. Nobody's title is their name. Fixing it means either a
  per-type vocabulary on the platform columns or a module-supplied form, and it is the clearest argument yet
  that people-as-entries is a starting point rather than an ending one

## Phase 4 — The schema engine

*4–7 months. The hardest thing in the project, and where solo projects die.*

Data model is specified in [`architecture.md`](architecture.md) §3.

- [x] `entries` table: one `Entry` model, type discriminator, JSON values, promoted `title`/`slug`/`status`. Landed in Phase 2 as the substrate the tenancy kernel needed something to scope — ADR-010's one-model-per-Resource constraint makes the single model forced rather than chosen. `type_handle` is denormalised for routing and re-stamped on save, so it cannot drift from `entry_type_id`
- [x] `entry_types` / `field_storage` / `fields` — the Drupal storage/config split. Storage is defined once and reusable; `fields` carries the per-type presentation
- [x] **`field_storage.pii_class`, fail-closed** — an unclassified field does not save (ADR-020). Enforced in a `saving()` guard rather than by a NOT NULL default, because a default would silently classify everything as `none`
- [x] Entry types designate a **subject identifier** field, so "everything about this person" is answerable — `entry_types.subject_field_id`, with `Entry::subjectValue()` and `Entry::whereSubjectIs()`.

  **Deliberately not fail-closed**, unlike `pii_class`: the subject *is* one of the type's fields, so requiring the nomination before the first personal field can be added is circular. What is enforceable is visibility, so `EntryType::withoutSubjectIdentifier()` lists types holding `personal` or `sensitive` data with nothing nominated — exactly the holes a subject-access request cannot see. The entry type list's Subject column asks the same predicate, so it warns about those types and shows a neutral state for a type that holds no personal data — rather than warning about every type with nothing nominated, which on a fresh install was every type. Two things do fail closed: a nomination pointing at another type's field is refused on **create, update and field reassignment** (answering a request with somebody else's data is worse than answering with nothing), and `whereSubjectIs()` on a type that nominates nothing returns **no rows rather than all of them**. The holes report is org-scoped explicitly, since `EntryType` is `#[Unscoped]` and a compliance report is the last place to leak another customer's schema.

  All **three** storage strategies work: an inline field read from `values`, a **relation** read through `entry_relations`, and a **promoted** field read from its own column. The first implementation handled one, the second two — and each gap returned null, which is indistinguishable from "no subject nominated"
- [x] **Field-level redactable revisions** — erasure must reach revision history, because article revision 4 still holds the name just erased. `Entry::redactField()` sweeps the entry and every one of its revisions, replacing in place so the history of *what changed when* survives an erasure of *what it said*. It returns the number of rows rewritten: an erasure that silently reached nothing is otherwise indistinguishable from one that worked. A relational field has no value to replace, so erasing it detaches the `entry_relations` rows instead — the array-key path found nothing there and reported success while every link survived
- [x] **`is_locked` guard from day one** — storage definitions lock once data exists. `type` and `cardinality` are the locked shape; toggling the index stays allowed, because that is expensive rather than unsafe
- [x] **Field type registry** — twelve v1.0 types, each answering storage + API. ⚠️ **This line previously claimed "storage + form + table column + API", and the two UI faces do not exist**: `FieldType` has never declared a form or table method, so no type answers either. Tracked as [#39](https://github.com/adamgreenwell/kitsune/issues/39), with the seam settled by ADR-029 — a type names a *kind* of control and the panel builds it. ⚠️ **This line previously named `media` and `repeater`, and `field-types.md` had already ruled on both**: media are entries (§5), so a media picker is `relation` constrained to media entry types rather than a field type of its own, and `repeater` is deferred to v1.1 (§4) because nested groups mean recursive validation, no meaningful indexing and ugly revision diffs. The shipped twelve are text, textarea, rich text, number, boolean, date, datetime, select, multi-select, relation, slug and JSON
- [x] **Generated-column indexing** driven by `field_storage.is_indexed`, behind the Postgres/MySQL/SQLite driver abstraction — `SchemaManager`, green on all three engines. Indexing is refused with a reason for promoted, relational, non-indexable and multi-value fields, and capped at 20 generated columns on the shared `entries` table.

  ⚠️ **A cross-org defect was caught before it shipped and cost an ADR.** The first implementation named the column `idx_{handle}`, but `entries` is one table shared by every org while `field_storage` is `UNIQUE (org_id, handle)` — so two orgs each defining `price` would collide silently, one casting the other's data to the wrong type and either able to drop the other's column. Columns are now named for their projection — `idx_price__decimal12_2`, `idx_count__integer` — and dropping is reference-counted ([ADR-028](decision-log.md)). Two amendments followed, both from review: the shared expression also has to be **total**, since it reads its JSON key from every row in the table (unguarded, another org's `"contact us"` stops the column being created on PostgreSQL and MySQL and indexes as `0` on SQLite), and the projection depends on **configuration**, so the column name carries a signature rather than the field type handle. `php artisan kitsune:schema-sync` is the drift repair path, since DDL implicitly commits on MySQL and a row write cannot share a transaction with its schema change
- [x] `entry_relations` table — a real table, not JSON, so reverse lookups and referential integrity work. Org-scoped rather than site-scoped, because a relation may link a site entry to org-shared media
- [x] `EntryResource` with the `{type}` route parameter, per ADR-012 and [`architecture.md`](architecture.md) §2. 7–10 routes flat in the number of entity types, verified in a browser including the 201-type case
- [x] Memoised navigation, cached per site *(Filament calls it 5× per request)*. The memo lives on `EntryType::visibleFor()` rather than on the closure itself, which is where the query actually is — and its `once()` key is the scope IDs, not the objects. ⚠️ Keyed on a `Site` **object** first, and a probe showed PHP reusing the object handle on the very next allocation, so two different sites shared one cache entry. AGENTS.md invariant 13 exists because of it
- [x] `EntryPolicy` resolving per-type authorization against `type_handle` — ✅ **2026-09-13**, once Phase 3's RBAC existed to resolve against ([#81](https://github.com/adamgreenwell/kitsune/issues/81)). It was blocked rather than deferred, and writing it earlier would have meant inventing a permission model here and reconciling it there.

  With a record in hand the type is the **record's own** `type_handle`, never the request's — a record reached through a URL for another type is exactly the confusion an attacker would arrange. Without one it is the type `IdentifyEntryType` bound into the container, already validated as existing, belonging to this org and enabled for this site. **No type at all means no.**

  `restore` and `forceDelete` resolve against `delete`, because neither is in the published vocabulary and both operate on a deleted row — mapping them to `update` would let an editor who may not delete an entry resurrect one, or erase it permanently.

  ⚠️ **Three enforcement points, and the link is not one of them.** Navigation is filtered by `view` in `KitsunePanel`, because navigation is supplied explicitly and one resource serves every type — but hiding a link an authenticated user can still reach by typing the URL is the classic shape of this bug. `e2e/permissions.spec.js` asserts the **403 at the URL** as well as the absent link, each proved to bite by removing the code it covers.

  ⚠️ **`publish` has an enforcement point, because AGENTS.md #14 forbids publishing a constraint that is not enforceable.** `EntryResource` withholds the `published` option from the status control *and* refuses the value in validation, both derived from one method so they cannot disagree. `archived` stays available deliberately: withholding the whole control would take a different action with it, and `archive` is not one of the five the vocabulary names.
- [x] **Entity type builder UI** — `EntryTypeResource` plus a fields relation manager that writes both halves of ADR-006's storage/config split from one form, and a `SettingsSchemaRenderer` that turns a field type's `settingsSchema()` **data** into Filament components. That last piece is why the method returns an array rather than components — the seam, now recorded as ADR-029: a field type describes a control and the panel builds it, so adding a field type needs no admin code at all. ⚠️ The reason cited here used to be ADR-002 and "core stays headless-capable". Core hard-requires `filament/filament ^5.4` (ADR-008), so dependency avoidance was never what the rule bought; what it buys is a single renderer through which every control passes.

  ⚠️ **Three defects the PHP suite structurally could not see, all found by opening a browser** — the same shape as the ADR-012 spike, and why ADR-024 makes the browser layer mandatory. The list page 500d on Filament's tenant scoping (the tenant is a Site; schema is org-owned). The create-field modal 500d because the registry fails closed and the form's empty initial state called `get('')`. And a `number` field silently defaulted to **integer** — suppressing the select's placeholder made the browser submit the first option, so a field declared `decimal` would have truncated every value.

  A fourth was found writing the browser tests: the field list is a **lazy** Livewire component, so at 1280×720 it sits below the fold and never mounts. The spec scrolls, as a user does
- [x] **Revisions and drafts** — a revision per saved version, recorded after the write and only when something versioned changed. Revisions snapshot the promoted columns alongside `values`, because a revision holding only `values` restores an entry with no title, which is worse than having no revisions at all.

  **Restoring adds a version rather than rewriting history**: restoring version 1 leaves versions 2 and 3 in place and appends version 4. Rewriting would make *what did this say last Tuesday* unanswerable, which is the question revisions exist to answer. There is deliberately **no delete** — erasure goes through `redactField()`, which replaces in place so the history of *what changed when* survives an erasure of *what it said* (ADR-020).

  Retention is capped at `Entry::KEEP_REVISIONS` (50). The decision log's *revision storage growth — full-JSON snapshots get expensive; consider diffs* is still open; a bound applied on write is the honest interim answer, and diffs would raise that number rather than remove it.

  ⚠️ **Two defects found by clicking it.** `wasRecentlyCreated` stays true for the lifetime of an instance, so every later save on a freshly created entry recorded another revision — fixed by hooking `created` and `updated` separately. And a restore left the **pre-restore values sitting in the form**, so the next save wrote them back and silently undid the restore the user had just watched succeed

**Done when:** a non-developer builds a working "Products" entity with ten field types, relations and permissions entirely through the admin, on 100k rows, with no query over 200ms.

**Where that stands.** The entity, its field types, its relations and its indexing are done and driven from the admin — the builder above is what makes that sentence true rather than aspirational. **One** thing is not, and the reason changed rather than went away: **permissions**.

They are *enforced* — [ADR-033](decision-log.md) and `EntryPolicy` landed with [#81](https://github.com/adamgreenwell/kitsune/issues/81), and a user without a grant is refused at the URL rather than merely shown no link. And since [#84](https://github.com/adamgreenwell/kitsune/issues/84) they are *administrable*: an owner creates a role, grants it per entry type and assigns it to a member of the org without leaving the panel, so handing somebody permission no longer takes a developer or a deploy. **The criterion's *entirely through the admin* is met for permissions.**

⚠️ **The distance is not only a form.** `roles` is org-owned configuration and belongs in core; assignment writes `role_user` against the host application's `users` table, which core does not own — so the assignment UI belongs in the skeleton, and **neither half is useful alone**. #84 carries that decision.

⚠️ **And that last sentence was wrong, which is recorded rather than edited out.** Core owns no user model and still does not — but it does not need one: `Permissions::userModel()` asks the PANEL's own auth provider, which cannot be wrong about the model it loads. So the screen lives in core after all, the skeleton keeps only the `role_user` migration, and no extension point had to be opened in core's navigation before the extension API exists (Standing Principle #1). ADR-033 carries the reversal and the evidence for it.

⚠️ **This paragraph used to say three, and two of them were stale — [#80](https://github.com/adamgreenwell/kitsune/issues/80).** It called revisions "the last unchecked line of the checklist" after that line had been ticked, and it said the 100k-row measurement was missing because `benchmark-storage` only measured the ADR-027 floor — confusing it with `benchmark-floor`, while the 100k table sits twenty lines up this same document. Prose describing the state of something else, not derived from it.

✅ **The 100k-row / 200ms half is measured, 2026-09-13**, via `php artisan kitsune:benchmark-admin` — a third benchmark, and the first to issue **requests** rather than queries. Every admin page shape at 100k entries on SQLite, warmed, with query counts beside the wall-clock:

| page | page ms | queries | slowest query |
|---|---|---|---|
| dashboard | 74.4 | 13 | 38.59 |
| entry list, page 1 | 59.9 | 17 | 18.89 |
| entry list, last page (offset 99,990) | 91.7 | 17 | 24.49 |
| entry create | 30.3 | 15 | 0.69 |
| entry edit | 31.9 | 19 | 0.10 |
| entry view | 29.4 | 16 | 0.10 |
| related records | 27.9 | 15 | 0.12 |
| entry type builder | 30.6 | 13 | 0.15 |

⚠️ **And it found a defect the query benchmark could not, which is the whole argument for it.** `benchmark-storage` reported the list page fast at 100k rows — probing `order by published_at`, which nothing in the admin orders by. The entry list orders by `updated_at desc` and **nothing indexed it**, so every request paid a full sort of every row in the site. The list query alone, median of five:

| | page 1 | offset 99,990 |
|---|---|---|
| without the index | 22.51 ms | 153.65 ms |
| with `(site_id, entry_type_id, updated_at)` | **0.07 ms** | **16.65 ms** |

Through real requests the last page went from **214.6 ms to 91.7 ms**, and the slowest statement on the list page is now the pagination `count(*)` rather than the sort. A benchmark measuring a query the application does not issue is a benchmark that agrees with you.

The index is three columns rather than four: covering the `id` tiebreak as well was measured and made no difference outside noise, and a fourth column on the hottest table in the schema costs write throughput for nothing. ⚠️ The first corpus hid that, because it stamped every row with the same `now()` — so `updated_at` discriminated nothing, the ordering fell entirely to `id`, and the four-column index looked necessary. The benchmark spreads the corpus over time now.

`EntryListSortIsIndexedTest` keeps it true: it reads `EntryResource::DEFAULT_SORT` rather than naming a column, so changing the sort fails the build until an index covers the new one.

⚠️ **The dashboard row was re-measured when the dashboard got something to say, 2026-09-14.** It had no widgets, which is why it was the cheapest page in the table. It now counts each entry type the reader is offered and lists the ten most recently updated entries: 13 queries rather than 11, and 74.4 ms rather than 18.8. Measured back to back on one corpus, the widgets alone take it from 20.4 ms to 74.4 ms.

Its slowest statement is the per-type count, 38.6 ms. `count(*)` grouped by type and status reads every entry on the site, because neither `deleted_at` nor the site scope's shared-entry branch is in an index, so it grows with the site and is the first thing on this page to revisit beyond 100k entries.

The recent entries were the other half. They cross types, so `(site_id, entry_type_id, updated_at)` could not deliver them in order and every dashboard load sorted every entry on the site: 23.92 ms. With `(site_id, updated_at)` it is 0.18 ms. That index was weighed against the write cost the paragraph above turns a fourth column down for, and measured rather than assumed: +4.0% on a 20k-row bulk insert and +1.0% on an `Entry::create()` save. `RecentEntriesSortIsIndexedTest` holds it to `RecentEntriesWidget::SORT`.

**What the command does not measure, said plainly:** sorting and searching from the table's own controls, which Livewire drives over POST rather than through a URL, and browser render time. It measures server cost for every page shape a GET reaches.

## Phase 5 — Blueprints

*3–4 weeks. Do not skip — this is what turns the schema engine into a product.*

Drupal spent ~a decade proving a runtime schema engine *without* opinionated starting configurations is harder to use than a fixed-schema CMS, then shipped "Recipes." Skip their decade.

- [ ] Blueprint format: portable bundle of entity types, fields, roles, permissions, settings, seed content
- [ ] Apply/install flow, idempotent and reversible
- [ ] First-party: **Blog**, **Marketing Site**, **DAM Starter**

  The **Marketing Site** blueprint has a named first user: `kitsunecms.org` itself, per [ADR-030](decision-log.md). The project's site waits for that blueprint rather than being stood up on a static generator, so its gaps land on the maintainer before they land on anyone else. **This line is the trigger, not a tag:** ADR-030 moves the site when the blueprint applies cleanly and idempotently to a fresh install at the floor, and the result is editable through the admin. The site is also the first thing to stand on ADR-027's resource floor for real, and it runs the **self-host** path rather than KaaS deliberately.
- [ ] Blueprints are a **kernel primitive**, not a module

**Done when:** fresh install to working blog is one click, under 60 seconds.

## Phase 6 — v1.0 hardening

*6–8 weeks.*

- [ ] Security review, especially every tenancy boundary
- [ ] Documentation site — explicitly *not* settled by [ADR-030](decision-log.md), which covers the marketing site only. Search, versioning and deep cross-linking may or may not be a Kitsune workload; that wants evidence rather than symmetry
- [ ] Semantic versioning commitment and published upgrade policy
- [ ] Staffed security disclosure process
- [ ] **One-command self-host installer** (ADR-026) — paste one command on a fresh Ubuntu LTS box, end at the onboarding screen:
  - [ ] **Native path** (recommended default, ADR-027) — PHP-FPM + SQLite at the resource floor, with the distribution's own PHP where it meets `^8.4` (26.04 ships 8.5) and packages.sury.org where it does not (ADR-026 amendment)
  - [ ] **Docker path**, fully supported and equal in quality, reusing the Phase 0 CI image — for scale, reproducibility, or anyone preferring a container to a system PHP
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

## v1.1 — API and theming, reader accounts and the privacy tooling

*4–6 months, grown from 2–3.* [ADR-036](decision-log.md) and [ADR-037](decision-log.md) added the chat module and
reader accounts, and the three [ADR-020](decision-log.md) deliverables below were promised for v1.1 and missing from
this list. Per **Honest timeline** at the end of this file, the phase absorbs the work and the estimate moves with it,
rather than the estimate standing still while the work grows underneath it ([ADR-011](decision-log.md)).

- [ ] REST API generated from schema, site-scoped, per-entity permissions
- [ ] Token auth + scopes (Sanctum); consider Laravel 13's first-party JSON:API resources
- [ ] Blade theme layer: template hierarchy, theme discovery, per-site selection
- [ ] Menus, routing, slugs, redirects
- [ ] Media library + Flysystem
- [ ] Caching, correctly scope-keyed (org and site)
- [ ] **Reader accounts** ([ADR-037](decision-log.md)) — their own guard, provider and model, provided by the host; registration, sign-in and recovery. A reader is not a panel user, and `canAccessPanel()` returns true for every row of the one that exists
- [ ] **Consent records, subject-access export and erasure tooling** — [ADR-020](decision-log.md) promised all three for v1.1 and this list omitted them, which is how a privacy promise quietly becomes a later one. `erasure_log` has its table and no writer
- [ ] **`kitsune/support`** ([ADR-036](decision-log.md)) — the chat module: per-Site link, widget injection through the theme layer above, signed visitor identity, webhook receiver. Prototyped against stage, and merged only once Phase 3's registry and settings store exist, since its settings hold secrets core cannot yet store encrypted

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
