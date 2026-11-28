# Kitsune plugin guidelines

`kitsune/plugin-guidelines` provides authoring guidance for [Laravel Boost](https://laravel.com/framework/docs/13.x/boost#third-party-package-ai-guidelines).
It contains Markdown resources for the development host. The guidelines are a v1.2
candidate. The release’s extension contract governs which APIs are supported.

## Install in a development host

Add Boost and the guidelines as direct development dependencies of the Laravel app
where you develop the extension, with its Laravel environment set to `local`. Boost discovers the guidelines from that app’s
direct Composer requirements. The guidelines package can be tried from a local Kitsune
checkout while it is a candidate.

```sh
composer config repositories.kitsune-guidelines path /path/to/kitsune/packages/plugin-guidelines
composer require --dev laravel/boost:^2 kitsune/plugin-guidelines:dev-main
php artisan boost:install --guidelines
```

Use the local checkout’s branch in the Composer constraint if it differs from
`main`. Select your agent and the Kitsune guidelines during setup. Check the generated agent
instructions for “Kitsune extension authoring”.
Keep both packages under `require-dev` so a production install using `composer install
--no-dev` omits them.

## Validate the authored code

Install development dependencies in a trusted checkout matching the Kitsune version
under test. From there, run the source validator against the separate extension package.

```sh
php bin/check-plugin.php --strict /path/to/extension
```

A reported source violation exits with status 1. With `--strict`, manual review
findings exit with status 3. Review inherited model or validation
behavior at the flagged source and exercise the extension in its host. Test scoped
reads and writes, permission checks, and disabled content and routes separately.

## Maintenance

The Plugin SDK maintainers own this package. When the supported extension contract,
validator diagnostics, or Boost discovery format changes, update the affected guidance
and inspect the composed instructions in a disposable development host. Check an
independently authored example in the validator and the running host, and keep a negative
control that uses an unscoped validation rule. Verify that a production `--no-dev`
install omits Boost, the guidelines, and Boost’s transitive development tooling.
