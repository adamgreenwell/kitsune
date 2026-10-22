# Blueprint apply timings — 2026-10-22 (#207)

Blog and Marketing Site meet Phase 5's **60,000 ms constrained-container apply criterion** in these runs.
The slowest apply took **785 ms**. These are individual observations on a local Docker Desktop VM, not a
latency distribution or evidence that a native 1 GB host serves the site. ADR-030's native-floor condition
remains open; this record authorizes no site move, deployment or host change.

## Reproduce

Measured application and harness: `4e6f9059d841533b7ff45e18685da2c7022392ab`. All 1,359 tracked files
matched that commit before and after both runs. The installed `kitsune/core` and `kitsune/person` files
also matched its bytes and executable modes in all fourteen disposable app copies. Composer omits core's
`.gitattributes` when mirroring the package; it is the sole omitted tracked file in those two packages.
Optional commerce and SVG sanitizer packages were not installed in this default skeleton graph.

The first run resolved a fresh graph and saved it. The second installed that exact graph:

```bash
bin/benchmark-floor.sh --entries 1000 --save-lock docs/benchmarks/floor-2026-10-22.composer.lock
bin/benchmark-floor.sh --entries 100000 --lock docs/benchmarks/floor-2026-10-22.composer.lock
```

To reproduce the saved graph at either size, use `--lock` rather than resolving it again. The actual runs
specified `--image kitsune-floor-207:php8.4 --keep`; that local image was built from the unchanged
`bin/benchmark-floor.Dockerfile`. Disposable paths and the saved lock's destination differed from the
portable commands above. `--keep` allowed database inspection before cleanup. An external Docker wrapper
retained each stopped container long enough to inspect its limits, exit status and OOM flag, then removed
it. It did not change the application command or the timer inside PHP.

## Platform and graph

| Input | Recorded value |
|---|---|
| Physical developer host | Apple M4 Max, Mac16,5, 16 CPU cores, 128 GiB RAM; macOS 27.0.1 (26A434) |
| Container host | Docker Desktop engine 29.9.2; Linux 7.0.14-linuxkit, aarch64, cgroup v2; VM reports 16 CPUs and 8,318,709,760 bytes RAM |
| Interpreter image | `kitsune-floor-207@sha256:8e3c25f0d2c8a07d3720a9c2cbbb5c6815dda3ed9f7fec6420f518dfd47be535` (locally built; not a published image) |
| Pinned base | `php@sha256:a545b9041fb0e378cb597b4d0509f77c6a4d996dd485763af92e5b7e59c469cc` |
| PHP | 8.4.25 CLI, NTS; `memory_limit=128M`, `opcache.enable_cli=0`, no main php.ini |
| Native libraries | ICU 76.1 (`libicu76` 76.1-4); libzip 1.11.3-2; recorded build inputs, not apt pins |
| Saved lock SHA-256 | `c100535d6eaccd6d7ac8b47e8ff74d7e9cd3cdbfbffe2ed1888bb49c48e9121e` |
| Runtime graph | 114 packages; Laravel v13.38.0, Filament v5.10.3, Livewire v4.4.8; remaining versions in the adjacent lock |
| Application storage | SQLite; file sessions/cache; synchronous queue; no external application services |
| Constrained containers | `NanoCpus=1000000000`, `Memory=1073741824`, `MemorySwap=1073741824`; every measured container inspected |
| Effective limit probe in the same image | `cpu.max=100000 100000`, `memory.max=1073741824`, `memory.swap.max=0` |

Loaded extensions: Core, ctype, curl, date, dom, fileinfo, filter, hash, iconv, intl, json, libxml,
mbstring, mysqlnd, openssl, pcre, PDO, pdo_sqlite, Phar, posix, random, readline, Reflection, session,
SimpleXML, sodium, SPL, sqlite3, standard, tokenizer, xml, xmlreader, xmlwriter, Zend OPcache, zip, zlib.
Composer's generated platform check succeeded **inside the image** before bootstrapping either install.
The host Composer resolved for the image's PHP version; this host has intl and zip, so #128 was not exercised.

## Apply observations

Each cell is one command in a fresh copy under **1 vCPU / 1,024 MiB, no swap**. Each fresh path creates
one org, one site and the named first owner, including its bcrypt hash. The harness supplies its disposable
test credential only through standard input; no credential is recorded here. Each content path first seeds
the corpus in a separate process, then applies into that existing org without a new owner.

| Blueprint | Fresh owner, 1k run | Into 1,000 entries | Fresh owner, 100k run | Into 100,000 entries |
|---|---:|---:|---:|---:|
| Blog 1.0.0 | 719 ms | 448 ms | 753 ms | 434 ms |
| Marketing Site 1.1.0 | 785 ms | 427 ms | 743 ms | 418 ms |

The timer surrounds the full `php artisan kitsune:blueprint apply … --no-interaction` child process inside
the container, including Artisan bootstrap and owner creation when requested. Dependency installation,
container startup, migrations, corpus preparation and post-apply status reads are outside that timer.
This measures blueprint application, not the Phase 6 installer or an end-to-end deployment.

All eight applies exited zero and left one finished receipt with a non-null manifest. Independent read-only
SQLite inspection found the expected blueprint/version, one org and site, and exactly one user with an Owner
role and org membership on each fresh path. Content paths retained exactly their 1,000 or 100,000 entries
and created no user. All fourteen databases passed integrity and foreign-key checks. All ten constrained
measurement containers exited zero without an OOM kill and were removed.

## Same-image control

| Corpus | Constrained / unconstrained bootstrap peak | Constrained / unconstrained serving peak | Constrained / unconstrained list of 25 rows |
|---|---|---|---|
| 1,000 | 42.5 / 42.5 MiB | 44.5 / 44.5 MiB | 1.1 / 0.9 ms |
| 100,000 | 42.5 / 42.5 MiB | 44.5 / 44.5 MiB | 58.2 / 61.2 ms |

Both measurement processes reported the full corpus in scope and **zero entries seeded by that process**.
The harness calculates eleven workers in half the floor from the serving peak; it did not run eleven workers.
PHP's heap peak excludes native-library allocations and the OS. Wall-clock timings depend on the host,
VM, bind mount and competing workloads. No developer-host apply was newly measured, and no comparison with
the older developer-host figures is claimed.

## Remaining evidence

- Native-host installation, application service and Marketing Site behavior at the real floor remain unmeasured.
- Concurrent requests and full-system memory pressure remain unmeasured.
- Private media stage workloads remain #219's separate work.
- Host extension portability remains #128's separate work.

Raw stdout, per-container inspections, source manifests and database audit receipts are retained with the
task's `artifacts/issue207` evidence. Qualification receipt SHA-256:
`1daee99ff8143b19cb3fb8b4bb694f8886716e42fa9a787fb99521f23cf16135`.
The older `floor.composer.lock` and its historical results remain intact; this dated lock records a different graph.
