#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Reproduce ADR-027's resource-floor measurement under the floor's own limits — spike #14.
#
# Usage: bin/benchmark-floor.sh [--entries N] [--image IMAGE] [--lock FILE] [--save-lock FILE] [--keep]
#
# Without --image the interpreter is built from bin/benchmark-floor.Dockerfile, which is the one the floor is
# recorded on: `php:8.4-cli` at a pinned digest, plus the ext-intl and ext-zip the dependency graph requires.
#
# After the two columns it times Phase 5's one command, `kitsune:blueprint apply`, under the same limits, for each of
# core's blueprints — Blog, and the Marketing Site ADR-030 moves kitsunecms.org onto: once on an empty installation,
# where it creates the org, its site and its first owner, and once into the corpus org with --entries in the table.
#
# ⚠️ WHY THIS EXISTS. docs/roadmap.md recorded a constrained column — peak memory and workers "verified
# inside a container limited to 1 vCPU and 1 GB, not merely on the dev machine" — and nothing in this
# repository could produce it again. compose.yaml sets no cpu or memory limit, and no script pinned one, so
# the one number ADR-026's whole self-host premise rests on could not be re-checked. An unreproducible
# measurement is the same defect as an unforgeable guard: it may be right, and nobody can tell.
#
# ⚠️ AND BOTH COLUMNS COME FROM ONE IMAGE, which is the correction this script exists to make as much as the
# reproducibility. Comparing a container against the dev machine confounds two variables — the PHP build, its
# extensions and its INI on one side, the cgroup limits on the other — and reports the sum as the cost of the
# limits. Measured properly — same image, fresh copy of the application per run, only the limits changed —
# the pairing becomes a CONTROL: peak memory should come out identical, and a difference between the columns
# means the two runs differed in something other than their limits.
#
# ⚠️ AND THE LIMITS ARE READ OUT OF THE APPLICATION, not written here. `Kitsune::FLOOR_VCPU` and
# `FLOOR_MEMORY_MB` are the floor; a second copy of them in this file would be a floor that could drift from
# the one the code asserts. tests/Core/Release/FloorHarnessTest.php runs this script against stub binaries
# and reads the argv it builds, so the container size, those constants and the hint the command prints to
# operators are held to one number by something that executes rather than greps.
set -euo pipefail

entries=1000
image=
keep=false
lock=
save_lock=

refuse() {
  echo "Refusing to measure: $*" >&2
  exit 1
}

while (($#)); do
  case "$1" in
    --entries) entries=${2?--entries needs a value}; shift 2 ;;
    --entries=*) entries=${1#*=}; shift ;;
    --image) image=${2?--image needs a value}; shift 2 ;;
    --image=*) image=${1#*=}; shift ;;
    --lock) lock=${2?--lock needs a file}; shift 2 ;;
    --lock=*) lock=${1#*=}; shift ;;
    --save-lock) save_lock=${2?--save-lock needs a file}; shift 2 ;;
    --save-lock=*) save_lock=${1#*=}; shift ;;
    --keep) keep=true; shift ;;
    -h|--help) sed -n '8,10p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) refuse "unknown argument [$1]" ;;
  esac
done

[[ "$entries" =~ ^[1-9][0-9]*$ ]] || refuse "--entries must be a positive integer, not [$entries]"
[[ -z "$lock" || -f "$lock" ]] || refuse "--lock names [$lock], which is not a file"

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

command -v docker >/dev/null 2>&1 || refuse "docker is not on PATH, and the floor is a claim about constrained hardware"
docker info >/dev/null 2>&1 || refuse "docker is installed but its daemon is not answering"
command -v composer >/dev/null 2>&1 || refuse "composer is not on PATH"

# The floor's own interpreter, built when no other is named. Fed on stdin because it copies nothing in, so the build
# has no context to send; the tag is local, and the header below records the image id a run actually used.
if [[ -z "$image" ]]; then
  echo "==> building the floor interpreter from bin/benchmark-floor.Dockerfile"
  image=kitsune-floor:php8.4
  docker build -q -t "$image" - < "$repo/bin/benchmark-floor.Dockerfile" >/dev/null \
    || refuse "bin/benchmark-floor.Dockerfile did not build"
fi

# ⚠️ WITH A TEMPLATE. macOS's mktemp ignores TMPDIR without one and answers under /var/folders, which is not
# shared with Docker Desktop by default — so the mount would be empty and every measurement would be of an
# application that is not there.
work=$(mktemp -d "${TMPDIR:-/tmp}/kitsune-floor-XXXXXXXX")
app="$work/skeleton"

