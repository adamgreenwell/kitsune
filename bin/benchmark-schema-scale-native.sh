#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Prepare a fresh loopback-only native database, then run the seed and picker.
set -euo pipefail

refuse() {
    printf 'Native schema benchmark refused: %s\n' "$*" >&2
    exit 1
}

read_stage=
panel_writes=
while [[ "${1-}" == --panel-* ]]; do
    case "$1" in
        --panel-reads|--panel-sql)
            [[ -z "$read_stage" ]] || refuse 'select one panel read mode'
            read_stage=$1
            ;;
        --panel-writes)
            [[ -z "$panel_writes" ]] || refuse 'give --panel-writes only once'
            panel_writes=1
            ;;
        *) refuse 'unsupported panel mode' ;;
    esac
    shift
done
(( $# == 5 )) || refuse 'usage: benchmark-schema-scale-native.sh [--panel-reads|--panel-sql] [--panel-writes] DRIVER IMAGE_ID APPLICATION ROWS ABSOLUTE_NEW_OUTPUT'
driver=$1
image=$2
application=$(cd -- "$3" && pwd -P) || refuse 'the built application is unavailable'
rows=$4
out=$5
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
case "$driver" in
    pgsql) port=5432; app_pdo=pdo_pgsql ;;
    mysql|mariadb) port=3306; app_pdo=pdo_mysql ;;
    *) refuse 'use pgsql, mysql or mariadb' ;;
esac
case "$rows" in 10000|100000|1000000) ;; *) refuse 'use 10000, 100000 or 1000000 rows' ;; esac

[[ "$root" =~ ^/[A-Za-z0-9/._-]+$ && "$out" =~ ^/[A-Za-z0-9/._-]+$ && "$out" != */ \
    && "$(cd -- "$(dirname -- "$out")" && pwd -P)" == "$(dirname -- "$out")" \
    && "$out/" != "$root/"* && "$out/" != "$application/"* && ! -e "$out" && ! -L "$out" ]] \
    || refuse 'use a new canonical output directory outside the source and application'
[[ -f "$application/composer.lock" && -f "$application/vendor/autoload.php" && -f "$application/bootstrap/app.php" \
    && ! -e "$application/.env" && ! -e "$application/bootstrap/cache/config.php" ]] \
    || refuse 'use a built application with its lock and without runtime configuration'
php_bin=$(command -v php) || refuse 'PHP 8.4 or newer is required'
"$php_bin" -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded($argv[1]) ? 0 : 1);' "$app_pdo" \
    || refuse "PHP 8.4 or newer and $app_pdo are required"
command -v node >/dev/null || refuse 'install the browser dependencies listed in the reproduction guide'
node -e 'require.resolve("playwright", {paths: [process.argv[1]]})' "$root" >/dev/null 2>&1 \
    || refuse 'install the checkout browser dependencies'

