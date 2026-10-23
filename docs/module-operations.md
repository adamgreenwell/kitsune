# Module uninstall and interrupted teardown

`php artisan kitsune:module disable PACKAGE` changes future admission. It does not unload providers from
already-running web processes, workers or scheduled commands. Before destructive uninstall, stop every writer
and every process that can still execute the module, block incoming work, and serialize lifecycle commands,
code changes and cache generation. Keep them stopped through verification or recovery.

Take a complete, consistent pre-uninstall database backup and the matching code, Composer lock/dependencies,
configuration and owned assets. Verify the backup by restoring it to an explicitly approved disposable target.
If a module owns another database or external assets, its coordinated backup/restore procedure must cover
those too. A successful SQL dump alone does not prove consistency with another store. Do not begin destructive
teardown without that procedure. This work authorizes disposable local qualification only; production recovery
requires its own authorization and target verification.

## What the command records

The data hook, receipt removal and a separate `module_uninstalls` marker commit together. Schema reset runs
afterward, outside that transaction: MySQL and MariaDB DDL can commit implicitly. A completed reset must also
remove the ledger entries for the module's current migration files before the marker is removed. If any of
those steps fails, or the process stops after the transaction commits, the marker remains. The module command
reports incomplete uninstall rather than success. `php artisan kitsune:module status` lists that state even
without a receipt and exits unsuccessfully while any marker remains.

Install, enable, disable, upgrade and another uninstall refuse a marked package. Kernel boot excludes it from
runtime and retained-data command registration even if an enabled receipt is planted beside the marker.
`Module::isEnabled()` also refuses admission. A marker-query failure cannot be treated as absence: lifecycle
reads fail, and ordinary kernel boot logs the database failure and registers no modules. Apply core migrations
before expecting module admission after a code upgrade; a missing marker table is not permission to boot.
The marker-table migration refuses rollback while any marker remains, and a failed guard query also refuses
rollback. Core rollback/reapply is not a way to clear an interrupted uninstall.

The native release and isolated Coolify startup keep their pre-migration cache/serving preflight and rebuild
the explicit caches after migration, followed by another serving check. An old schema without the marker
table suppresses module boot, so caches built on that schema cannot be the final caches for activation.

This marker is evidence of unfinished work, not an inventory of every table a module might own. Arbitrary
PHP `down()` methods can lie, omit old files or affect other stores. Core does not infer schema health from
their names, inspect every module's columns, or make partially committed DDL retryable. The supported recovery
here is restoration, not an automatic retry or a force/acknowledge command. Historical interrupted uninstalls
from before this marker existed cannot be discovered or repaired just by applying its migration.

## Recovery on a verified disposable target

1. Keep all writers and old module processes stopped. Record the exact failure, package version, marker,
   receipt and migration ledger. Do not run reinstall or enable to test whether the schema is healthy.
2. Verify the target is the approved disposable database and that the complete pre-uninstall backup and
   matching code/assets belong to this installation. Restore into an empty replacement database, or use the
   engine's verified complete-database replacement procedure. Do not overlay selected receipt or ledger rows
   onto a partly dropped schema. Preserve the failed target separately when an incident procedure requires it.
3. Restore the matching configuration, code and assets as one recovery point. The complete database restore
   restores physical schema, data, migration ledger and original receipt together; its pre-uninstall marker
   table has no marker for this attempt. Deleting the marker by hand proves none of these things.
4. Compare the restored table set, columns/indexes/foreign keys, expected data counts and fingerprints, ledger
   and receipt with the recorded backup witness. Check fresh-process module status and the module's own data
   invariants before resuming any work. A healthy restored disabled receipt stays disabled. An incomplete
   restore, unavailable backup, changed migration/code set or uncoordinated external store is unsupported by
   this procedure; remain stopped and establish a module-specific recovery plan.
5. A repeat restoration must reproduce the same recovery point. It cannot recover legitimate writes made
   after the backup: keeping writers quiescent is a requirement, not a courtesy. Fix and qualify the failing
   migration before planning another uninstall. Restart persistent processes only after separate acceptance.

## Measured boundary (#264)

At `ed51194`, a real installed Composer fixture has two synthetic data tables and an empty ordinary content
table. Its `down()` drops the first table, then tries to drop a deliberately missing table. SQLite 3.53.4,
MySQL 8.4.7 and MariaDB 10.6.28 retain the first drop and lost synthetic row, keep the migration recorded and
lose the receipt. The old lifecycle then accepts reinstall and enable despite the absent table. PostgreSQL
17.11 rolls that file's DDL back through Laravel's actual migrator, preserving both data tables and rows;
receipt removal remains committed. Transactional DDL support in an engine is not proof that a migrator wraps
it: Laravel's SQLite grammar does not open that per-file transaction.

A separate disposable experiment backs up the complete 42-table pre-uninstall database, reproduces failure
and mistaken reinstall/enable, and restores twice on each engine. The second restore follows another deliberate
synthetic-row deletion. Both restores match table sets, column/index/foreign-key fingerprints, every table's row
counts/fingerprints, the migration ledger and original disabled receipt. SQLite uses an online backup to memory;
PostgreSQL uses `pg_dump`/`psql`, MySQL `mysqldump`/`mysql`, MariaDB `mariadb-dump`/`mariadb`. Only backup hashes,
sizes and procedural witnesses are retained; no database export is retained. All disposable schemas, containers,
owned volumes and private probe roots were audited and removed. These are synthetic database recovery results,
not production recovery, external-asset recovery, arbitrary migration repair or a general backup guarantee.
