#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Compare the same SQLite workload under the paired Linux app cgroups.
set -euo pipefail

refuse() {
    printf 'SQLite pair refused: %s\n' "$*" >&2
    exit 1
}

mode=run
if [[ ${1:-} == --preflight ]]; then
    mode=preflight
    shift
fi
(( $# == 4 )) || refuse 'usage: benchmark-schema-scale-pair.sh [--preflight] IMAGE_ID APPLICATION ROWS ABSOLUTE_NEW_OUTPUT'
image=$1
application=$(cd -- "$2" && pwd -P) || refuse 'the built application is unavailable'
rows=$3
out=$4
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)

[[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]] || refuse 'use a complete local image ID'
[[ "$(docker image inspect --format '{{.Id}} {{.Os}}' "$image")" == "$image linux" ]] \
    || refuse 'the exact Linux image must be present locally'
case "$rows" in 10000|100000|1000000) ;; *) refuse 'use 10000, 100000 or 1000000 rows' ;; esac
[[ -f "$application/composer.lock" && -f "$application/vendor/autoload.php" && -f "$application/bootstrap/app.php" \
    && ! -e "$application/.env" && ! -e "$application/bootstrap/cache/config.php" ]] \
    || refuse 'use a built application with its lock and without runtime configuration'
[[ "$application" != *,* && "$root" =~ ^/[A-Za-z0-9/._-]+$ && "$out" =~ ^/[A-Za-z0-9/._-]+$ \
    && "$out" != */ && "$(cd -- "$(dirname -- "$out")" && pwd -P)" == "$(dirname -- "$out")" \
    && "$out/" != "$root/"* && "$out/" != "$application/"* && ! -e "$out" && ! -L "$out" ]] \
    || refuse 'use canonical paths and a new output directory outside the source and application'

source_sha=$(git -C "$root" --no-replace-objects rev-parse HEAD)
umask 077
mkdir -- "$out"
source_copy="$out/source"
mkdir -- "$source_copy"
git -C "$root" --no-replace-objects archive "$source_sha" \
    bin/benchmark-schema-scale.php bin/benchmarks skeleton/app/Models/User.php \
    skeleton/database/migrations packages/core/database/migrations | tar -x -C "$source_copy"
printf 'source=%s\nimage=%s\nrows=%s\nmode=%s\n' "$source_sha" "$image" "$rows" "$mode" > "$out/pair.txt"

run_profile() {
    local profile=$1 cpu=$2 ram=$3
    docker run --pull never --rm --init --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
        --user "$(id -u):$(id -g)" --cpus "$cpu" --memory "$ram" --memory-swap "$ram" --pids-limit 128 \
        --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \
        --mount "type=bind,src=$source_copy,dst=$root,readonly" \
        --mount "type=bind,src=$application,dst=/application,readonly" \
        --mount "type=bind,src=$out,dst=$out" \
        --entrypoint /bin/sh "$image" -euc '
            cpu=$(cat /sys/fs/cgroup/cpu.max)
            memory=$(cat /sys/fs/cgroup/memory.max)
            swap=$(cat /sys/fs/cgroup/memory.swap.max)
            pids=$(cat /sys/fs/cgroup/pids.max)
            if [ "$cpu" != "$(($1 * 100000)) 100000" ] || [ "$memory" != "$2" ] || [ "$swap" != 0 ] || [ "$pids" != 128 ]; then
                printf "%s\n" "The actual cgroup limits differ from the requested profile." >&2
                exit 1
            fi
            printf "cpu.max=%s\nmemory.max=%s\nmemory.swap.max=%s\npids.max=%s\n" "$cpu" "$memory" "$swap" "$pids"
            runtime=$(php -d memory_limit=128M -r '\''
                if (PHP_VERSION_ID < 80400 || ! extension_loaded("pdo_sqlite")) exit(1);
                require "/application/vendor/autoload.php";
                echo json_encode(["php" => PHP_VERSION, "sqlite" => (new PDO("sqlite::memory:"))
                    ->query("select sqlite_version()")->fetchColumn(), "php_memory" => ini_get("memory_limit"),
                    "composer_lock_sha256" => hash_file("sha256", "/application/composer.lock")], JSON_THROW_ON_ERROR), "\n";
            '\'')
            printf "%s\n" "$runtime"
            if [ "$3" = run ]; then
                env -i PATH="$PATH" php -d memory_limit=128M "$4/bin/benchmark-schema-scale.php" /application "$5" "$6" || exit "$?"
            fi
            php "$4/bin/benchmarks/write-schema-scale-pair-receipt.php" "$7" "$3" "$8" "$9" \
                "$5" "${cpu%% *}" "${cpu##* }" "$memory" "$swap" "$pids" "$runtime" "$6"
        ' pair "$cpu" "$ram" "$mode" "$root" "$rows" "$out/$profile" "$profile" "$source_sha" "$image" > "$out/$profile.log" 2>&1 \
        || refuse "$profile failed; see $out/$profile.log"
}

run_profile floor 1 1073741824
run_profile control 2 2147483648
printf 'Pair %s completed: %s\n' "$mode" "$out"