docker_local() { env -i PATH="$PATH" HOME="$HOME" docker "$@"; }
context=$(docker_local context show)
[[ "$context" =~ ^[a-zA-Z0-9_.-]+$ ]] || refuse 'use a valid Docker context'
endpoint=$(docker_local context inspect "$context" --format '{{.Endpoints.docker.Host}}')
[[ "$endpoint" == unix://* && "$endpoint" != *$'\n'* ]] || refuse 'use a local Docker socket'
engine() { docker_local --context "$context" "$@"; }
[[ "$image" =~ ^sha256:[0-9a-f]{64}$ && "$(engine image inspect "$image" --format '{{.Id}} {{.Os}}')" == "$image linux" ]] \
    || refuse 'use a complete, locally present Linux image ID'

umask 077
mkdir -- "$out"
mkdir -p -- "$out"/storage/{app/{private,public},framework/{cache/data,sessions,views},logs}
nonce=$("$php_bin" -r 'echo bin2hex(random_bytes(12));')
password=$("$php_bin" -r 'echo bin2hex(random_bytes(24));')
database="kitsune_scale_$nonce"
lease_name="kitsune-scale-$nonce"
printf '%s\n%s\n' "$context" "$endpoint" > "$out/docker-context.txt"
credentials="$out/container.env"
client_options=()
if [[ "$driver" == pgsql ]]; then
    printf 'POSTGRES_DB=%s\nPOSTGRES_USER=scale\nPOSTGRES_PASSWORD=%s\n' "$database" "$password" > "$credentials"
else
    root_password=$("$php_bin" -r 'echo bin2hex(random_bytes(24));')
    prefix=MYSQL
    [[ "$driver" != mariadb ]] || prefix=MARIADB
    printf '%s_DATABASE=%s\n%s_USER=scale\n%s_PASSWORD=%s\n%s_ROOT_PASSWORD=%s\n' \
        "$prefix" "$database" "$prefix" "$prefix" "$password" "$prefix" "$root_password" > "$credentials"
    printf '[client]\nuser=scale\npassword=%s\nprotocol=tcp\nhost=127.0.0.1\n' "$password" > "$out/client.cnf"
    client_options=(--mount "type=bind,src=$out/client.cnf,dst=/run/kitsune-scale.cnf,readonly")
fi

container=
volumes=
lease_recorded=
owned() {
    [[ "$container" =~ ^[0-9a-f]{64}$
        && "$(engine container inspect "$container" --format '{{.Name}} {{index .Config.Labels "io.kitsune.scale.native"}}')" == "/$lease_name $nonce" ]]
}

record_lease() {
    owned || return 1
    volumes=$(engine container inspect "$container" --format '{{range .Mounts}}{{if eq .Type "volume"}}{{println .Name}}{{end}}{{end}}') || return 1
    printf 'container=%s\nvolumes=%s\n' "$container" "$volumes" > "$out/cleanup-attempt.txt" || return 1
    lease_recorded=1
}

absent() {
    local kind=$1 id=$2 verdict attempt
    for (( attempt=0; attempt<20; attempt++ )); do
        if ! verdict=$(engine "$kind" inspect "$id" --format '{{.Name}}' 2>&1); then
            if [[ "$verdict" == *'No such'* || "$verdict" == *'no such'* ]]; then return; fi
            return 1
        fi
        sleep 0.25
    done
    return 1
}

cleanup() {
    local status=$? volume
    trap - EXIT
    trap '' INT TERM
    if [[ "$lease_recorded" != 1 ]]; then
        if ! container=$(engine container inspect "$lease_name" --format '{{.Id}}' 2>/dev/null); then
            absent container "$lease_name" || refuse 'could not verify the failed container launch'
            exit "$status"
        fi
        record_lease || refuse 'could not verify ownership for cleanup'
    fi
    if owned; then
        engine stop --time 10 "$container" > "$out/cleanup.log" 2>&1 || true
    fi
    absent container "$container" || refuse 'could not verify removal of the owned container'
    while IFS= read -r volume; do
        if [[ -n "$volume" ]] && ! absent volume "$volume"; then
            refuse 'could not verify removal of the owned database volume'
        fi
    done <<< "$volumes"
    printf 'verified\n' > "$out/cleanup-result.txt"
    "$php_bin" -r 'foreach (array_slice($argv, 1) as $file) { if (is_file($file)) unlink($file); }' \
        "$credentials" "$out/client.cnf"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
container=$(engine run --pull never --rm -d --name "$lease_name" --label "io.kitsune.scale.native=$nonce" \
    --cpus 2 --memory 2g --memory-swap 2g --pids-limit 256 \
    --publish "127.0.0.1::$port" --env-file "$credentials" "${client_options[@]}" "$image")
record_lease || refuse 'the started container does not match the owned lease'

published=$(engine port "$container" "$port/tcp")
[[ "$published" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || refuse 'the database must publish exactly one IPv4 loopback port'
loopback_port=${BASH_REMATCH[1]}
(( loopback_port >= 1024 && loopback_port <= 65535 )) || refuse 'invalid loopback port'
engine container inspect "$container" --format 'container={{.Id}} image={{.Image}} cpus={{.HostConfig.NanoCpus}} memory={{.HostConfig.Memory}} memory_with_swap={{.HostConfig.MemorySwap}} pids_limit={{.HostConfig.PidsLimit}}' > "$out/database-runtime.txt"

if [[ "$driver" == pgsql ]]; then
    sql=(sh -ec 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec "$@"' scale
        psql -XqAt --host 127.0.0.1 --username scale --dbname "$database" --set ON_ERROR_STOP=1)
    rows_column='"rows"'
else
    sql=("$driver" --defaults-extra-file=/run/kitsune-scale.cnf --batch --skip-column-names "$database")
    rows_column='`rows`'
fi
ready=
for (( attempt=0; attempt<45; attempt++ )); do
    if printf 'SELECT 1;\n' | engine exec -i "$container" "${sql[@]}" >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[[ "$ready" == 1 ]] || refuse 'the owned database did not become ready'
root_sha=$("$php_bin" -r 'echo hash("sha256", $argv[1]);' "$root")
engine exec -i "$container" "${sql[@]}" > "$out/ownership.log" 2>&1 <<SQL
CREATE TABLE kitsune_scale_ownership (nonce VARCHAR(24), root_sha256 VARCHAR(64), $rows_column INTEGER, driver VARCHAR(10));
INSERT INTO kitsune_scale_ownership VALUES ('$nonce', '$root_sha', $rows, '$driver');
SQL

"$php_bin" -r '
    [, $root, $rows, $id, $driver, $database, $nonce, $port] = $argv;
    echo json_encode([
        "purpose" => "kitsune-schema-scale", "root" => $root, "rows" => (int) $rows,
        "native" => ["container_id" => $id, "driver" => $driver, "database" => $database,
            "host" => "127.0.0.1", "nonce" => $nonce, "port" => (int) $port],
    ], JSON_THROW_ON_ERROR), "\n";
' "$root" "$rows" "$container" "$driver" "$database" "$nonce" "$loopback_port" > "$out/owned-experiment.json"
key=$("$php_bin" -r 'echo base64_encode(random_bytes(32));')
cat > "$out/.env" <<ENV
APP_ENV=local
APP_DEBUG=false
APP_KEY=base64:$key
APP_URL=http://localhost
DB_CONNECTION=$driver
DB_HOST=127.0.0.1
DB_PORT=$loopback_port
DB_DATABASE=$database
DB_USERNAME=scale
DB_PASSWORD=$password
DB_FOREIGN_KEYS=true
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
APP_PACKAGES_CACHE="$out/packages.php"
APP_SERVICES_CACHE="$out/services.php"
ENV

source_sha=$(git -C "$root" --no-replace-objects rev-parse HEAD)
source_clean=yes
[[ -z "$(git -C "$root" --no-replace-objects status --porcelain=v1)" ]] || source_clean=no
printf 'source_head=%s\ncheckout_matches_source_head=%s\ndriver=%s\nrows=%s\n' "$source_sha" "$source_clean" "$driver" "$rows" > "$out/native.txt"
seed_args=(--native)
if [[ "$panel_writes" == 1 ]]; then seed_args+=(--panel-writes); fi
env -i PATH="$PATH" "$php_bin" -d memory_limit=128M "$root/bin/benchmark-schema-scale.php" "${seed_args[@]}" "$application" "$rows" "$out" > "$out/seed.log" 2>&1 \
    || refuse "seed failed; see $out/seed.log"
env -i PATH="$PATH" HOME="$HOME" KITSUNE_SCALE_DOCKER_CONTEXT="$context" node "$root/bin/benchmark-schema-relations.cjs" --native "$php_bin" "$application" "$out" > "$out/browser.log" 2>&1 \
    || refuse "browser benchmark failed; see $out/browser.log"
if [[ -n "$read_stage" ]]; then
    env -i PATH="$PATH" HOME="$HOME" KITSUNE_SCALE_DOCKER_CONTEXT="$context" node "$root/bin/benchmark-schema-relations.cjs" --native "$read_stage" "$php_bin" "$application" "$out" > "$out/panel-reads.log" 2>&1 \
        || refuse "panel reads failed; see $out/panel-reads.log"
fi
if [[ "$panel_writes" == 1 ]]; then
    env -i PATH="$PATH" HOME="$HOME" KITSUNE_SCALE_DOCKER_CONTEXT="$context" node "$root/bin/benchmark-schema-relations.cjs" --native --panel-writes "$php_bin" "$application" "$out" > "$out/panel-writes.log" 2>&1 \
        || refuse "panel writes failed; see $out/panel-writes.log"
fi
printf 'Native schema benchmark completed: %s\n' "$out"
