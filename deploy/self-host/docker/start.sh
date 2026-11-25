#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail
umask 077

refuse() {
    printf 'Kitsune startup refused: %s\n' "$*" >&2
    exit 1
}

cd /srv/kitsune
state=/var/lib/kitsune
config=$state/.env
database=$state/database.sqlite
storage=$state/storage
installed=$state/.installed
image_release=/usr/local/share/kitsune-release
image_source=/usr/local/share/kitsune-source-commit

publish_record() {
    local source=$1 destination=$2 pending
    pending=$(mktemp "$state/.pending-receipt.XXXXXXXX")
    if ! cat "$source" > "$pending" || ! sync "$pending" || ! mv -T -- "$pending" "$destination" || ! sync -f "$state"; then
        rm -f -- "$pending"
        refuse 'could not persist an installation receipt'
    fi
}

mountpoint -q "$state" || refuse "mount a persistent data volume at $state"
[ ! -L "$state/.lock" ] || refuse 'data volume contains a linked lock file'
# Keep the descriptor open until both services stop.
exec 9>>"$state/.lock"
flock -n 9 || refuse 'another container holds this data volume'
for path in "$config" "$database" "$storage" "$installed"; do
    [ ! -L "$path" ] || refuse 'data volume contains linked application state'
done

install_pending=false
if [ -e "$installed" ]; then
    [ -f "$installed" ] || refuse 'data volume contains an unreadable installation receipt'
    if cmp -s "$installed" "$image_release/installing"; then
        install_pending=true
    elif cmp -s "$installed" "$image_release/installed" || cmp -s "$installed" "$image_source"; then
        # The source-only comparison supports completed legacy installs, without granting them archive provenance.
        [ -f "$database" ] && [ -s "$database" ] || refuse 'restore the database for this existing installation'
    else
        refuse 'this volume belongs to a different release or its receipt is invalid; inspect it before proceeding'
    fi
else
    [ ! -e "$config" ] && [ ! -e "$database" ] && [ ! -e "$storage" ] \
        || refuse 'existing state has no installation receipt; inspect the volume before proceeding'
    install_pending=true
fi

if [ ! -e "$config" ]; then
    [ ! -e "$database" ] && [ ! -e "$storage" ] \
        || refuse 'restore the configuration and APP_KEY for the existing data'
    url=${KITSUNE_APP_URL:-http://localhost:8080}
    php -r '$port = parse_url($argv[1], PHP_URL_PORT); exit($port !== false && $port !== 0 && preg_match("~\Ahttps?://(?:[a-zA-Z0-9.-]+|\\[[a-fA-F0-9:]+\\])(?::[0-9]{1,5})?\z~D", $argv[1]) === 1 ? 0 : 1);' "$url" \
        || refuse 'KITSUNE_APP_URL must be an HTTP or HTTPS origin'
    case "$url" in
        https://*) secure=true ;;
        http://*) secure=false ;;
    esac
    if [ ! -e "$installed" ]; then
        # The versioned interim receipt also fences older images, which require a lone source commit.
        publish_record "$image_release/installing" "$installed"
    fi
    key=$(php -r 'echo base64_encode(random_bytes(32));')
    pending=$(mktemp "$state/.pending-env.XXXXXXXX")
    trap '[[ ! -e "$pending" ]] || unlink "$pending"' EXIT
    printf '%s\n' \
        'APP_NAME=Kitsune' 'APP_ENV=production' 'APP_DEBUG=false' "APP_KEY=base64:$key" "APP_URL=$url" \
        'DB_CONNECTION=sqlite' "DB_DATABASE=$database" 'SESSION_DRIVER=file' "SESSION_SECURE_COOKIE=$secure" \
        'CACHE_STORE=file' 'QUEUE_CONNECTION=sync' 'MAIL_MAILER=log' 'LOG_CHANNEL=stderr' > "$pending"
    unset key
    # The key must be durable before an installer creates or migrates its database.
    sync "$pending"
    mv -T -- "$pending" "$config"
    sync -f "$state"
    trap - EXIT
fi

# Read the serving scheme and port and verify that a persisted key exists without outputting it.
origin=$(php -r '
    require "vendor/autoload.php";
    try {
        $config = Dotenv\Dotenv::parse(file_get_contents($argv[1]));
        $key = $config["APP_KEY"] ?? "";
        $url = parse_url($config["APP_URL"] ?? "");
        $scheme = $url["scheme"] ?? "";
        $port = $url["port"] ?? null;
        if (!preg_match("/\Abase64:[A-Za-z0-9+\\/=]+\z/", $key)
            || strlen(base64_decode(substr($key, 7), true) ?: "") !== 32
            || !in_array($scheme, ["http", "https"], true)
            || $port === 0
            || !isset($url["host"])
            || ($config["APP_ENV"] ?? null) !== "production"
            || ($config["APP_DEBUG"] ?? null) !== "false"
            || ($config["DB_CONNECTION"] ?? null) !== "sqlite"
            || ($config["DB_DATABASE"] ?? null) !== $argv[2]) {
            exit(1);
        }
        echo $scheme, $port === null ? "" : ":".$port;
    } catch (Throwable) {
        exit(1);
    }
' "$config" "$database") || refuse 'review the private volume configuration, APP_KEY and SQLite path'

scheme=${origin%%:*}
port_suffix=${origin#"$scheme"}
if [ "$scheme" = https ]; then
    [ -r /run/kitsune-tls/fullchain.pem ] && [ -r /run/kitsune-tls/privkey.pem ] \
        || refuse 'HTTPS needs a readable certificate and key mounted at /run/kitsune-tls'
    printf '%s\n' 'listen 8080 ssl;' 'ssl_certificate /run/kitsune-tls/fullchain.pem;' \
        'ssl_certificate_key /run/kitsune-tls/privkey.pem;' 'ssl_protocols TLSv1.2 TLSv1.3;' > /tmp/kitsune-listen.conf
else
    printf '%s\n' 'listen 8080;' > /tmp/kitsune-listen.conf
fi
printf 'set $kitsune_origin_port "%s";\n' "$port_suffix" >> /tmp/kitsune-listen.conf
printf '%s\n' "$scheme" > /tmp/kitsune-scheme
nginx -t -c /etc/kitsune/nginx.conf

mkdir -p "$storage/app/private" "$storage/app/public" "$storage/framework/cache/data" \
    "$storage/framework/sessions" "$storage/framework/views" "$storage/logs"
[ -e "$database" ] || touch "$database"
env -i PATH="$PATH" php artisan migrate --force --no-interaction
if [ "$install_pending" = true ]; then
    publish_record "$image_release/installed" "$installed"
fi

# Both processes serve the same application. Exit if either one stops so
# Docker sees a process failure, and drain both on normal shutdown.
php-fpm --nodaemonize 9>&- &
fpm=$!
nginx -c /etc/kitsune/nginx.conf -g 'daemon off;' 9>&- &
nginx=$!
stop() {
    kill -QUIT "$nginx" "$fpm" 2>/dev/null || true
    wait "$nginx" "$fpm" 2>/dev/null || true
}
trap 'stop; exit 0' TERM INT
wait -n "$nginx" "$fpm" || true
stop
refuse 'a serving process exited'
