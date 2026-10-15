# Credential changes, recovery and application-key rotation

Run commands from the installed application's directory with its usual PHP version and environment. In this
monorepo that directory is `skeleton/`. These tools change stored credentials and mode only; they do not contact
Stripe, Shippo or another provider. Payment checkout and verified payment-event handling remain separate work.

## Changes in the admin

An org owner can change test credentials normally. Setting, replacing or removing a live credential requires the
owner's current local password, even while the org is in test mode. Changing a mode-neutral credential while the
org is live also requires it. Entering live mode requires it; returning to test mode does not. The writer decides
under the org lock, so leaving a form open cannot preserve an earlier, weaker requirement.

Confirmation applies to one operation. A remembered login or Laravel's earlier password confirmation does not
satisfy it. Passwords and credential values travel through native POST forms outside Livewire; neither is returned
in a snapshot, old input, notification or HTML response. A missing, incorrect or changed password refuses the
write. Ownership, the authenticated account, scope and the password hash are checked again before commit.

An SSO/passwordless owner cannot confirm a password-required web operation. There is no fallback to a session
age or an SSO claim. Recover a local password through the host's own account recovery, or have an authorised server
operator use the tools below. These tools do not reset an administrator's password or grant an owner role.

Exclude request bodies and URL query strings for all `…/credentials/set`, `…/credentials/remove` and
`…/credentials/mode` endpoints from host request logging, error reporters and developer recorders. Kitsune scrubs
its parsed request, cached body and query before application work; upstream middleware and proxies run earlier.
Never paste a credential into a URL. If that happens, rotate it at its provider because logs outside Kitsune may
already hold it.

## Server recovery

Every command requires an explicit org slug. A command opens a one-use authority for exactly that org and operation;
being a CLI process alone gives the writer no permission. No signed-in administrator is impersonated or attributed
by the tools. Commands restore the previous Org/Site context on success or failure.

```sh
php artisan kitsune:credentials:set commerce.shippo-api-token --org=example --mode=test
php artisan kitsune:credentials:remove commerce.shippo-api-token --org=example --mode=live --force
php artisan kitsune:credentials:mode test --org=example --force
```

The set command uses a hidden terminal prompt and refuses an echo fallback. For automation, pipe one bounded line
from a secret manager to the same command with `--stdin --no-interaction`. Never put the value in an argument,
environment variable, shell history, an `echo` command, or a committed script. Omit `--mode` for a declared
mode-neutral slot. Only enabled modules' declared slots may be set or removed; re-enable the owning module through
its normal lifecycle if a replacement is needed. Remove and mode changes require explicit `--force` confirmation, including interactive use; authorised
noninteractive maintenance also adds `--no-interaction`.

Changing application mode does not revoke credentials at their provider, cancel provider work or reconcile a
payment. Before a future live checkout implementation is enabled, its recovery rules for pending payments and
historical events must also be implemented. These credential commands do not supply those rules.

## Rotating APP_KEY

Back up the database and its matching application keys securely before rotation. A database backup without the key
that sealed its credentials cannot recover their values. Keep keys out of source control, tickets and deploy logs.
`APP_KEY` also protects Laravel data, so credential rotation alone does not prove that every other encrypted cookie,
session or host value has migrated.

1. Configure the new `APP_KEY` and retain the old one in `APP_PREVIOUS_KEYS`, following the host's secret deployment
   process. Refresh cached configuration and restart persistent workers so every process uses the intended keys.
2. Run `php artisan kitsune:credentials status --strict`. It reports counts only: current key, retained previous keys
   and unavailable keys. The release script runs this check after migrations and before promotion; unavailable
   key tags abort the release. It includes retained credentials from disabled modules and deleted orgs.
3. For each org, run the bounded command below. It inspects at most 100 rows by default, at most 1000 when requested.
   Each changed row is resealed and audited in its own transaction. The value and its `changed_at` remain unchanged.
   Current-key rows and tombstones are skipped, including when rerun.

   ```sh
   php artisan kitsune:credentials:reencrypt --org=example --limit=100
   php artisan kitsune:credentials:reencrypt --org=example --limit=100 --after=123
   ```

   Use the last completed row ID printed by the prior batch, for the same org. Rotation includes undeclared and
   disabled-module slots and soft-deleted orgs. A failure stops at the unreadable/unwritable row; earlier completed
   rows stay committed. Keep previous keys, repair the problem, and resume after the last completed ID. An interrupted
   batch can be rerun safely; a replaced credential is read freshly under the lock before rotation.
4. Re-run the installation-wide status check. Retain every previous key while any stored credential still uses it.
   Also retain the keys required by backup retention and other Laravel encrypted data. Only remove keys through a
   separately authorised host maintenance change. Repeat the strict check after that configuration change.

Status counts key tags without decrypting. A successful status check proves key availability, not ciphertext
integrity, provider validity, account identity or payment readiness. Rotation authenticates each envelope against
its stored org, slot and mode; malformed or misfiled ciphertext refuses that row without printing it.

If a previous key is lost, restore it from the secure backup. If that is impossible, replace the affected declared
credential with a newly issued provider value; a tombstone or a missing key cannot be decrypted into the old value.
Restore backups into an isolated environment with their matching keys, apply the host's erasure replay procedure,
and rerun rotation/status before serving traffic. Restoring a database can bring back old-key rows, so retain the
necessary backup keys even after the current installation reports zero previous-key credentials.
