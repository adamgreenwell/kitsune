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

## Compare Linux app resource profiles

Use a local, reviewed Linux image with Bash or POSIX shell, PHP 8.4 or newer, and the built
application's extensions. Pass the exact image ID from `docker image inspect`. Use a cgroup v2 Docker
host with memory and swap accounting.

~~~sh
bin/benchmark-schema-scale-pair.sh --preflight "$image_id" "$unpacked_application" 10000 "$new_output_directory"
bin/benchmark-schema-scale-pair.sh "$image_id" "$unpacked_application" 10000 "$another_new_output_directory"
~~~

Preflight checks the mounted PHP runtime and both resource profiles without starting the application.
The workload uses the same committed source, read-only application and dependency lock for both cases.
It gives PHP and SQLite one CPU and 1 GiB in the floor case, then two CPUs and 2 GiB in the control.
Both use zero swap, 128 processes or threads, a 128 MiB PHP heap and no container network. The profile
logs record the actual cgroup limits and runtime versions. Paired workload output is private and is
retained for analysis. Each container exits after its work. This command measures the in-process
workload. The browser command has its own timing and resource boundary.
