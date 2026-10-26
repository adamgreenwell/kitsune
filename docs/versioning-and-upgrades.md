# Versioning, compatibility and upgrades

This policy records Adam's choices for [#211](https://github.com/adamgreenwell/kitsune/issues/211).
Kitsune has 0.x development packages; **v1.0 is not released**. Adopting this document does not publish a
release, admit an installation, qualify an upgrade or authorize a deployment.

## Versions and the contract they cover

At v1.0, stable release numbers describe compatibility with the documented application and operator contract:

- **Patch:** compatible corrections. Security corrections may reject unsafe or invalid behavior previously
  accepted by mistake; release notes must describe the change and required operator action.
- **Minor:** compatible additions to that contract, with documented migration and host-integration steps.
- **Major:** incompatible changes, with an explicit upgrade guide and a separately qualified upgrade path.

A version number is not a promise of zero downtime, an automatically reversible migration or support for
every dependency version Composer can resolve. Existing 0.x packages gain no retroactive stability promise.

| Artifact | Compatibility boundary | What an operator must check |
|---|---|---|
| `kitsune/core` | The declared application/operator contract for the release. Internal implementation remains internal. | Release notes, supported platform matrix, admitted upgrade origin and migration requirements. |
| First-party modules | Each module must declare its compatible core versions and migration requirements. | The exact module/core combination, receipt and enablement state. Composer installation, module installation and enablement are separate operations. Disabled modules may still own retained data needed for export and erasure. |
| Blueprints | The declaration version and installed receipt describe an ADR-039 merge, separately from the containing package's version. | Review the apply against current operator-owned structure and data. Reapplying the same version is not an upgrade test, and reverse is bounded by receipts and current state. |
| Host-owned skeleton | A starting project copied at installation, not a centrally rewritten application. | Merge required changes to models, routes, configuration and other host code deliberately. A core Composer update does not apply them or validate local customizations. |

### The extension freeze is still v1.2

