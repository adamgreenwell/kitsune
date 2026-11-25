# Upgrade and compatibility

## Choose the instructions for your source

Use the documentation snapshot that matches the code under review or installed on your host. A development
snapshot, a clean CI result and a version label are not a stable-release or security-support promise. The
[security policy](../SECURITY.md#supported-versions) owns the current support statement. The
[versioning and upgrade policy](versioning-and-upgrades.md) separates core, modules, blueprints and copied host
code, records the latest-stable-minor/latest-patch maintenance model, and requires case-by-case fingerprints
and rehearsal for retained pre-v1.0 data. Its upgrade matrix keeps unqualified paths explicit. No support
duration, transition/EOL date or actual upgrade admission is invented here.

Before changing an installation, retain a [recoverable backup and its matching keys](backup-restore.md), inspect
the target release notes and migration requirements, and rehearse the exact change against a restored copy.
Record the actual PHP, database and dependency versions. Check separately whether the host skeleton needs edits:
it is copied at installation, not rewritten by a core Composer update.

## Inspect a self-host installation

Run the inspection from a trusted Kitsune checkout. Use the filesystem paths for the actual installation.
For Docker, use a read-only view of the persistent volume. The command does not contact Docker or stop services.

```sh
php bin/self-host-upgrade.php inspect --mode=native --installation=/opt/kitsune --state=/var/lib/kitsune
php bin/self-host-upgrade.php inspect --mode=docker --state=/path/to/volume
```

`installed` means the installer receipts and expected layout were found. `incomplete` means there are
unclassified files, missing persistent state or invalid receipts. Keep those files and investigate before
running an installer again. Earlier Docker receipts record only the source commit. A versioned Docker
receipt also identifies the exact release archive and manifest. The local image still needs to be matched
to the volume and verified release evidence.

To check the proposed release, supply its exact application archive and both the origin and target
manifests signed by the pinned release key. The key and its fingerprint come from an independent
trusted source. Pass the target machine's platform explicitly.

```sh
php bin/self-host-upgrade.php plan --mode=native --installation=/opt/kitsune --state=/var/lib/kitsune \
    --platform=linux-amd64 --key=/trusted/kitsune-release.pem --fingerprint="$KITSUNE_RELEASE_KEY_SHA256" \
    --origin-manifest="$ORIGIN_MANIFEST" --origin-signature="$ORIGIN_SIGNATURE" \
    --origin-version="$ORIGIN_VERSION" --origin-channel=stable \
    --target-manifest="$TARGET_MANIFEST" --target-signature="$TARGET_SIGNATURE" \
    --target-version="$TARGET_VERSION" --target-channel=stable --target-archive="$TARGET_ARCHIVE"
```

The plan verifies the signed release identities and the target archive's platform, size and digest. It
reports current receipt identity and any missing verification. Exit status 0 means the native receipt
already identifies the selected release archive. Status 2 means inspection or transition evidence is
still required. Status 1 means the input or release verification failed.

Admission and execution are separate from this check. The plan reads neither the live SQLite ledger
nor the contents of the persisted configuration. It grants no permission to migrate or switch the
installation. Follow the [versioning policy](versioning-and-upgrades.md#which-installations-may-upgrade)
for the exact path, backup and rehearsal evidence.

## Check a retained pre-v1.0 database

The Forge and stage release script inspects the original Kitsune migration records and the affected
live schema before `migrate`. It refuses an incomplete ledger, an unrecorded partial schema and
historical original-table shapes that are missing required columns or constraints. If it refuses,
preserve the database and its keys, inspect a restored copy with the matching
[schema baseline audit](schema-upgrade-baseline-audit.md), and rehearse the case before proceeding.

The check covers the known historical in-place edits. The versioning policy still requires an origin
fingerprint, a recoverable backup and an isolated rehearsal for each retained installation. In a
local rehearsal, populated fixtures created from the exact `v0.1.0` and `v0.2.0` migrations kept all
original values in six tables and their original migration records when upgraded using the current
runtime. The `v0.1.1` migration trees match `v0.1.0` byte for byte. The SQLite, PostgreSQL, MySQL and
MariaDB replays also checked site and org isolation.

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
