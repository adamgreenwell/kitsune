# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

FROM composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php@sha256:cc45fc347dcdef585e907fa7dab27087039f2adf7bd911c22b79a3d80cc98d08

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git libicu-dev libpq-dev libzip-dev nginx openssl supervisor unzip \
    && docker-php-ext-install -j2 intl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# The deployed application is the qualified application commit, independently of the
# branch carrying this infrastructure profile. No host credentials enter a build.
ARG KITSUNE_SOURCE_SHA=dec5b98e9b605685b39965283da960089bd38c90
ENV GIT_TERMINAL_PROMPT=0 COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /srv/kitsune
RUN test "${#KITSUNE_SOURCE_SHA}" = 40 \
    && git init . \
    && git remote add origin https://github.com/adamgreenwell/kitsune.git \
    && git fetch --depth=1 origin "$KITSUNE_SOURCE_SHA" \
    && git checkout --detach FETCH_HEAD \
    && test "$(git rev-parse HEAD)" = "$KITSUNE_SOURCE_SHA" \
    && composer config -d skeleton repositories.kitsune-packages '{"type":"path","url":"../packages/*","options":{"symlink":false}}' \
    && composer require -d skeleton 'kitsune/commerce:@dev' --no-update --no-scripts --no-interaction \
    && composer install -d skeleton --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && php deploy/release-check.php packages 1 \
    && composer audit -d skeleton --no-dev --abandoned=report \
    && printf '%s\n' "$KITSUNE_SOURCE_SHA" > /usr/local/share/kitsune-source-sha

COPY nginx.conf /etc/nginx/conf.d/kitsune.conf
COPY supervisord.conf /etc/supervisor/conf.d/kitsune.conf
COPY start.sh /usr/local/bin/kitsune-start
COPY tls-init.sh /usr/local/bin/kitsune-tls-init
RUN chmod 0755 /usr/local/bin/kitsune-start /usr/local/bin/kitsune-tls-init \
    && printf '%s\n' '[www]' 'listen = 127.0.0.1:9000' 'clear_env = no' > /usr/local/etc/php-fpm.d/zz-kitsune.conf \
    && printf '%s\n' 'expose_php=Off' 'display_errors=Off' 'zend.exception_ignore_args=On' 'memory_limit=256M' 'opcache.revalidate_freq=0' > /usr/local/etc/php/conf.d/kitsune.ini \
    && mkdir -p /srv/kitsune/skeleton/bootstrap/cache/views \
    && chown -R www-data:www-data /srv/kitsune/skeleton/bootstrap/cache /srv/kitsune/skeleton/public \
    && chmod 0700 /srv/kitsune/skeleton/bootstrap/cache

HEALTHCHECK --interval=15s --timeout=5s --start-period=120s --retries=10 \
    CMD curl --fail --silent --cacert /run/kitsune-tls/ca.crt \
    --resolve development.kitsunecms.org:8443:127.0.0.1 \
    -H 'Host: development.kitsunecms.org' https://development.kitsunecms.org:8443/up >/dev/null || exit 1
ENTRYPOINT ["/usr/local/bin/kitsune-start"]
