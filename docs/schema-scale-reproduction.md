# Reproduce schema-scale benchmarks

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
median and observed p95 as `relation-picker-browser.json` in the fixture and stops its server. The
report includes the seed result and Composer lock digests plus the browser workload's PHP and Node versions.

For a fixture under a completed Linux pair, add `--paired` and pass the pair's `floor` or `control`
directory. The command verifies the checkout revision, profile receipt, seed result and application dependency
lock before starting PHP. The profile identifies where the fixture was prepared. The browser command
launches its own server, so its timing does not inherit the seed container's cgroup limits.

## Measure panel reads

Add `--panel-reads` to the browser command before the PHP path. For a paired
fixture, put `--paired` first.

~~~sh
node bin/benchmark-schema-relations.cjs --panel-reads "$(command -v php)" "$unpacked_application" "$new_output_directory"
~~~

The seed supplies five Sites and their known article IDs for the browser. The browser signs
in as each Site's owner and measures nine navigations per Site. It verifies dashboard, first
and last article pages, broad and selective article search, view, edit, related records and the
asset list. Each Site gets one warmup pass and 25 further passes with all nine cases interleaved.
Navigation timing stops when the expected page element renders. Result assertions run separately.
The last page is derived from
the actual pagination summary, then independently checked after navigation. The report
`schema-scale-panel-reads.json` includes every sample and the seed and lock digests. It also records 25 fetch-and-decode
observations for a 70-byte private PNG shared within the Org, plus two denial checks across
the Org and editor-grant boundaries.

This measures read navigation to the requested page state. The historical matrix also profiled
original SQL, additional permission controls and audited browser writes.
Those observations have their own measurement boundaries.

## Capture read SQL and replay its estimated plans

Use `--panel-sql` in place of `--panel-reads`. The browser records a private SELECT profile for
each navigation, warmup and pagination calibration. After the browser exits, a separate
query-only PDO connection obtains estimated plans using the captured statements and bindings.
`schema-scale-read-plans.json` reports the profile and statement counts and the plan digests.
The raw profiles are private and can include bound values from the synthetic fixture. This
mode profiles the panel page requests. Private-image fetch and write timing retain their own
boundaries.

## Verify permissions and measure targeted picker searches

Run the browser command with `--panel-controls` before the PHP path. The fixture supplies
live articles and assets for the success and denial controls. The browser checks eight
denials across the sibling Site, Org and editor-grant boundaries, verifying accessible
controls for the same records first. Four additional direct requests verify Org
page access with both owners. Four targeted picker searches verify the returned
IDs or the expected empty result, with 25 timed observations per case after warmup.
Search timing starts when the term is entered and ends when the Livewire response body is
available. It includes the picker debounce. The output is `schema-scale-panel-controls.json`.

Use `--panel-control-sql` to record each of the 100 timed picker POST requests and
obtain estimated plans for their captured SELECTs. Its timing includes the capture
cost and uses separate fields from the ordinary control run. The final
`schema-scale-panel-control-sql.json` binds the plan receipt digest. The two
preliminary positive searches and the warmup searches run outside SQL capture.

## Measure panel writes on a fresh fixture

Pass `--panel-writes` when preparing a fresh SQLite seed and when starting its browser stage.

~~~sh
env -i PATH="$PATH" php -d memory_limit=128M bin/benchmark-schema-scale.php --panel-writes \
  "$unpacked_application" 10000 "$new_output_directory"
node bin/benchmark-schema-relations.cjs --panel-writes "$(command -v php)" "$unpacked_application" "$new_output_directory"
~~~

The seed records write authority for that output directory, database and dependency lock.
Run the write stage once per fixture. It writes 25 change-and-restore pairs across 12 cases,
covering three rich-text sizes and four operations. It checks persisted values, link order,
revision retention and the signed-in actor's audit row after every save. The timer stops at
the returned save response. Database verification runs outside the timed interval. See
`schema-scale-panel-writes.json` for the 600 samples. The paired SQLite fixtures are for
read stages.

For an instrumented run, use `--panel-write-sql` in the browser command with the
same `--panel-writes` seed opt-in. This profiles the SELECTs in each audited save
request, including changes and restores. Database verification and estimated plan
replay run outside the timed interval. The instrumented timings include the cost of
recording the SQL and use separate fields. Plans describe the post-run fixture. The final `schema-scale-panel-write-sql.json` binds the plan
receipt digest and requires all 600 save profiles. Treat the raw profiles as private.
On MariaDB the known locking SELECTs get estimated plans in one allowlisted
EXPLAIN-only transaction. It rolls back and verifies the session's read-only default.
Other statements are planned with read-only enforcement. An interrupted or
failed plan stage may leave a private partial receipt for diagnosis.

## Run the seed and broad browser case on a native database

Use a local Docker socket and the same built application. Install the checkout's browser dependencies
and Chromium, and use PHP with the PDO extension for the selected engine. Pass the full ID of a
reviewed local Linux image for PostgreSQL, MySQL, or MariaDB.

~~~sh
engine=mysql
image_id=$(docker image inspect --format '{{.Id}}' mysql:8.4)
bin/benchmark-schema-scale-native.sh "$engine" "$image_id" "$unpacked_application" 10000 "$new_output_directory"
~~~

Use `pgsql`, `mysql` or `mariadb`, and the same three corpus sizes as the SQLite runner. Each run
creates a fresh database, maps one port on IPv4 loopback, and verifies database ownership before
migrations. The launcher runs the application seed and the 25-search owner browser case. Add
`--panel-reads` or `--panel-sql` before the engine to include navigation. Add `--panel-writes`
to include audited writes after any read stage, or use `--panel-write-sql` to
profile the save SELECTs. Use `--panel-controls` before the engine
to run the permission and picker controls before writes. Add `--panel-control-sql`
in place of `--panel-controls` to capture those picker SELECTs. The launcher stops its own database and checks that its temporary volume is gone. Use `cleanup-result.txt` to
confirm teardown and keep the output private because it includes disposable fixture credentials.

`database-runtime.txt` records the database container's configured limits of two CPUs, 2 GiB and
256 processes. PHP and Chromium run on the host. Compare these results as a native database control,
with the SQLite pair below providing separate app resource-floor evidence. The historical
panel, write and permission matrix has its own qualification record in [issue #261](https://github.com/adamgreenwell/kitsune/issues/261).

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
logs record the actual cgroup limits and runtime versions. The launcher writes `floor.receipt.json`
and `control.receipt.json` after each profile succeeds. They record the archived runner's commit,
exact image ID, observed cgroup limits, runtime and dependency lock. In workload mode each receipt
includes the SHA-256 of its profile's `result.json`. A preflight receipt has a null result.
`pair.receipt.json` binds both profile receipts after confirming that their source, image, runtime,
dependency lock and workload size agree. Its absence means the pair is incomplete, even when one
profile finished. The application report itself leaves CPU and host-memory limits unattested.

Paired workload output is private and is retained for analysis. Each container exits after its work.
This command measures the in-process workload. The browser command has its own timing and resource
boundary.
