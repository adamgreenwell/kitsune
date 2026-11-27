#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail

mode=${1:-false}
case "$mode" in
    true|false) ;;
    *) echo 'Usage: qualify.sh [true|false]' >&2; exit 2 ;;
esac
unset KITSUNE_REHEARSAL_SITE_API
if [[ "$mode" = true ]]; then
    export KITSUNE_REHEARSAL_SITE_API=true
fi
p=$(cd "$(dirname "$0")" && pwd)
project="kitsune-rehearsal-$(openssl rand -hex 6)"
export KITSUNE_REHEARSAL_HOST=migration.example.test
compose=(docker compose --project-name "$project" --file "$p/compose.yaml")
cleanup() {
    "${compose[@]}" down --volumes >/dev/null 2>&1 || return 1
    test -z "$(docker ps -a --filter "label=com.docker.compose.project=$project" --format '{{.Names}}')"
    test -z "$(docker volume ls --filter "label=com.docker.compose.project=$project" --format '{{.Name}}')"
    printf '%s\n' 'The temporary Compose project has no remaining containers or volumes.'
}
trap cleanup EXIT
"${compose[@]}" up -d --no-build --wait --wait-timeout 60
container=$("${compose[@]}" ps --quiet app)
test -n "$container"
witness() {
    docker inspect --format '{{.HostConfig.NetworkMode}} {{.HostConfig.NanoCpus}} {{.HostConfig.Memory}} {{json .HostConfig.PortBindings}}' "$1"
}
runtime=$(witness "$container")
printf 'runtime=%s\n' "$runtime"
test "$runtime" = 'none 1000000000 1073741824 {}' || test "$runtime" = 'none 1000000000 1073741824 null'
el() {
    "${compose[@]}" exec -T app "$@"
}
request() {
    el curl -sS --noproxy '*' --cacert /run/kitsune-rehearsal-ca/ca.crt \
        --resolve "$KITSUNE_REHEARSAL_HOST:8443:127.0.0.1" -H "Host: $KITSUNE_REHEARSAL_HOST" "$@"
}
for url in /up /admin/login; do
    test "$(request -o /dev/null -w '%{http_code}' "https://$KITSUNE_REHEARSAL_HOST:8443$url")" = 200
done
printf '%s\n' 'Trusted HTTPS returned 200 for health and admin login.'
routes=$(el php artisan route:list --json)
test "$(printf '%s' "$routes" | jq 'map(select(.uri | test("^market(/|$)"))) | length')" = 0
token_storage=$(el php -r '
    require "vendor/autoload.php";
    $db = new PDO("sqlite:/var/lib/kitsune-rehearsal/database.sqlite");
    $table = $db->prepare("SELECT name FROM sqlite_master WHERE name = ?");
    $table->execute([\Kitsune\Core\Http\Api\SiteTokenSchema::TABLE]);
    echo $table->fetchColumn() === false ? "absent" : "present";
')
if [[ "$mode" = true ]]; then
    test "$token_storage" = present
    test "$(printf '%s' "$routes" | jq 'map(select(.uri == "api/v1/schema")) | length')" = 1
else
    test "$token_storage" = absent
    test "$(printf '%s' "$routes" | jq 'map(select(.uri | test("^api/v1(/|$)"))) | length')" = 0
    test "$(request -o /dev/null -w '%{http_code}' "https://$KITSUNE_REHEARSAL_HOST:8443/api/v1/schema")" = 404
    printf '%s\n' 'Site API routes and token storage are absent by default.'
fi
el php -r '
  require "vendor/autoload.php";
  foreach (["laravel/framework","filament/filament","livewire/livewire","league/commonmark"] as $name)
      echo $name." ".Composer\InstalledVersions::getPrettyVersion($name).PHP_EOL;
'
openssl rand -hex 30 | el php artisan kitsune:blueprint apply marketing-site --org=migration --owner=owner@example.test --owner-password-stdin --no-interaction
el php artisan kitsune:site address "https://$KITSUNE_REHEARSAL_HOST" --org=migration --site=migration --no-interaction
base="https://$KITSUNE_REHEARSAL_HOST:8443/api/v1"
if [[ "$mode" = true ]]; then
    test "$(request -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$base/schema")" = 401
    actor=$(el php -r '$db=new PDO("sqlite:/var/lib/kitsune-rehearsal/database.sqlite"); echo $db->query("SELECT id FROM users")->fetchColumn();')
    token=$(el php artisan kitsune:api-token issue --org=migration --site=migration --actor="$actor" --name=fixture-import \
        --ability=entry.page.view --ability=entry.page.create --ability=entry.page.update --days=1 --no-interaction --no-ansi)
    if ! [[ "$token" =~ ^[0-9]+\|[A-Za-z0-9_-]+$ ]]; then
        echo 'The issuer did not return an expected credential.' >&2
        exit 4
    fi
    api() {
        printf 'header = "Authorization: Bearer %s"\n' "$token" | request --config - -H 'Accept: application/json' "$@"
    }
    api "$base/schema" | jq -e '.data | any(.handle=="page")' >/dev/null
    created=$(api -H 'Content-Type: application/json' -X POST --data \
        '{"title":"Fixture article","slug":"fixture-article","values":{"page_summary":"Verified import preview"}}' "$base/entries/page")
    id=$(printf '%s' "$created" | jq -er '.data.id | select(type=="number" and .>0)')
    api "$base/entries/page/$id" | jq -e '.data.title=="Fixture article" and .data.status=="draft" and .data.values.page_summary=="Verified import preview"' >/dev/null
    denied=$(api -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -X PATCH --data '{"status":"published"}' "$base/entries/page/$id")
    test "$denied" = 403
    api "$base/entries/page/$id" | jq -e '.data.status=="draft"' >/dev/null
    el php artisan kitsune:api-token revoke --org=migration --site=migration --id="${token%%|*}" --no-interaction
    test "$(api -o /dev/null -w '%{http_code}' "$base/entries/page/$id")" = 401
    unset token
    printf '%s\n' 'The scoped credential created one draft. Publication was refused. Revocation took effect on the next HTTPS request.'
else
    # With a configured Site, this path reaches the ordinary HTML fallback.
    response=$(request -H 'Accept: application/json' -o /dev/null -w '%{http_code} %{content_type}' "$base/schema")
    [[ "$response" == '200 text/html'* ]]
fi

readback() {
    el php -r '
      $db = new PDO("sqlite:/var/lib/kitsune-rehearsal/database.sqlite");
      if($db->query("PRAGMA integrity_check")->fetchColumn()!=="ok"||$db->query("PRAGMA foreign_key_check")->fetchAll()!==[])exit(2);
      $data=[];
      foreach(["orgs","sites","entry_types","entries","users"] as $table)
         $data[$table]=$db->query("SELECT * FROM ".$table." ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
      if(count($data["orgs"])!==1||count($data["sites"])!==1||count($data["users"])!==1)exit(3);
      if($argv[1]==="true" && count($data["entries"])!==1)exit(4);
      echo hash("sha256",serialize($data)).PHP_EOL;
    ' "$mode"
}
before=$(readback)
"${compose[@]}" up -d --no-build --force-recreate --wait --wait-timeout 60
afterContainer=$("${compose[@]}" ps --quiet app)
test "$afterContainer" != "$container"
test "$(witness "$afterContainer")" = "$runtime"
after=$(readback)
test "$before" = "$after"
printf '%s\n' 'The recreated container retained the provisioned Org, Site, owner and blueprint.'
test "$(request -o /dev/null -w '%{http_code}' "https://$KITSUNE_REHEARSAL_HOST:8443/up")" = 200
