#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail

refuse() {
    printf 'Docker upgrade refused: %s\n' "$*" >&2
    exit 1
}

image_id() {
    [[ $1 =~ ^sha256:[a-f0-9]{64}$ ]] || refuse 'use the exact local Docker image ID including sha256:'
    [[ "$(docker image inspect --format '{{.Id}}' -- "$1")" == "$1" ]] || refuse 'the selected image ID is unavailable'
}

local_volume() {
    [[ $1 =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]+$ ]] || refuse 'select an existing named Docker volume'
    [[ "$(docker volume inspect --format '{{.Driver}} {{len .Options}} {{.Name}}' -- "$1")" == "local 0 $1" ]] \
        || refuse 'the operation requires an existing standard local Docker volume'
}

phase=${1:-}
docker_host=${DOCKER_HOST:-}
if [[ -n "${DOCKER_CONTEXT:-}" || -z "$docker_host" ]]; then
    docker_host=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
fi
[[ "$docker_host" == unix:///* ]] || refuse 'use a local Unix-socket Docker context for this installation'
common=(--rm --pull never --network none --read-only --security-opt no-new-privileges)
if [[ "$phase" == prepare-volume ]]; then
    [[ $# == 3 ]] || refuse 'usage: upgrade.sh prepare-volume TARGET_IMAGE_ID EMPTY_VOLUME'
    image_id "$2"
    local_volume "$3"
    [[ -z "$(docker ps -aq --filter "volume=$3")" ]] || refuse 'the empty volume must be unattached'
    docker run "${common[@]}" --user 0:0 --mount "type=volume,src=$3,dst=/custody,volume-nocopy" \
        --entrypoint /bin/bash "$2" -euc '
            [[ -z "$(find /custody -mindepth 1 -maxdepth 1 -print -quit)" ]] || { echo "Expected an unused, empty volume." >&2; exit 1; }
            chown 33:33 /custody
            chmod 0700 /custody
        '
    exit
fi

case "$phase:$#" in
    request:6|migrate:6|stage:6|abort:6|recover:7|confirm-private:7|begin-traffic:7|confirm-traffic:7) ;;
    *) refuse 'usage: upgrade.sh PHASE ORIGIN_CONTAINER_ID TARGET_IMAGE_ID DATA_VOLUME CUSTODY_VOLUME CONTROL_DIR [RESTORE_VOLUME_OR_TARGET_CONTAINER_ID]' ;;
esac
origin_container=$2
target_image=$3
data=$4
custody=$5
[[ "$origin_container" =~ ^[a-f0-9]{64}$ ]] || refuse 'use the full stopped origin container ID'
image_id "$target_image"
local_volume "$data"
local_volume "$custody"
data_binding=$(docker volume inspect --format '{{.Name}}|{{.Driver}}|{{.CreatedAt}}|{{.Mountpoint}}' -- "$data"     | openssl dgst -sha256 -r | awk '{print $1}')
[[ "$data_binding" =~ ^[a-f0-9]{64}$ ]] || refuse 'the selected Docker volume could not be identified'
[[ "$data" != "$custody" ]] || refuse 'the data and private custody volumes must be separate'
controls=$(cd -- "$6" && pwd -P) || refuse 'the control directory is unavailable'
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd -P)
for path in "$controls" "$root"; do
    [[ "$path" != *','* && "$path" != *$'\n'* ]] || refuse 'the helper mount paths contain unsupported characters'
done

origin_info=$(docker inspect --type container --format '{{.Id}} {{.State.Status}} {{.Image}}' -- "$origin_container")
read -r actual_container origin_status origin_image <<<"$origin_info"
[[ "$actual_container" == "$origin_container" && "$origin_status" == exited && "$origin_image" != "$target_image" ]] \
    || refuse 'stop the selected origin container and select a distinct target image'
image_id "$origin_image"
profile_mounts() {
    local container=$1 mounts mount data_seen=0
    tls_mount=0
    mounts=$(docker inspect --type container --format '{{range .Mounts}}{{println .Destination .Type .Name .RW}}{{end}}' -- "$container")
    while IFS= read -r mount; do
        case "$mount" in
            "/var/lib/kitsune volume $data true") data_seen=1 ;;
            '/run/kitsune-tls bind  false') tls_mount=1 ;;
            '') ;;
            *) refuse 'the container uses additional mounts or does not own the selected writable data volume' ;;
        esac
    done <<<"$mounts"
    [[ "$data_seen" == 1 ]] || refuse 'the selected container must mount this named data volume'
}

