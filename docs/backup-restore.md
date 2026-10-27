# Back up and restore

## Keep a recoverable set

A database file alone is not a recoverable installation. Keep a consistent database backup, private uploaded
media and other host-owned storage, the application's code/dependency versions and configuration, and the
matching application keys. Store secrets through the host's secure backup process, separate from public
artifacts, source control, tickets and build logs. Include the host's erasure replay records and recovery plan.

`APP_KEY` is backup-critical: stored credentials are encrypted using a key derived from it. Retain every
`APP_PREVIOUS_KEYS` value required by any retained backup and other encrypted Laravel data. Never generate a
new key as a shortcut for restoring an old database. A credential status count can prove key availability;
it does not prove ciphertext integrity or provider readiness.

For SQLite, use a consistent database backup mechanism or stop writes through the host's maintenance procedure;
copying an actively changing main file and ignoring its journal/WAL is not a restore plan. For a separate engine,
use its supported consistent backup tooling. This guide does not introduce a universal backup command, retention
period or a new automated backup product.

## Rehearse in isolation

1. Restore into an isolated environment with no public traffic or provider business actions. Use the matching
   source/dependency versions, database, storage and keys. Keep mail and payment processing disabled or in a
   deliberately isolated test configuration.
2. Verify database integrity and representative Org/Site content, access boundaries, media bytes and credential
   key availability. Apply the host's erasure replay procedure: restoring a backup can bring erased readers and
   their data back. The [reader export and erase instructions](../README.md#reader-accounts) explain that boundary.
3. Follow the [upgrade guide](upgrades.md) if the target code differs. Reconcile media through its documented
   read-only inspection path before any separately authorised repair. Record actual verification results and
   remaining failures; a restore command acknowledgement is not recovered service.
4. Expose the restored installation only after the operator has verified the application and host gates and
   authorised that cutover. A successful isolated rehearsal does not authorise production promotion.

The [credential operations guide](credential-operations.md#rotating-app_key) contains the exact bounded rotation,
strict status and previous-key retention procedure. Commerce exports and privacy obligations have their own
[operator guide](commerce-exports.md); an export does not replace a full backup, and a backup does not replace a
complete subject export.
