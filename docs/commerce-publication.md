# Commerce installation and publication

`kitsune/commerce` is an optional first-party module. This foundation adds no catalogue, business tables, routes,
payment dependency or money-moving path. The default skeleton still requires core and person alone. Having the
commerce package in Composer's vendor directory does not install or enable its module receipt.

## Development and the skeleton

From this monorepo, `composer install` installs commerce as a development dependency through the `packages/*`
path repository. Its `kitsune/core: self.version` requirement makes Composer resolve the same version of both
packages. Updating the path package requires updating both lock entries together:

```sh
composer update kitsune/core kitsune/commerce --no-scripts
```

For an opt-in local skeleton, run:

```sh
composer skeleton:commerce
```

This runs the existing development skeleton setup with commerce temporarily added to the requirements and restores
the original skeleton manifest on success or failure. It keeps the resulting development lock/vendor state. Like
`composer skeleton:install`, this setup generates the skeleton's application key: use a disposable local development
skeleton, never an existing deployed installation holding encrypted credentials. A pre-existing `composer.json.orig`
backup is refused so an earlier interrupted setup is not overwritten. The default `composer skeleton:install`
continues to install the default skeleton.

After the skeleton has its database, choosing the module is a separate operator action:

```sh
php skeleton/artisan kitsune:module install kitsune/commerce
php skeleton/artisan kitsune:module enable kitsune/commerce
php skeleton/artisan kitsune:module status
```

Disabled commerce remains inert. No Laravel package auto-discovery entry registers its provider. `kitsune:module`
owns the migration directory and lifecycle; ordinary `artisan migrate` does not install a module's schema. This
foundation has no business tables to migrate. The catalogue and its storage arrive in their own slice.

## Destructive teardown limits

Disable commerce and stop writers before uninstall. Retained rows, including another org's records and trash,
refuse teardown; so do schema/read failures. On a MySQL/MariaDB server that folds table names, a non-ASCII
connection prefix also refuses, even when empty. Identifier folding differs from PHP and SQL lowercasing;
[#263](https://github.com/adamgreenwell/kitsune/issues/263) tracks support for proving those physical names safely.
Keep the data and receipt rather than changing the prefix or bypassing the check.

A failed multi-statement `down()` on MySQL/MariaDB can commit partial DDL while its migration stays recorded.
Reinstall is not a universal repair. This existing kernel risk is source-derived, not reproduced in this slice;
[#264](https://github.com/adamgreenwell/kitsune/issues/264) owns the reproduction and recovery procedure. This
foundation has no production migrations. Before later destructive rollback, take a recoverable backup and inspect
any interrupted state before enabling again.

## Pinned monorepo releases

`deploy/release.sh` preserves its default runtime graph unless the deployment environment explicitly supplies
`KITSUNE_INSTALL_COMMERCE=1`. Only `0` and `1` are valid; the default is `0`. The choice is installation configuration
that the operator must preserve on every deployment, including a replay of the same commit. Do not omit it from a
commerce installation's next release. This is a build input, not a reader-facing feature switch and not a module
receipt.

With `1`, the release-local skeleton manifest adds `kitsune/commerce:@dev` before resolving dependencies. The existing
`packages/*` path repository mirrors the selected packages into the release, rather than symlinking them. Before any
Laravel boot or schema migration, the release verifies that required first-party packages are present, that every
installed first-party package names this checkout's path source and contains its source files (apart from Composer-excluded `.gitattributes` metadata), and that commerce and
core have the same Composer version. A missing, symlinked, changed-source or mismatched package refuses the release.
Installing the package does not run `kitsune:module install`, enable a receipt or activate the release.

The opt-in must be identical for the stage rehearsal and its promoted release. Stage/Forge host changes, deployment
and activation need their own explicit authorization. This document authorizes none of them. The release body's
existing shared-state, exact-commit, production-configuration and serving checks still apply; do not use the local
skeleton setup command as a deployment command.

## External Composer availability is a separate gate

A local `packages/*` install or a successful copied-path release does not prove that an operator outside this
monorepo can download the same packages. The live split workflow currently publishes **core only**. This slice does
not change that matrix, create mirrors, register Packagist packages, push tags or publish a release.

For a future external installation, the dependency inventory is:

| Installation | Packages that must be externally resolvable |
| --- | --- |
| A host app choosing commerce | Matching `kitsune/core` and `kitsune/commerce` versions |
| The current published-shape skeleton choosing commerce | Core, commerce and its existing `kitsune/person` requirement |
| An installation also choosing SVG sanitisation | The above plus optional `kitsune/svg-sanitizer` and its declared dependencies |

Person and SVG sanitiser are first-party code already in the monorepo. Neither is added to the publishing matrix by
adding commerce. Recheck their actual public availability before planning a release; their local path resolution is
not that evidence. The skeleton's own `kitsune/kitsune` distribution also needs an explicitly chosen publication
route. Do not mistake the core split mirror for an app-skeleton publication.

Prepare these artifacts before asking for publication approval:

1. Record the candidate monorepo commit and proposed common release version. `self.version` makes the same version of
   core a dependency of commerce, so publishing commerce without that core version leaves it unresolvable.
2. List the required mirrors, Composer names and current public versions. Decide which packages this release will
   publish, without automatically adding every `packages/*` directory or test fixture to the matrix.
3. Prepare the reviewed split-matrix change and least-privilege credential/access plan. Mirrors begin empty and remain
   read-only contribution mirrors; reuse the existing non-force, immutable-tag split script and its tests.
4. Prepare the Packagist registration/update configuration, dependency publication order, failure/retry plan and exact
   commit/version checks. Do not put tokens, webhook secrets or credential-bearing URLs into issues or logs.
5. Obtain explicit authorization for mirror/account changes, registration, tags and publication. Each is a distinct
   action; a merged foundation PR supplies none of those permissions.
6. After authorized publication, inspect the remote mirror tag and Packagist metadata, then install the selected
   versions into a fresh external host with no monorepo path repository. Verify version equality and kernel discovery,
   and exercise the lifecycle on an authorized disposable SQLite app. For the skeleton variant, also prove person
   resolves publicly. Record exactly which variant was tested.

No fixed version or publication date is selected here. Use the approved candidate/version in the future run, and
keep unavailable packages as an explicit external-install blocker.

## What the local evidence covers

`CommercePackagingTest` runs the real Composer solver offline against path packages using commerce's actual manifest.
A matched pair installs as copies; a different core version is refused. It also executes the development helper with
successful and failing child setup processes and verifies exact manifest restoration. `ReleaseScriptTest` runs the
release body in a disposable checkout, including opt-in/default behavior, invalid inputs and package-origin/version
refusals before Laravel starts. Its Composer stand-in tests the release guard and step ordering; it is not evidence
of public Composer availability or of a deployed host.

A real opt-in development skeleton install and module lifecycle readback are additional evidence. Public package
resolution, stage/Forge deployment and human acceptance remain separate gates, recorded only when performed.

For an installed application, [commerce operations](commerce-operations.md) covers module switches, route-cache
invalidation, persistent workers and retained catalogue data. Installation does not itself enable commerce.