v1.0 does **not** freeze a third-party PHP extension API. Standing Principle #2 and ADR-011 defer that
contract to v1.2. A public method, event or configuration key is not automatically part of it. Existing named
first-party exceptions in [CONTRIBUTING](../CONTRIBUTING.md#things-that-wont-be-merged) retain their exact
limits; the host-owned reader-model contract does not gain mandatory methods during an upgrade.

The v1.2 audit must name the supported surface and resolve accidental exposure before declaring it stable
([#235](https://github.com/adamgreenwell/kitsune/issues/235)). Once frozen, announce a deprecation with its
replacement and migration instructions in release notes and the relevant documentation. **Never remove
deprecated public extension surface within the same major.** The SDK and enforcement work in
[#238](https://github.com/adamgreenwell/kitsune/issues/238) remain open; this policy does not claim they exist.

## Security maintenance

Adam selected **the latest stable minor, at its latest patch**, as the security-maintenance model. This is
one maintained minor line, not a promise to backport fixes to every minor of a major. Operators on an older
patch update to the latest patch; moving from an older minor requires that release's documented upgrade path.

| Version | Current security-support status |
|---|---|
| 0.x development packages, including v0.1.0, v0.1.1 and v0.2.0 | No stable-version security-maintenance commitment. Reports are still welcome through the private reporting channel. |
| v1.0 and later stable minors | Not yet released. No currently supported stable release is declared by this document. |

Before the first stable release, its announcement and [SECURITY.md](../SECURITY.md#supported-versions) must
name the actual supported minor and current patch. Before a later release supersedes a supported minor,
publish its transition instructions and the maintainer's chosen notice/overlap and end-of-support terms.
**No calendar support duration, transition window, EOL date or superseded-major coverage has been chosen.**
Those decisions remain explicit release gates, not dates inferred from a version number or this model.

Private reporting and disclosure follow [SECURITY.md](../SECURITY.md). The existing acknowledgement within
5 working days and initial assessment within 10 working days remain honest targets, not a contractual SLA.
This policy adds no staff or new response guarantee. Actual intake, notifications, continuity and private
coordination still need the human rehearsal in [#212](https://github.com/adamgreenwell/kitsune/issues/212).

## Platforms: declared requirements versus qualified combinations

The current manifests declare the following. A release must publish the exact tested versions separately;
these ranges alone do not prove that every resolvable combination works or that a retained-data upgrade passed.

| Component | Current declaration or test target | Evidence boundary |
|---|---|---|
| PHP | `^8.4`; CI targets 8.4 and 8.5 | A future PHP version satisfying the constraint is not automatically qualified. |
| Laravel | Core `^13.0`; skeleton `^13.17` | Check the resolved graph and host code, not only core's lower bound. |
| Filament / Livewire | Core `^5.6` / `^4.1` | These are package constraints, not a stability promise for their internals. |
| SQLite | Supported default for small installs; CI uses the runner's SQLite | A stable release's matrix must record its actual SQLite version and required features. |
| PostgreSQL / MySQL / MariaDB | CI service images `postgres:17-alpine`, `mysql:8.4`, `mariadb:10.6` | Image tags and green fresh-schema tests do not qualify every advertised engine version or old schema. Record resolved engine versions and upgrade evidence. |

Current module constraints are not a released compatibility matrix: commerce requires core `self.version`,
while person and svg-sanitizer require core `@dev`; the skeleton also requires core/person `@dev`.
Stable artifacts and the installer must declare and qualify their actual package graph before release
([#213](https://github.com/adamgreenwell/kitsune/issues/213)).

Changes to requirements need explicit compatibility review and release guidance. The designed default floor
remains **1 vCPU, 1 GB RAM, SQLite, no container runtime or external services** (ADR-027). A separate database,
cache/search service, Node runtime or always-on worker must not become necessary for default onboarding.
Native and optional Docker installation paths have separate acceptance gates; one does not qualify the other.

## Which installations may upgrade

Adam selected **case-by-case origin fingerprinting and rehearsal for retained pre-v1.0 databases**. No
blanket automatic upgrade support is promised for every 0.x tag, development checkout or migration ledger.
The [schema baseline audit](schema-upgrade-baseline-audit.md) records why: historical edits to an already
recorded migration do not run again, and a tag alone does not identify an installed database's current shape.

An admitted case needs exact installed source/dependencies, core/module receipts, schema and migration-ledger
fingerprints, host changes, a recoverable backup and a successful isolated rehearsal of its origin-to-target
path. [#217](https://github.com/adamgreenwell/kitsune/issues/217) owns the origin-specific repairs and retained
data evidence. The decision here chooses the admission model; it neither implements those repairs nor admits
an existing installation.

Preserve historical unknowns. A current entry type, field mapping or auth provider cannot recreate a missing
revision type, past relation state, promotion owner or audit identity. Refuse operations that cannot be made
safe without that information; do not silently invent history or choose a winning Org for conflicting Site claims.

An operator may rebuild a disposable pre-alpha installation **only after explicitly confirming that its data
need not be preserved**. Pre-alpha status alone is not permission to drop data. An unadmitted retained origin
stays preserved pending inspection and a supported path.

### Upgrade acceptance matrix

These are open evidence requirements, not passing results. A supported row must link exact source/destination,
platform and retained-data results plus its operator procedure. No stable origin-to-target pair exists yet.

| Origin or path | What exists | What is still required |
|---|---|---|
| Retained 0.x or development database | Source tags and the historical schema audit. | Case admission, actual schema/ledger fingerprint, retained-data migration and refusal/recovery evidence on each admitted engine (#217). |
| Fresh v1.0 native installation | The designed recommended default and resource floor. | Signed artifacts, safe first-owner handoff, native install and floor/host acceptance (#213–#215). |
| Fresh v1.0 Docker installation | The optional, equally supported design path. | Its own signed artifacts, first-owner and install/operator acceptance (#213, #214, #216). |
| Stable patch/minor upgrade, native or Docker | This compatibility and support policy. | Exact version pair, backups/restore, retained-data and host-edit checks, migration failure recovery and actual operator acceptance (#218). |
| Module update or blueprint apply | Lifecycle and receipt contracts in ADR-038/039. | Exact compatible package combination and sequenced migration/apply evidence preserving receipts, enablement, retained data and operator choices. |
| Cross-major upgrade | No stable-major pair yet. | An explicit compatibility decision, release-specific guide and independently qualified path; no inferred in-place support. |

The [v1.0 acceptance issue](https://github.com/adamgreenwell/kitsune/issues/220) retains separate code, native,
Docker, host and human gates. A policy PR, a clean CI run or a successful fresh install cannot close those gates.

## Operator procedure and recovery limits

This is the required sequence to plan and rehearse. It is not a new universal upgrade command or a claim that
the unfinished [safe upgrade tooling](https://github.com/adamgreenwell/kitsune/issues/218) already exists.

1. **Identify the case.** Record installed and target code, resolved dependencies, receipts, schema/ledger
   fingerprints and host changes. Read the target-specific release notes. Refuse an origin without an admitted,
   evidenced path; do not substitute its version label for the database inspection.
2. **Keep a recoverable set.** Back up the consistent database, private uploads and other host-owned storage,
   code/dependency versions, configuration and matching application/previous keys. Keep secrets outside source,
   tickets and public artifacts. A database or subject export alone is not a complete installation backup.
   For SQLite, use a consistent backup mechanism or stop writes as the host's procedure requires; copying a live
   main file without its journal/WAL is not a restore plan.
3. **Prove restoration in isolation.** Restore that set with matching keys and apply the host's erasure replay
   procedure, because a backup can resurrect erased data. Verify representative Org/Site content, media bytes,
   access boundaries and key availability. Follow the existing
   [credential and backup-key instructions](credential-operations.md#rotating-app_key). No new application key
   can recover data encrypted with a lost old key. Keep provider business actions and outbound mail disabled or
   within a separately authorized isolated test setup.
4. **Rehearse the exact upgrade.** Use the admitted origin, target, engine, hosting path and host customizations.
   Follow its tested maintenance and migration order. Core schema must be ready before code using it runs;
   modules follow their compatible core and lifecycle sequence. Blueprint changes are explicit separate applies.
   Stop concurrent writers and restart persistent workers as that procedure requires. Do not guess that old
   code can keep writing safely while the schema changes.
5. **Inspect storage changes and failures.** Review field-storage proposals before an authorized schema-sync
   apply. Do not use `migrate:fresh`, uninstall/reinstall or receipt edits to repair retained data. An earlier
   successful migration or partial DDL can remain after a later failure; inspect actual schema and retry safety
   before repeating a failed operation.
6. **Verify before cutover.** Check actual application behavior, isolation/authorization, retained rows, erasure
   replay, media, keys and host conditions. Keep incomplete checks visible. A migration acknowledgement or a
   symlink switch does not establish a recovered service. Authorize host promotion separately.
7. **Recover against actual state.** Code rollback does not reverse database writes, lost columns or already
   applied DDL. Use an inspected fix-forward path or restore the consistent set with matching keys and required
   erasure replay, under the host's tested write/maintenance procedure. There is no general guarantee of lossless
   reversal of destructive migration, restored unknown history, automatic rollback or zero downtime.

The existing [release builder](../deploy/release.sh) runs migrations before its strict retained-credential
key and field-storage gates; its source forbids `migrate:fresh` and replacing `APP_KEY`. It is not evidence
that every origin above can upgrade. The [host runbook](../deploy/runbook/README.md) has separate intrusive
host checks and maintenance-window requirements; reading this policy does not authorize running them.

Installation, backup and upgrade guides proposed in [#339](https://github.com/adamgreenwell/kitsune/pull/339)
are pending, as is the cold module-upgrade correction in [#313](https://github.com/adamgreenwell/kitsune/pull/313).
They are not current-main operator instructions. Link their merged procedures and actual acceptance evidence
when available; do not replace the missing evidence with this document's requirements.

## Release communication and decisions still open

Each release must state its actual artifact versions, qualified platforms and upgrade origins, required host
edits, migrations and recovery limits, security coverage and known unresolved gates. Security fixes follow
coordinated disclosure; ordinary deprecations name their replacement and migration path. Stable extension
deprecations retain the no-removal-within-a-major rule above.

Before v1.0, record the supported stable version and maintainer decisions about support duration and eventual
transition/EOL terms; finish signed artifacts, safe onboarding, platform/upgrade evidence and operational
security intake. None of those decisions is supplied by a 0.x package number, this policy or a documentation
merge. No release, tag, deployment, provider operation or live admission is authorized here.
