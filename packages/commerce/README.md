# kitsune/commerce

The optional first-party commerce module. This first slice supplies its package and lifecycle foundation;
it does not yet supply a catalogue, cart, orders, inventory writes or Stripe payments. ADR-040 in the monorepo
records the design and delivery order.

The module and core resolve at the same version (`kitsune/core: self.version`). Composer installing the
package does not register its provider: only the kernel may do that, after an explicit installation and
enablement. There is no Laravel package auto-discovery entry.

For a development checkout, use the root's opt-in `composer skeleton:commerce` setup. The stock skeleton
does not require commerce. Once package publication is available, a host adds `kitsune/commerce` at the
same version as its core, then runs:

```sh
php artisan kitsune:module install kitsune/commerce
php artisan kitsune:module enable kitsune/commerce
php artisan kitsune:module status
```

Install leaves the module disabled. Enable takes effect when the next application boots. Disable preserves
data and schema; it does not unload a provider from requests or long-running processes already in flight.
Restart those processes after changing the switch.

The migration directory is wired through `migrationPath()` and currently empty. No placeholder table or
extra receipt is created: core owns the receipt. Ordinary `artisan migrate` does not run module migrations;
the module lifecycle does. Real commerce tables arrive with sellables, and their models must then declare
and enforce Org or Site scope and update the manifest's presently empty `scoping` list.

Commerce reserves table names beginning with `commerce_`. Uninstall inspects every such table on the
receipt's connection, past org/site context and soft deletion, and refuses if any row remains. This includes
future tables and retained invoice records. Read failures refuse too. It never exports or deletes records
on the operator's behalf. On MySQL/MariaDB servers that fold table names (`lower_case_table_names=1` or `2`),
uninstall currently requires an ASCII connection prefix. A non-ASCII prefix refuses teardown even when empty:
the server's Unicode identifier mapping differs from both PHP and SQL lowercasing. Disable keeps the data and
schema; do not guess a different prefix or bypass this refusal to remove retained data.

Uninstall is destructive maintenance: disable commerce, stop and restart its writers, export what must be
kept, and take a recoverable backup before running `kitsune:module uninstall kitsune/commerce`. The existence
check does not serialize teardown against running writes. A failed multi-statement migration on MySQL or
MariaDB can leave partial DDL; a retry is not evidence that the schema is repaired. Restore or inspect and
repair that state before enabling again.

Local path resolution is not a public package release. The publication prerequisites and separate artifact
checks are in [`docs/commerce-publication.md`](../../docs/commerce-publication.md) in the monorepo; no mirror,
Packagist registration, tag or release is created by installation.
