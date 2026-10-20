#!/bin/sh
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -eu
cd /srv/kitsune
test -s /run/kitsune-tls/server.key
test -s /run/kitsune-tls/server.crt
test -s /run/kitsune-tls/ca.crt
# These are provisioned by Coolify before deployment, never generated at startup.
test -n "${APP_KEY:-}"
test -n "${DB_PASSWORD:-}"
test "${APP_ENV:-}" = production
test "${APP_DEBUG:-}" = false
test "${APP_URL:-}" = https://development.kitsunecms.org
test "${DB_CONNECTION:-}" = pgsql
test "${SESSION_SECURE_COOKIE:-}" = true
test ! -f skeleton/.env
test ! -L skeleton/.env
mkdir -p /data/storage/app/private /data/storage/app/public /data/storage/framework/cache/data \
    /data/storage/framework/sessions /data/storage/framework/views /data/storage/logs
chown -R www-data:www-data /data/storage
chmod 0700 /data/storage/app/private
if test ! -L skeleton/storage; then
    # Only the image's empty skeleton scaffold is replaced, never a mounted storage tree.
    test "$(find skeleton/storage -type f ! -name .gitignore | wc -l)" -eq 0
    rm -rf skeleton/storage
    ln -s /data/storage skeleton/storage
fi
test "$(readlink skeleton/storage)" = /data/storage
run_app() { su -s /bin/sh www-data -c "$1"; }
run_app 'php deploy/release-check.php environment 1'
run_app 'php skeleton/artisan package:discover --no-interaction'
run_app 'php skeleton/artisan filament:assets --no-interaction'
run_app 'php skeleton/artisan storage:link --no-interaction'
test -L skeleton/public/storage
for cache in config event route view icons; do
    run_app "php skeleton/artisan $cache:cache --no-interaction"
done
run_app 'php deploy/release-check.php serving 1'
run_app 'php skeleton/artisan migrate --force --no-interaction'
run_app 'php skeleton/artisan kitsune:credentials status --strict --no-interaction'
run_app 'php skeleton/artisan kitsune:audit-patterns --strict --no-interaction'
run_app 'php skeleton/artisan kitsune:schema-sync --no-interaction'
nginx -t
exec supervisord -c /etc/supervisor/conf.d/kitsune.conf
