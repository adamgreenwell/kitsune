#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Reproduce ADR-027's resource-floor measurement under the floor's own limits — spike #14.
#
# Usage: bin/benchmark-floor.sh [--entries N] [--image php:8.4-cli] [--keep]
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
image=php:8.4-cli
keep=false

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
    --keep) keep=true; shift ;;
    -h|--help) sed -n '8,10p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) refuse "unknown argument [$1]" ;;
  esac
done

[[ "$entries" =~ ^[1-9][0-9]*$ ]] || refuse "--entries must be a positive integer, not [$entries]"

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

command -v docker >/dev/null 2>&1 || refuse "docker is not on PATH, and the floor is a claim about constrained hardware"
docker info >/dev/null 2>&1 || refuse "docker is installed but its daemon is not answering"
command -v composer >/dev/null 2>&1 || refuse "composer is not on PATH"

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
mkdir -p "$work/packages"
cp -R "$repo/skeleton" "$app"
cp -R "$repo/packages/core" "$work/packages/core"
rm -rf "$app/vendor" "$app/.env" "$app/database/database.sqlite" "$work/packages/core/vendor"
# bootstrap/cache is gitignored, so `cp -R` may carry a config cache built against another .env — which the
# container would then load in preference to the one written below, silently measuring a different app.
rm -f "$app/bootstrap/cache"/*.php

# kitsune/core as real files, exactly as deploy/release.sh step 6 installs it and as a Packagist install will
# put it. `symlink: false` matters twice over: it is how a released install resolves, and a symlinked vendor
# entry would need its target mounted into the container as well.
composer config -d "$app" repositories.kitsune-core \
  '{"type":"path","url":"../packages/core","options":{"symlink":false}}' >/dev/null
if ! composer install -d "$app" --no-dev --no-interaction --prefer-dist --optimize-autoloader \
  --no-scripts >"$work/install.log" 2>&1; then
  tail -20 "$work/install.log" >&2
  refuse "composer install failed in the disposable install"
fi
[[ -f "$app/vendor/kitsune/core/composer.json" && ! -L "$app/vendor/kitsune/core" ]] \
  || refuse "kitsune/core was not installed as a copy of packages/core"

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

  printf '%s\n' "$out"
}

# ⚠️ WHAT WAS MEASURED, RECORDED BESIDE THE NUMBERS. `php:8.4-cli` is a moving tag, and the interpreter decides
# the answer: the official image activates no php.ini at all, so the process runs at PHP's built-in 128 MB
# limit with OPcache off in CLI — neither of which an operator's FPM install shares. A figure copied into
# docs/roadmap.md without this line is not reproducible, it only looks it.
digest=$(docker image inspect "$image" --format '{{index .RepoDigests 0}}' 2>/dev/null || echo "$image (no digest; built or loaded locally)")
phpline=$(in_image php -r 'echo PHP_VERSION, " memory_limit=", ini_get("memory_limit"), " opcache.enable_cli=", ini_get("opcache.enable_cli") ?: "0";')

echo "==> measuring, ${entries} entries in scope"
echo "    image:       ${digest}"
echo "    interpreter: ${phpline}"
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
echo "  anyway, and 42 MB of a 1024 MB cap is not pressure — so a difference between these columns means"
echo "  the two runs differed in something other than their limits, which is the confound this exists to"
echo "  catch. What the floor still needs, and this does not give, is the same measurement under"
echo "  CONCURRENCY: the workers figure above is arithmetic from one request, not an observation of that"
echo "  many running at once."
