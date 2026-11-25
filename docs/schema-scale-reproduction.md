# Prepare a schema-scale SQLite fixture

The in-process runner builds the synthetic schema and data, checks scoped queries, captures estimated
plans and exercises actual entry and media writes. Its results cover application calls. The relation-picker browser case has its own command below. The full browser matrix and paired
database and resource-floor workloads have separate measurement boundaries in
[issue #261](https://github.com/adamgreenwell/kitsune/issues/261).

Build a runtime application from the same source using the
[release bundle instructions](release-artifacts.md), then unpack it into a temporary directory.
Use PHP 8.4 or newer, the application extensions and a new absolute output path outside both the
source checkout and the unpacked application.

~~~sh
env -i PATH="$PATH" php -d memory_limit=128M bin/benchmark-schema-scale.php \
  "$unpacked_application" 10000 "$new_output_directory"
~~~

Valid sizes are 10,000, 100,000 and 1,000,000 entries. The runner creates its own SQLite file, storage
and application configuration. It refuses an existing output directory, cached application
configuration and ambient application overrides. The JSON receipt names the result file and the
dependency lock hash. Keep the output private because it includes the throwaway browser credentials
and key as well as the benchmark results.

## Measure the broad relation picker in a browser

Install the checkout's Node dependencies and Chromium for Playwright. Use the same unpacked
application and a fixture produced by the SQLite command.

~~~sh
node bin/benchmark-schema-relations.cjs "$(command -v php)" "$unpacked_application" "$new_output_directory"
~~~

The command starts its own PHP server on a loopback port and signs in with the fixture's generated owner.
It alternates the two search spellings after one warmup and measures the 25 results in the browser,
including the picker debounce. Each response must contain 50 options. It records the observations,
median and observed p95 as `relation-picker-browser.json` in the fixture and stops its server.
