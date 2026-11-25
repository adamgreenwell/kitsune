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

## Docker on a local engine

Use the reviewed [Docker profile](../deploy/self-host/docker/README.md) on a local Unix-socket
Docker context with a standard named volume. The controller checks the stopped origin container,
its data mount, and both exact image IDs. It binds the approval to the signed release manifests
and the volume's persistent identity. Keep the stopped origin container through publication or
recovery. A legacy commit-only receipt also requires the original image from the verified build.

Build the target with `deploy/self-host/docker/build.sh`. Put the following files in a dedicated
control directory readable by UID 33 inside the container. The helper mounts the directory
read-only. Keep signing keys elsewhere and restrict access to the rehearsal database.

| File | Input |
| --- | --- |
| `origin.manifest`, `origin.sig`, `target.manifest`, `target.sig`, `release.pem` | Both signed releases and the trusted release public key |
| Target archive under its signed basename | The same application archive used to build the target image |
| `pre-migration.sqlite` | The verified pre-migration snapshot from the isolated rehearsal |
| `approval.pem` | The independently trusted approval public key |
| `selection.json` | Release versions, channels, key fingerprints and the archive basename |
| `approval.json`, `approval.sig` | The generated request and its detached approval signature |

```json
{
  "origin_version": "v1.0.0",
  "origin_channel": "stable",
  "target_version": "v1.0.1",
  "target_channel": "stable",
  "release_fingerprint": "<release public key sha256>",
  "approval_fingerprint": "<approval public key sha256>",
  "target_archive": "kitsune-v1.0.1-linux-arm64.tar.gz"
}
```

Stop the origin container and all external writers. The controller refuses external queues.
Use the full container ID and image ID from `docker inspect` and `docker image inspect`. Keep the
`sha256:` prefix on the image ID. Choose a new custody volume with room for the SQLite and media
backup. Keep an encrypted off-host copy of the verified backup and allow space for a separate
restored copy.

```sh
upgrade=deploy/self-host/docker/upgrade.sh
docker volume create "$custody"
"$upgrade" prepare-volume "$target_image" "$custody"
"$upgrade" request "$origin_container" "$target_image" "$data" "$custody" "$controls" > "$controls/approval.json"
```

Have the approval owner review the rehearsal and sign the emitted bytes with the approval key.
Store the detached signature as `approval.sig`, then migrate and stage with ingress disabled.

```sh
"$upgrade" migrate "$origin_container" "$target_image" "$data" "$custody" "$controls"
"$upgrade" stage "$origin_container" "$target_image" "$data" "$custody" "$controls"
```

Migration holds the entrypoint's volume lock, verifies the backup against the rehearsal, fences
startup, then runs core and module migrations. The JSON readback reports the journal phase and
backup location inside the custody volume. Use `abort` for a recorded interruption before the fence
while the origin receipt still matches. If the fence was written, use recovery before opening
traffic. Repeat `stage` after an interrupted receipt handoff.

Start a temporary container with the exact target image and application volume, using
`--network none` and no published ports. Attach the read-only certificate mount if the persisted
origin uses HTTPS. Record private readiness, then record the traffic boundary before replacing the
temporary container with one that publishes the app's loopback port.

```sh
"$upgrade" confirm-private "$origin_container" "$target_image" "$data" "$custody" "$controls" "$private_target"
"$upgrade" begin-traffic "$origin_container" "$target_image" "$data" "$custody" "$controls" "$private_target"
docker stop "$private_target"
published_target=$(docker run -d --pull never --restart unless-stopped --name kitsune-upgraded \
  --publish "127.0.0.1:${KITSUNE_PORT:-8080}:8080" \
  --mount "type=volume,src=$data,dst=/var/lib/kitsune,volume-nocopy" "$target_image")
"$upgrade" confirm-traffic "$origin_container" "$target_image" "$data" "$custody" "$controls" "$published_target"
```

Add the certificate mount to the published container for HTTPS. Published readback verifies
`/up` and `/admin/login` on the inspected loopback port using the persisted hostname and TLS
verification where applicable. Keep any external proxy closed until the checks pass. From
`opening_traffic` onward, finish with the signed target and `confirm-traffic`. Writes may already
exist in the upgraded database.

For recovery before `opening_traffic`, stop all containers using the upgrade volume and create
a fresh, unattached volume. Recovery verifies the copy before writing the selected origin image's receipt. A versioned origin image can restore an earlier source-only receipt as its matching versioned receipt.
Keep the upgrade volume for inspection. Start the original image privately on the restored volume
and verify both health routes before routing traffic to it.

```sh
docker volume create "$restored_data"
"$upgrade" prepare-volume "$target_image" "$restored_data"
"$upgrade" recover "$origin_container" "$target_image" "$data" "$custody" "$controls" "$restored_data"
```

