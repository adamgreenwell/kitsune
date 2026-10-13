# Operating the commerce module

Commerce is optional and starts disabled after installation. Its current admin screen selects the org's currency;
product administration and public catalogue pages follow in #191 and #192. Prices and stock belong to the org,
while each site's listing connects its own content. See [ADR-040](decision-log.md#adr-040--commerce-is-a-module-entitlements-are-a-kernel-guard-and-v10-waits-for-both)
and [currency maintenance](commerce-currencies.md).

## Changing module state

Run these commands from the installed application's directory, using the same PHP version and environment as the
application. Arrange the change outside a deployment or another route-cache build: a concurrent `route:cache` could
publish routes for the state being replaced.

```sh
php artisan kitsune:module status
php artisan kitsune:module install kitsune/commerce
php artisan kitsune:module enable kitsune/commerce
```

Enable, disable and upgrade clear the application's configured route-cache file before changing their receipt.
Deletion is checked and read back. A failure refuses the receipt change; fix access to the cache directory and retry
the same command. Repeating an already completed enable, disable or upgrade also clears stale cached routes.
An upgrade may already have applied schema migrations before cache clearing fails; its unchanged receipt continues
to require an upgrade, so repair the filesystem problem and retry rather than editing the receipt.

Routes reflect the new state on the next application boot. A process that has already booted keeps its providers
and route collection; restart persistent application workers through the host's normal procedure. On a fresh owner
session, check the dashboard and roles page as well as the currency chooser. A disabled module contributes no
currency route, and an old cached currency URI is refused unless the module is enabled, version-matched and registered.

If the host uses route caching, rebuild it after the switch has completed:

```sh
php artisan route:cache
```

The cache command boots a fresh application to build routes for the new state. A later database failure after cache
deletion can leave the cache absent with the old receipt intact; a fresh application then generates the old state's
routes normally. Rebuild only after resolving the failed operation.

## Selecting currency and keeping catalogue data

An owner selects the org's supported currency at **Storefront → Catalogue currency**. The selection applies to every
site in the org. There is no default, and changing a selection never converts prices. Existing sellables prevent
changing or clearing it, including while commerce is disabled. Remove their site listings and sellables explicitly
before selecting another currency. Currency metadata support does not promise payment-provider eligibility.

Disable preserves schema, content and catalogue records:

```sh
php artisan kitsune:module disable kitsune/commerce
```

Uninstall requires a disabled module and refuses while any module-owned table retains a row. Resolve retained data
through its owning workflow, take the host's normal backup, and stop concurrent catalogue writers before teardown.
Site content and the org's currency selection remain after an empty commerce uninstall. The lifecycle and its
partial-DDL recovery limits are recorded in [ADR-038](decision-log.md#adr-038--a-module-is-a-composer-package-core-keeps-a-receipt-for-and-the-kernel-is-a-record-and-a-refusal).
