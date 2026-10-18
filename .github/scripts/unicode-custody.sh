#!/usr/bin/env bash
# Focused, disposable folding-engine qualification for #263. No default-suite runtime dependency.
set -euo pipefail

case "${DB_CONNECTION:-}" in mysql|mariadb) ;; *) exit 2 ;; esac
case "${DB_IMAGE:-}" in mysql:8.4|mariadb:10.6) ;; *) exit 2 ;; esac
container="kitsune-unicode-${GITHUB_RUN_ID:?}-${GITHUB_RUN_ATTEMPT:?}-${DB_CONNECTION}"
volume="${container}-data"
owner="${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"

cleanup() {
    result=$?
    trap - EXIT
    if docker inspect "$container" >/dev/null 2>&1; then
        actual=$(docker inspect --format '{{ index .Config.Labels "kitsune.unicode-ci" }}' "$container")
        [[ "$actual" == "$owner" ]] || exit 3
        docker rm -f "$container" >/dev/null
    fi
    if docker volume inspect "$volume" >/dev/null 2>&1; then
        actual=$(docker volume inspect --format '{{ index .Labels "kitsune.unicode-ci" }}' "$volume")
        [[ "$actual" == "$owner" ]] || exit 3
        docker volume rm "$volume" >/dev/null
    fi
    exit "$result"
}

# Never take ownership of a pre-existing name, including after an interrupted CI run.
if docker inspect "$container" >/dev/null 2>&1 || docker volume inspect "$volume" >/dev/null 2>&1; then
    exit 3
fi
trap cleanup EXIT

docker volume create --label "kitsune.unicode-ci=$owner" "$volume" >/dev/null
docker run -d --name "$container" --label "kitsune.unicode-ci=$owner" \
    -p 127.0.0.1::3306 -v "$volume:/var/lib/mysql" \
    -e MYSQL_DATABASE=kitsune_custody_probe_ci -e MYSQL_USER=kitsune \
    -e MYSQL_PASSWORD=kitsune -e MYSQL_ROOT_PASSWORD=kitsune-root \
    "$DB_IMAGE" --lower-case-table-names=1 >/dev/null
export DB_HOST=127.0.0.1
DB_PORT=$(docker inspect --format '{{ (index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort }}' "$container")
export DB_PORT DB_DATABASE=kitsune_custody_probe_ci DB_USERNAME=kitsune DB_PASSWORD=kitsune

ready=false
for attempt in {1..90}; do
    if php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
[[ "$ready" == true ]]

KITSUNE_CUSTODY_PROBE=disposable KITSUNE_PROBE_HOST="$DB_HOST" KITSUNE_PROBE_PORT="$DB_PORT" \
    KITSUNE_PROBE_DATABASE="$DB_DATABASE" KITSUNE_PROBE_USERNAME="$DB_USERNAME" KITSUNE_PROBE_PASSWORD="$DB_PASSWORD" \
    php tools/commerce-custody/probe.php > "${RUNNER_TEMP:?}/unicode-${DB_CONNECTION}-identifiers.json"

# Refuse a misconfigured server instead of silently accepting nineteen skipped Unicode cases.
php -r '$p = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); if ((string) $p->query("select @@lower_case_table_names")->fetchColumn() !== "1") { exit(1); }'

vendor/bin/pest tests/Core/Modules/CommerceUnicodeCustodyTest.php \
    tests/Core/Modules/CommerceMySqlCustodyTest.php tests/Commerce/CommerceCustodyTest.php \
    tests/Commerce/CommerceModuleTest.php --colors=always \
    --log-junit "$RUNNER_TEMP/unicode-${DB_CONNECTION}.xml"

# Audit data before disposal; report names/counts only, never fixture or customer payloads.
php <<'PHP'
<?php
$p = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE').';charset=utf8mb4', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
foreach ($p->query('show tables')->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if ($name !== 'migrations' && (int) $p->query('select count(*) from `'.str_replace('`', '``', $name).'`')->fetchColumn() !== 0) {
        fwrite(STDERR, 'Disposable custody fixture data remains in '.$name.PHP_EOL);
        exit(1);
    }
}
PHP