To reattach the restored install in Compose, keep the original service stopped. Use the exact
origin image and an override with `volumes.data.external: true` and `volumes.data.name` set to the
restored volume. Verify the rendered mount before starting. Use new custody for a later upgrade
from the restored volume.

## Request approval for a rehearsed native upgrade

After a native rehearsal, retain its **pre-migration** SQLite snapshot and the staged target that was
verified and extracted from the signed archive. Keep both outside the running application. For
`request-native-admission`, use the same manifest and archive options as `plan` and add these options.

| Option | Input |
| --- | --- |
| `--staging` | The uninstalled target tree in a sibling directory of the current installation |
| `--pre-migration-snapshot` | The standalone SQLite origin snapshot from the rehearsal |
| `--journal` | An existing mode-`0700` directory for the installation identity and upgrade journal |

The command checks the release plan, rebuilds a reference tree from the verified target archive,
compares the staged tree to that reference, and fingerprints the pre-migration SQLite snapshot.
It prints an approval payload that includes the exact target archive digest. The installation
identity is tied to the canonical `--state` path. Retain the payload exactly as printed for offline
review and detached RSA-SHA256 signing.

Before maintenance, run `verify-native-admission` with the same release, staging and journal inputs. Omit the request's `--pre-migration-snapshot` option.
Use `--approval` for the original payload, `--approval-signature` for the detached signature,
`--approval-key` and `--approval-fingerprint` for the independently pinned approval key, and
`--attempt-nonce` from the approved request. Verification prints the exact plan ID.

The native installer accepts the same upgrade phases with an `upgrade` prefix, for example
`install.sh upgrade inspect --mode=native --installation=/opt/kitsune --state=/var/lib/kitsune`.
A regular installer rerun verifies the requested release. An installed release returns success
without rerunning package setup or migrations. A different release requires the signed upgrade
path described above.

### Migrate a fenced native installation

The native migration phase is for the dedicated Ubuntu installer with code at `/opt/kitsune`, state
at `/var/lib/kitsune`, and the private journal at `/var/lib/kitsune-upgrades/journal`. Prepare a
root-owned private backup directory at `/var/lib/kitsune-upgrades/backups`. Use a trusted checkout
with its Composer dependencies installed.

Close ingress and stop scheduled commands and other application writers. Persistently mask and stop
both `nginx.service` and the PHP-FPM service selected in the managed Nginx configuration. A
persistent systemd mask survives a reboot. Use `migrate-native` with the verification inputs, plus
`--backup` pointing to a fresh name under the backup directory. The command checks both masks
under the installer lock, verifies the backup and origin ledger, then runs the staged application's
migrations as `www-data`. Installed module receipts must match the origin manifest and have a
package in the target manifest and staged Composer inventory. After core migrations, changed modules
run through the existing module upgrade command. The phase checks the new receipt versions and
preserves each module's installed timestamp and enabled setting. A change to the module's installed files
requires a changed Composer version so the target can defer that module's provider until its
upgrade finishes. A lower module version or a switch between development and versioned module receipts
requires a separately qualified transition. Earlier development bundles that record `dev-main` with a commit-specific signed
version require an installer-specific transition before using this path with installed modules.

The command leaves services fenced after the `migrated` phase. Run `stage-native` with the
verification inputs to move the target into place and retain the previous application. If staging
stops between renames, repeat the same command with the same approval and staging path.

Run `confirm-native-private` with the signed approval and release manifest inputs. The command
starts short-lived PHP-FPM and Nginx services on private Unix sockets and checks `/up` and
`/admin/login`. The installed service units remain masked. The journal advances to `activated`
after both checks pass, the probe services stop, and the fence is checked again.

Use `recover-native` with the original signed approval, both manifests and the backup path printed
by migration while services are still fenced. Omit `--staging` and `--target-archive`; recovery uses
the archive digest in the signed approval and the retained local code. Before migration begins, it records the abort without replacing state. Once
migration has begun, it verifies and restores the backup and retains the failed state and code
for diagnosis. Recovery refuses an attempt that has reached traffic publication.

After the private check, run `publish-native` with the same inputs as `confirm-native-private`.
It records `opening_traffic` before unmasking either service, starts PHP-FPM and Nginx, and confirms
both health routes on the installed loopback listener. If publication stops partway through, repeat
the same signed command to finish forward. If it keeps failing, close ingress and inspect the
installed service configuration and journal before retrying. The recovery command refuses once `opening_traffic` is
recorded, since the new release may already have accepted writes. Target installation qualification
and operator acceptance are tracked under [#218](https://github.com/adamgreenwell/kitsune/issues/218).

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
