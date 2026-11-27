#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail
umask 077

refuse() {
    printf 'Kitsune rehearsal fixture startup refused: %s\n' "$1" >&2
    exit 1
}

cd /srv/kitsune/app
state=/var/lib/kitsune-rehearsal
host=${KITSUNE_REHEARSAL_HOST:?Set the dedicated Kitsune rehearsal demo hostname}
[[ "$host" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]]
site_api=${KITSUNE_REHEARSAL_SITE_API:-false}
case "$site_api" in
    true|false) ;;
    *) refuse 'KITSUNE_REHEARSAL_SITE_API must be true or false' ;;
esac
mountpoint -q "$state" || refuse "mount the dedicated state volume at $state"
[[ ! -L "$state/.lock" ]]
exec 9>>"$state/.lock"
flock -n 9 || refuse 'the fixture state volume is in use'

keyfile="$state/app-key"
database="$state/database.sqlite"
[[ ! -L "$keyfile" && ! -L "$database" && ! -L "$state/storage" ]]
if [[ ! -e "$keyfile" ]]; then
    [[ ! -e "$database" && ! -e "$state/storage" && ! -e "$state/origin" ]]
    scratch=$(mktemp "$state/.key.XXXXXXXX")
    trap 'rm -f "$scratch"' EXIT
    php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;' > "$scratch"
    sync "$scratch"
    mv "$scratch" "$keyfile"
    sync -f "$state"
    trap - EXIT
fi
origin="$state/origin"
[[ ! -L "$origin" ]]
if [[ ! -e "$origin" ]]; then
    [[ ! -e "$database" ]] || refuse 'the persisted database has no origin record'
    printf '%s\n' "$host" > "$origin"
fi
[[ "$(cat "$origin")" = "$host" ]] || refuse 'the configured origin differs from this volume'
php -r '$s=trim(file_get_contents($argv[1])); exit(str_starts_with($s,"base64:") && strlen(base64_decode(substr($s,7),true) ?: "") === 32 ? 0 : 1);' "$keyfile"
[[ -f "$keyfile" ]]
[[ ! -L "$state/runtime.env" ]]
runtime=$(mktemp "$state/.runtime-env.XXXXXXXX")
trap 'rm -f "$runtime"' EXIT
{
    printf '%s\n' 'APP_ENV=production' 'APP_DEBUG=false' 'APP_NAME="Kitsune rehearsal"' "APP_URL=https://$host"
    printf 'APP_KEY=%s\n' "$(cat "$keyfile")"
    printf '%s\n' 'DB_CONNECTION=sqlite' "DB_DATABASE=$database" 'SESSION_DRIVER=file' 'SESSION_SECURE_COOKIE=true'
    printf 'KITSUNE_REHEARSAL_SITE_API=%s\n' "$site_api"
    printf '%s\n' 'CACHE_STORE=file' 'QUEUE_CONNECTION=sync' 'MAIL_MAILER=log' 'LOG_CHANNEL=stderr'
} > "$runtime"
mv "$runtime" "$state/runtime.env"
trap - EXIT

openssl verify -CAfile /run/kitsune-rehearsal-ca/ca.crt -verify_hostname "$host" /run/kitsune-rehearsal-origin/current/server.crt >/dev/null || refuse 'the TLS origin certificate is invalid for the fixture host'
printf 'server_name %s;\nif ($host != %s) { return 421; }\n' "$host" "$host" > /tmp/kitsune-rehearsal-origin.conf
[[ -e "$database" ]] || touch "$database"
mkdir -p "$state/storage"
cp -an /srv/kitsune/storage-template/. "$state/storage/"
mkdir -p "$state/storage/app/public"
php artisan migrate --force --no-interaction
if [[ "$site_api" = true ]]; then
    php artisan migrate --force --no-interaction --path=database/api-migrations
fi
php artisan route:cache
php artisan view:cache
php-fpm -F &
fpm=$!
touch /tmp/kitsune-rehearsal-nginx-error.log
tail -n +1 -F /tmp/kitsune-rehearsal-nginx-error.log &
logs=$!
nginx -e /tmp/kitsune-rehearsal-nginx-error.log -c /etc/kitsune-rehearsal-nginx.conf -g 'daemon off;' &
nginx=$!
trap 'kill -QUIT "$fpm" "$nginx" 2>/dev/null || true; wait "$fpm" "$nginx" || true; kill "$logs" 2>/dev/null || true' EXIT
trap 'exit 0' INT TERM
wait -n "$fpm" "$nginx"
exit 1
