# Kitsune plugin SDK

`kitsune/plugin-sdk` is a Composer development package for testing Kitsune
extensions. This v1.2 candidate includes a Laravel Testbench base, a scope fixture,
and assertions for the Site and Org boundaries.

## Test an extension

Require the SDK through the extension’s `require-dev` in Composer. Its
`PluginTestCase` boots Kitsune and runs migrations on an in-memory SQLite database
with foreign keys enabled. Register module or host fixture migrations in
`defineDatabaseMigrations()` using Testbench’s `load_migration_paths()`. Native
database coverage can use an explicitly configured disposable test database in a
test subclass. Laravel’s `RefreshDatabase` prepares the schema on that connection.

```php
$fixture = ScopeFixture::create();
$note = $fixture->inSite($fixture->site, fn () => Note::create(['slug' => 'handout']));

$fixture->assertSiteIsolation(fn (): bool => Note::whereKey($note->getKey())->exists());
```

The callback makes a fresh query or request under the active context. The assertion
checks the owning Site, a sibling Site, a Site owned by another Org, an Org with no
Site selected, and missing context. Use `assertOrgIsolation()` for Org-owned
records. It expects access from sibling Sites and from the owning Org alone. Both
helpers restore the previous scope after each call, including when a callback
throws.

Also test permissions, route binding, write refusal, and module enablement through
the extension’s own entry points. Include checks for records shared across an Org
when the extension supports that behavior.

## Run the example

The example models a Site-scoped learning note with its own table and module
manifest. From a trusted Kitsune checkout with development dependencies installed,

```sh
php bin/check-plugin.php --strict packages/plugin-sdk/examples/scope-notes
```

Run its test suite with Composer path packages from the same checkout.

```sh
cd packages/plugin-sdk/examples/scope-notes
composer install
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

The example and the first-party module implementations illustrate the current
development workflow. The v1.2 freeze will record the supported extension
contracts. Use that contract when choosing base classes, interfaces, and hooks.