cleanup() {
  if [[ "$keep" == true ]]; then
    echo "Kept the disposable install at $work" >&2
  else
    rm -rf "$work"
  fi
}
trap cleanup EXIT

echo "==> building a disposable install in $work"

# ⚠️ A COPY, NEVER THE WORKING TREE. Installing into `skeleton/` would leave a vendor directory and an edited
# composer.json behind in the repository, and `skeleton/vendor` is what the bare-clone rule says never has to
# exist for the suite to pass.
# ⚠️ EVERY PACKAGE, NOT CORE ALONE. The skeleton requires `kitsune/person` too (#134), and with only core's path
# repository Composer looked for person somewhere else — so the install failed, or installed a person that is not this
# commit's. The same glob `deploy/release.sh` step 6 uses, for the same reason.
mkdir -p "$work/packages"
cp -R "$repo/skeleton" "$app"
for pkg in "$repo"/packages/*/; do
  name=$(basename "$pkg")
  cp -R "$pkg" "$work/packages/$name"
  rm -rf "$work/packages/$name/vendor"
done
rm -rf "$app/vendor" "$app/.env" "$app/database/database.sqlite"
# And any lock the checkout holds — `skeleton/composer.lock` is ignored, so running Composer in the skeleton leaves
# one, and `cp -R` carried it in. A run without --lock then installed that graph while its header said it had
# resolved one fresh, and --save-lock kept it as new (Codex, #126). The only lock an install may start from is
# the one --lock names.
rm -f "$app/composer.lock"
# bootstrap/cache is gitignored, so `cp -R` may carry a config cache built against another .env — which the
# container would then load in preference to the one written below, silently measuring a different app.
rm -f "$app/bootstrap/cache"/*.php

# Every kitsune/* package as real files, exactly as deploy/release.sh step 6 installs them and as a Packagist install
# will put them. `symlink: false` matters twice over: it is how a released install resolves, and a symlinked vendor
# entry would need its target mounted into the container as well.
# ⚠️ THE DEPENDENCY GRAPH IS AN INPUT TOO. The skeleton commits no lock (deploy/release.sh step 5 says why), so
# `composer install` resolves whatever Laravel, Filament and their dependencies currently satisfy the skeleton's
# constraints — which is right for the floor's purpose, since that is what an operator installing today gets,
# and wrong for re-checking a recorded figure, since the next compatible release changes the application being
# measured. The image digest pins the interpreter and not this (Codex, #126). So a run records the graph it
# resolved (--save-lock), and a re-check installs exactly that graph (--lock).
if [[ -n "$lock" ]]; then
  cp "$lock" "$app/composer.lock"
fi

# ⚠️ RESOLVED FOR THE IMAGE'S PHP, NOT THIS HOST'S. Composer runs here, and left alone it resolves against this
# machine's PHP — so a host on 8.5 measuring an 8.4 image could install an 8.5-only release that then fails in the
# container, and a lock valid for a newer image could be refused here (Codex, #126). Only the version is pinned to
# the image, so the lock does not depend on which extensions this host happens to load; `platform-check` then has
# the autoloader verify the extensions, IN the image, before anything boots — see the check after the install.
image_php=$(docker run --rm "$image" php -r 'echo PHP_VERSION;') || refuse "could not read the PHP version of [$image]"
[[ "$image_php" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || refuse "[$image] reported its PHP version as [$image_php]"
composer config -d "$app" platform.php "$image_php" >/dev/null
composer config -d "$app" platform-check true >/dev/null

composer config -d "$app" repositories.kitsune-packages \
  '{"type":"path","url":"../packages/*","options":{"symlink":false}}' >/dev/null
if ! composer install -d "$app" --no-dev --no-interaction --prefer-dist --optimize-autoloader \
  --no-scripts >"$work/install.log" 2>&1; then
  tail -20 "$work/install.log" >&2
  refuse "composer install failed in the disposable install"
fi
[[ -f "$app/vendor/kitsune/core/composer.json" && ! -L "$app/vendor/kitsune/core" ]] \
  || refuse "kitsune/core was not installed as a copy of packages/core"
# And every other first-party package the skeleton requires, each a copy of this commit's — read from the skeleton's
# own composer.json, so a package added there is checked here without anybody remembering to.
required=$(php -r '$c = json_decode((string) file_get_contents($argv[1]), true);
  foreach (array_keys($c["require"] ?? []) as $n) { if (str_starts_with($n, "kitsune/")) { echo substr($n, 8), "\n"; } }' \
  "$app/composer.json")
for name in $required; do
  [[ -f "$app/vendor/kitsune/$name/composer.json" && ! -L "$app/vendor/kitsune/$name" ]] \
    || refuse "kitsune/$name was not installed as a copy of packages/$name"
done

# The graph this run measures, named beside the numbers whether or not anything saved it. The lock's own hash is
# the identity; the three versions are what dominate the memory figure, so a reader can see what moved.
[[ -f "$app/composer.lock" ]] || refuse "composer install left no composer.lock, so the graph measured cannot be named"
if [[ -n "$save_lock" ]]; then
  cp "$app/composer.lock" "$save_lock"
fi
lock_sha=$(shasum -a 256 "$app/composer.lock" | cut -c1-16)
versions=$(php -r '$l = json_decode((string) file_get_contents($argv[1]), true); $v = [];
  foreach ($l["packages"] ?? [] as $p) { $v[$p["name"]] = $p["version"]; }
  echo implode(", ", array_map(fn ($n) => $n." ".($v[$n] ?? "?"), ["laravel/framework", "filament/filament", "livewire/livewire"]));' \
  "$app/composer.lock")

# ⚠️ EVERY artisan RUN IS IN THE IMAGE, so the host's PHP never touches the application being measured. A key
# generated by one PHP and a benchmark run under another would still work, but then the run would no longer be
# a measurement of that image alone.
cat > "$app/.env" <<'ENV'
APP_NAME=Kitsune
APP_ENV=local
APP_KEY=
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/database.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV
touch "$app/database/database.sqlite"

in_image() { docker run --rm -v "$app":/app -w /app "$image" "$@"; }

# ⚠️ THE IMAGE MUST BE ABLE TO RUN WHAT WAS INSTALLED, AND IT WAS NOT. The official `php:8.4-cli` loads neither ext-intl
# (filament/support) nor ext-zip (openspout/openspout); the benchmark booted regardless, because its samples call
# neither, so the floor was measured on an interpreter Composer would refuse to install the application for. Composer's
# own platform check runs here first, in the image, and a missing extension is a refusal naming it — not a measurement.
if ! platform=$(in_image php vendor/composer/platform_check.php 2>&1); then
  refuse "[$image] cannot run the installed application: $(printf '%s' "$platform" | tr -s '\n' ' ')"
fi

in_image php artisan key:generate --force --no-interaction >/dev/null
in_image php artisan package:discover --no-interaction >/dev/null
in_image php artisan migrate --force --no-interaction >/dev/null

# The floor, from the application rather than from this file.
limits=$(in_image php -r 'require "vendor/autoload.php"; echo Kitsune\Core\Kitsune::FLOOR_VCPU, " ", Kitsune\Core\Kitsune::FLOOR_MEMORY_MB;')
vcpu=${limits%% *}
memory_mb=${limits##* }
[[ "$vcpu" =~ ^[0-9]+$ && "$memory_mb" =~ ^[0-9]+$ ]] \
  || refuse "the application did not report its floor as two numbers, but as [$limits]"

measure() {
  local label=$1
  shift
  local out
  local status=0

  # ⚠️ A FRESH COPY PER RUN, so the second measurement does not inherit the first's residue. Both runs shared
  # one mounted directory before this: run 1 seeded a thousand rows and deleted them again, handing run 2 a
  # grown SQLite file with a free list, a written log and a warm page cache — differences between the columns
  # that have nothing to do with the limits, in a pairing whose entire purpose is to isolate the limits.
  local run="$work/run-$label"
  rm -rf "$run"
  cp -R "$app" "$run"

  # ⚠️ SEEDED IN ONE PROCESS, MEASURED IN ANOTHER. PHP keeps the heap an insert grew, and
  # `memory_reset_peak_usage()` only moves the recorded mark down to what the process still holds — so a
  # measurement taken in the process that seeded reported the seeding as the request. Measured: 40.5 MB after
  # seeding 100 entries, 42.5 MB after 1,000 or 5,000, for requests reading the same 25 rows. Codex found it on
  # #126. So the rows go in first, kept, in a process of their own without the limits, and the measuring
  # process below finds them already there and inserts nothing.
  local seeded
  seeded=$(docker run --rm -v "$run":/app -w /app "$image" \
    php artisan kitsune:benchmark-floor --entries="$entries" --keep 2>&1) || status=$?

  if ((status != 0)); then
    printf '%s\n' "$seeded" >&2
    refuse "seeding for the $label run exited $status"
  fi

  # ⚠️ THE STATUS IS PART OF THE MEASUREMENT. Captured without it, a run killed by the cgroup or dying on a PHP
  # fatal still reached the checks below with whatever it had printed before it died — and since the scope line
  # is printed before the first sample, that was enough to look like a measurement. The columns then came out
  # blank and the script exited 0.
  #
  # ⚠️ AND THE STATUS IS TAKEN WITH `|| status=$?`, NOT INSIDE `if ! …`. `!` replaces the pipeline's status with
  # its logical negation, so `$?` in that branch is 1 and the real exit code — 137 for an OOM kill, 255 for a
  # failed artisan — is gone. Nor is it `local out=$(…)`: `local` is itself a command, and its success would
  # mask the substitution's failure.
  out=$(docker run --rm "$@" -v "$run":/app -w /app "$image" \
    php artisan kitsune:benchmark-floor --entries="$entries" 2>&1) || status=$?

  if ((status != 0)); then
    printf '%s\n' "$out" >&2
    refuse "the $label run exited $status"
  fi

  # ⚠️ THE ONE ASSERTION THIS SCRIPT MAKES ABOUT THE RUN, because the roadmap records a version of this
  # benchmark that measured nothing: with no site context SiteScope adds WHERE 1 = 0, and every sample timed
  # an empty result set while the output still looked like a measurement. A run whose scope line is absent, or
  # is zero, is not a slower measurement — it is not one.
  # Digits only: Symfony disables decoration when stdout is not a TTY, and a `docker run` without `-t` is not
  # one — but a stray escape sequence would otherwise turn a good run into a refusal.
  local scope
  scope=$(printf '%s\n' "$out" | awk '/content in scope:/ {print $4; exit}' | tr -cd '0-9')
  [[ "$scope" == "$entries" ]] \
    || refuse "the $label run measured [${scope:-no}] entries in scope rather than $entries, so it measured nothing"

  # And the process that measured must not have seeded, or its peak is the seeding's. Absent counts as seeded:
  # a command that stopped reporting it can no longer show the measurement is clean.
  local inserted
  inserted=$(printf '%s\n' "$out" | awk '/seeded by this run:/ {print $5; exit}' | tr -cd '0-9')
  [[ "$inserted" == 0 ]] \
    || refuse "the $label run seeded [${inserted:-an unknown number of}] entries itself, so its peak includes the seeding"

  printf '%s\n' "$out"
}

# ⚠️ WHAT WAS MEASURED, RECORDED BESIDE THE NUMBERS. `php:8.4-cli` is a moving tag, and the interpreter decides
# the answer: the official image activates no php.ini at all, so the process runs at PHP's built-in 128 MB
# limit with OPcache off in CLI — neither of which an operator's FPM install shares. A figure copied into
# docs/roadmap.md without this line is not reproducible, it only looks it.
# A pulled image has a registry digest; one built here has only its id, which is what a rebuild is compared against.
digest=$(docker image inspect "$image" --format '{{index .RepoDigests 0}}' 2>/dev/null) \
  || digest="$image $(docker image inspect "$image" --format '{{.Id}}' 2>/dev/null) (built here)"
phpline=$(in_image php -r 'echo PHP_VERSION, " memory_limit=", ini_get("memory_limit"), " opcache.enable_cli=", ini_get("opcache.enable_cli") ?: "0", " icu=", defined("INTL_ICU_VERSION") ? INTL_ICU_VERSION : "none";')

echo "==> measuring, ${entries} entries in scope"
echo "    image:       ${digest}"
echo "    interpreter: ${phpline}"
echo "    graph:       lock sha256:${lock_sha} — ${versions} ($([[ -n "$lock" ]] && echo "installed from $lock" || echo "resolved fresh; --save-lock keeps it"))"
echo
echo "──── constrained: ${vcpu} vCPU / ${memory_mb} MB ────"
# --memory-swap equal to --memory, or Docker grants twice the memory as swap and the cap is not the cap.
constrained=$(measure constrained --cpus="$vcpu" --memory="${memory_mb}m" --memory-swap="${memory_mb}m")
printf '%s\n' "$constrained" | sed -n '/content in scope/,/workers that fit/p'

echo
echo "──── unconstrained: same image, no limits ────"
unconstrained=$(measure unconstrained)
printf '%s\n' "$unconstrained" | sed -n '/content in scope/,/workers that fit/p'

# ⚠️ FAIL CLOSED ON AN EMPTY FIELD. These used to hand printf whatever awk found, so a run whose output had
# changed shape printed a blank column under a confident heading instead of stopping.
#
# ⚠️ AND BOTH awk VARIABLES COME THROUGH `-v`. Passed as a trailing `col=…` operand instead, awk would read
# the assignment as its file list, find no file to read and never touch stdin — printing nothing, for reasons
# that look nothing like the cause.
field_of() {
  local value
  value=$(printf '%s\n' "$2" | awk -v pattern="$1" -v col="$3" '$0 ~ pattern {print $col; exit}' | tr -cd '0-9.')
  [[ -n "$value" ]] || { echo "Refusing to report: no [$1] line in the run's output" >&2; return 1; }
  printf '%s' "$value"
}

peak_of() { field_of 'peak serving a request' "$1" 5; }
workers_of() { field_of 'workers that fit in half the floor' "$1" 8; }

# ⚠️ ASSIGNED BEFORE THEY ARE PRINTED, because `refuse` inside `$(…)` exits only the subshell — the script
# would carry on and print a blank cell. As a plain assignment the failure is the assignment's, and `set -e`
# stops the run.
constrained_peak=$(peak_of "$constrained")
unconstrained_peak=$(peak_of "$unconstrained")
constrained_workers=$(workers_of "$constrained")
unconstrained_workers=$(workers_of "$unconstrained")

echo
echo "──── what transfers between machines ────"
printf '  %-28s %14s %14s\n' '' constrained unconstrained
printf '  %-28s %14s %14s\n' 'peak serving a request (MB)' "$constrained_peak" "$unconstrained_peak"
printf '  %-28s %14s %14s\n' 'workers in half the floor' "$constrained_workers" "$unconstrained_workers"
echo
echo "  Equal peaks are the EXPECTED result, and what the pairing is for: it is a control, not a stress"
echo "  test. Neither limit binds one single-threaded request — a PHP CLI process uses at most one CPU"
echo "  anyway, and 40 MB of a 1024 MB cap is not pressure — so a difference between these columns means"
echo "  the two runs differed in something other than their limits, which is the confound this exists to"
echo "  catch. What the floor still needs, and this does not give, is the same measurement under"
echo "  CONCURRENCY: the workers figure above is arithmetic from one request, not an observation of that"
echo "  many running at once."

# ⚠️ THE APPLY'S TIME TOWARD PHASE 5's DONE-WHEN, measured where ADR-039 says: in this image, under the floor's limits,
# on a fresh copy — one command, under 60 s at the floor. ~~Its time only: nobody can sign in to what the fresh path
# creates until there is a first user, so this is not the done-when met.~~ The fresh leg is now the done-when's whole
# one command: it creates the org, its site and the first owner who signs in to them (ADR-026, as amended), and so
# includes one bcrypt hash at cost 12 — 0.23 s of the 0.64 s the command took on the 4-CPU host it was built on. `migrate` is the installer's step, not counted.
#
# ⚠️ TIMED INSIDE THE IMAGE, because a host-side `date +%N` is not portable: macOS's BSD date has no %N.
# ⚠️ TWO PATHS, because they exclude each other: on an empty installation the apply creates the org and its site
# (FirstOrg), and FirstOrg refuses whenever any org exists — so "content already in the table" is a second run, into
# the corpus org, whose `article` collides with none of Blog's types. The content leg cannot take `--owner` by
# construction: the corpus org exists, and a first owner is created only where there is none.
#
# ⚠️ THE OWNER'S PASSWORD GOES OVER A PIPE, AND NOWHERE ELSE. Random, never printed, and never in `$timed`, which is
# argv to `docker run` and to `php -r` — and argv is what `ps`, `/proc` and `docker inspect` show. `printf` is a builtin,
# so it is in no process's argv either; `passthru` inherits the container's standard input, which `-i` keeps open.
# `od` reads exactly sixteen bytes, so nothing is left writing into a closed pipe under pipefail.
owner_password=$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')

# ⚠️ ONE FUNCTION FOR EVERY BLUEPRINT, so each is timed the same way: ADR-030 makes the Marketing Site's figure the
# trigger for moving kitsunecms.org, and a second copy of this would be a second place for the measurement to drift.
apply_blueprint() {   # $1 handle, $2 name in refusals, $3 label, $4 org slug, $5 true to seed the corpus, $6 true to create the owner
  local run="$work/run-$1-$3" out status=0
  rm -rf "$run"
  cp -R "$app" "$run"

  if [[ "$5" == true ]]; then
    docker run --rm -v "$run":/app -w /app "$image" \
      php artisan kitsune:benchmark-floor --entries="$entries" --keep >/dev/null 2>&1 \
      || refuse "seeding the corpus for the $3 $2 apply failed"
  fi

  local owner_args=''
  [[ "$6" == true ]] && owner_args=' --owner=floor-owner@kitsune.test --owner-password-stdin'

  # One line, so the whole command is one line of anything that logs it.
  local timed='$t = hrtime(true); passthru("php artisan kitsune:blueprint apply '"$1"' --org='"$4$owner_args"' --no-interaction 2>&1", $s); printf("blueprint apply took: %d ms\n", intdiv(hrtime(true) - $t, 1000000)); exit($s);'

  if [[ "$6" == true ]]; then
    out=$(printf '%s\n' "$owner_password" | docker run -i --rm --cpus="$vcpu" --memory="${memory_mb}m" \
      --memory-swap="${memory_mb}m" -v "$run":/app -w /app "$image" php -r "$timed" 2>&1) || status=$?
  else
    out=$(docker run --rm --cpus="$vcpu" --memory="${memory_mb}m" --memory-swap="${memory_mb}m" \
      -v "$run":/app -w /app "$image" php -r "$timed" 2>&1) || status=$?
  fi

  ((status == 0)) || { printf '%s\n' "$out" >&2; refuse "the $3 $2 apply exited $status"; }
  # ⚠️ A HERE-STRING, NOT A PIPE, for the reason the status read below gives: `grep -q` stops at the `Applied` line, and
  # the builtin `printf` writing the lines after it into the closed pipe failed the pipeline under pipefail — a good
  # apply refused, which review reproduced in about one run in five hundred under load.
  grep -q "^Applied $1 " <<<"$out" || refuse "the $3 $2 apply did not report applying $1"

  # A here-string, not a pipe: the same closed-pipe hazard as the status read below.
  if [[ "$6" == true ]] && ! grep -q 'its first owner floor-owner@kitsune.test' <<<"$out"; then
    refuse "the $3 $2 apply created no owner"
  fi

  # A receipt left unfinished is an apply that did not happen, however quickly it stopped.
  # ⚠️ CAPTURED, THEN MATCHED. Piped into `grep -q`, which stops reading at the first match, the table's bottom border
  # was written into a closed pipe — and under pipefail the SIGPIPE turned the pipeline false and skipped the refusal.
  local status_out
  status_out=$(docker run --rm -v "$run":/app -w /app "$image" php artisan kitsune:blueprint status 2>&1) \
    || refuse "could not read blueprint status after the $3 $2 apply"
  if grep -q INTERRUPTED <<<"$status_out"; then
    refuse "the $3 $2 apply left its receipt unfinished"
  fi

  field_of 'blueprint apply took' "$out" 4
}

# Assigned before anything is printed, as the peaks above are: a block printed before a later leg refused would read
# as a measurement of a run that did not finish.
blog_fresh=$(apply_blueprint blog Blog fresh blog false true)
blog_content=$(apply_blueprint blog Blog content floor-benchmark true false)
marketing_fresh=$(apply_blueprint marketing-site 'Marketing Site' fresh marketing false true)
marketing_content=$(apply_blueprint marketing-site 'Marketing Site' content floor-benchmark true false)

phase5_block() {   # $1 handle, $2 fresh ms, $3 content ms
  echo
  echo "──── Phase 5: kitsune:blueprint apply $1 at ${vcpu} vCPU / ${memory_mb} MB ────"
  printf '  %-66s %8s ms\n' 'empty installation (creates the org, its site and its first owner)' "$2"
  printf '  %-66s %8s ms\n' "into floor-benchmark, ${entries} entries in the table" "$3"
  echo "  Budget: 60,000 ms (roadmap Phase 5). Reported, not enforced."
}

phase5_block blog "$blog_fresh" "$blog_content"
phase5_block marketing-site "$marketing_fresh" "$marketing_content"