profile_layer() {
    local container=$1 changes change
    [[ "$(docker inspect --format '{{json .Config.Entrypoint}} {{.Config.User}}' -- "$container")" == '["/usr/bin/tini","--","/usr/local/bin/kitsune-start"] www-data' ]] \
        || refuse 'the selected container must use the managed Kitsune entrypoint and runtime account'
    changes=$(docker diff "$container")
    while IFS= read -r change; do
        if [[ "$tls_mount" == 1 && ( "$change" == 'C /run' || "$change" == 'A /run/kitsune-tls' ) ]]; then
            continue
        fi
        case "${change:2}" in
            /tmp|/tmp/*|/var|/var/cache|/var/cache/kitsune|/var/cache/kitsune/*|\
            /srv|/srv/kitsune|/srv/kitsune/bootstrap|/srv/kitsune/bootstrap/cache|/srv/kitsune/bootstrap/cache/*|'') ;;
            *) refuse 'the selected container has local changes outside runtime cache paths' ;;
        esac
    done <<<"$changes"
}

profile_mounts "$origin_container"
profile_layer "$origin_container"

platform_format='{{.Os}}-{{.Architecture}}'
platform=$(docker image inspect --format "$platform_format" -- "$origin_image")
[[ "$platform" == linux-arm64 || "$platform" == linux-amd64 ]] || refuse 'the origin platform is outside the supported Docker profiles'
[[ "$(docker image inspect --format "$platform_format" -- "$target_image")" == "$platform" ]] \
    || refuse 'the target image must use the same supported platform as the origin'
origin_archive=$(docker image inspect --format '{{index .Config.Labels "org.kitsunecms.runtime-archive.sha256"}}' -- "$origin_image")
[[ "$origin_archive" =~ ^[a-f0-9]{64}$ ]] || refuse 'the origin image lacks its release archive digest'

worker=("${common[@]}" --user 33:33 --cap-drop ALL --mount "type=bind,src=$root/bin,dst=/tools,readonly" --entrypoint php)
metadata=$(docker run "${worker[@]}" "$origin_image" -d log_errors=1 -d error_log=/dev/stderr /tools/self-host-docker-upgrade.php origin)
recover_mount=()
if [[ "$phase" == recover ]]; then
    restore=$7
    local_volume "$restore"
    [[ -z "$(docker ps -aq --filter "volume=$restore")" ]] || refuse 'the restore volume must be unattached'
    [[ "$restore" != "$data" && "$restore" != "$custody" ]] || refuse 'recovery needs a separate empty volume'
    recover_mount=(--mount "type=volume,src=$restore,dst=/restored,volume-nocopy")
fi

if [[ "$phase" == confirm-private || "$phase" == begin-traffic || "$phase" == confirm-traffic ]]; then
    active=$7
    [[ "$active" =~ ^[a-f0-9]{64}$ ]] || refuse 'use the full running target container ID'
    active_info=$(docker inspect --type container --format '{{.Id}} {{.State.Status}} {{.Image}}' -- "$active")
    [[ "$active_info" == "$active running $target_image" ]] || refuse 'the running container does not use the signed target image'
    profile_mounts "$active"
    profile_layer "$active"
    ports=$(docker inspect --format '{{range $port, $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{println $port .HostIp .HostPort}}{{end}}{{end}}' -- "$active")
    # Read only the validated origin fields. Never emit the persisted environment.
    endpoint=$(docker exec "$active" php -r '
        require "/srv/kitsune/vendor/autoload.php";
        try {
            $url = Dotenv\Dotenv::parse(file_get_contents("/var/lib/kitsune/.env"))["APP_URL"] ?? "";
            if (!preg_match("~\A(https?)://([a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])(?::([0-9]{1,5}))?\z~D", $url, $matches)) exit(1);
            $port = $matches[3] ?? ($matches[1] === "https" ? "443" : "80");
            if ((int) $port < 1 || (int) $port > 65535) exit(1);
            echo $matches[1]," ",$matches[2]," ",$port," ",$url;
        } catch (Throwable) {
            exit(1);
        }
    ') || refuse 'the target origin cannot be used for health readback'
    read -r scheme hostname origin_port origin_url <<<"$endpoint"
    if [[ "$phase" == confirm-traffic ]]; then
        [[ "$ports" =~ ^8080/tcp[[:space:]]127\.0\.0\.1[[:space:]]([0-9]{1,5})$ ]] \
            || refuse 'publish only the managed port on the Docker host loopback before confirming traffic'
        public_port=${BASH_REMATCH[1]}
        for route in /up /admin/login; do
            status=$(curl --fail --silent --show-error --max-time 5 --noproxy '*' -o /dev/null -w '%{http_code}' \
                --connect-to "$hostname:$origin_port:127.0.0.1:$public_port" "$origin_url$route")
            [[ "$status" == 200 ]] || refuse "target listener did not return HTTP 200 for $route"
        done
    else
        [[ -z "$ports" && "$(docker inspect --format '{{.HostConfig.NetworkMode}}' -- "$active")" == none ]] \
            || refuse 'validate the private target in a network-isolated container with no published ports'
        for route in /up /admin/login; do
            status=$(docker exec "$active" curl --insecure --fail --silent --show-error --max-time 5 --noproxy '*' \
                -o /dev/null -w '%{http_code}' "$scheme://127.0.0.1:8080$route")
            [[ "$status" == 200 ]] || refuse "private target did not return HTTP 200 for $route"
        done
    fi
fi

exec docker run "${worker[@]}" \
    --mount "type=bind,src=$controls,dst=/controls,readonly" \
    --mount "type=volume,src=$data,dst=/var/lib/kitsune,volume-nocopy" \
    --mount "type=volume,src=$custody,dst=/custody,volume-nocopy" \
    "${recover_mount[@]}" --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \
    --tmpfs /srv/kitsune/bootstrap/cache:rw,nosuid,nodev,uid=33,gid=33,mode=0700 \
    "$target_image" -d log_errors=1 -d error_log=/dev/stderr /tools/self-host-docker-upgrade.php \
    "$phase" "$platform" "${origin_image#sha256:}" "${target_image#sha256:}" "$origin_archive" "$data_binding" "$metadata"
