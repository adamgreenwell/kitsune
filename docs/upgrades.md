# Upgrade and compatibility

## Choose the instructions for your source

Use the documentation snapshot that matches the code under review or installed on your host. A development
snapshot, a clean CI result and a version label are not a stable-release or security-support promise. The
[security policy](../SECURITY.md#supported-versions) owns the current support statement; issue #211 still owns
the published compatibility matrix and support commitments. No duration is invented here.

Before changing an installation, retain a [recoverable backup and its matching keys](backup-restore.md), inspect
the target release notes and migration requirements, and rehearse the exact change against a restored copy.
Record the actual PHP, database and dependency versions. Check separately whether the host skeleton needs edits:
it is copied at installation, not rewritten by a core Composer update.

## Blueprint changes are bounded

`php artisan kitsune:blueprint status` and the org's recorded blueprint receipt describe what was applied. A
newer blueprint can add compatible structure, but existing fields and operator data are not silently rewritten
to match a template. Reapplying the same version is not proof that an upgrade from another version works.
Read [the blueprint instructions](../README.md#starting-from-nothing) and ADR-039 before choosing an apply.

`php artisan kitsune:schema-sync` reports proposed storage changes; `--force` applies them. Inspect the report
first and use the host's maintenance/backup process before applying changes. The
[field-type guide](field-types.md#7-indexing) explains why storage/index changes differ across SQLite,
PostgreSQL, MySQL and MariaDB. A code rollback does not undo a data migration; restore or reverse only through a
tested procedure that covers the actual data and key state.

## First-party modules, before the extension freeze

`php artisan kitsune:module status` reports module receipts. Installing a Composer package, installing its
module record and enabling it are separate operations. Inspect the
[module decision](decision-log.md#adr-038--a-module-is-a-composer-package-core-keeps-a-receipt-for-and-the-kernel-is-a-record-and-a-refusal)
before changing them. Disabled modules may still own data needed for a complete export or erasure.

The extension surface is deliberately unstable before v1.2. The current named host seams are first-party
exceptions, not a third-party SDK or permission to depend on arbitrary internal classes. Existing reader-model
contracts do not gain mandatory methods during an upgrade; new host capabilities require explicit integration.
The [contribution rules](../CONTRIBUTING.md) and ADR-038/039 retain the exact exceptions and limits.

## Leave with your data

Reader requests have a [scoped export path](../README.md#reader-accounts). Commerce exports have their own
[permission, decryption and delivery boundaries](commerce-exports.md). These are not a generic whole-site
migration export. The [roadmap](roadmap.md) records the remaining portability work; do not infer an unimplemented
command from the project's portability commitment.
