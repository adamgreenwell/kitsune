# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

FROM composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php@sha256:cc45fc347dcdef585e907fa7dab27087039f2adf7bd911c22b79a3d80cc98d08

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git libicu-dev libzip-dev nginx openssl tini util-linux unzip \
    && docker-php-ext-install -j2 intl zip \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ARG KITSUNE_SOURCE_SHA=29b6124e0c2e96deb188b63e00bf590cdc512831
LABEL org.opencontainers.image.base.revision="$KITSUNE_SOURCE_SHA"
ENV COMPOSER_ALLOW_SUPERUSER=1 GIT_TERMINAL_PROMPT=0
WORKDIR /srv/kitsune
RUN git init -q /tmp/source \
    && git -C /tmp/source fetch --depth=1 https://github.com/adamgreenwell/kitsune.git "$KITSUNE_SOURCE_SHA" \
    && test "$(git -C /tmp/source rev-parse FETCH_HEAD)" = "$KITSUNE_SOURCE_SHA" \
    && git -C /tmp/source archive FETCH_HEAD skeleton packages/core packages/person | tar -xf - \
    && mv skeleton app \
    && rm -rf /tmp/source
WORKDIR /srv/kitsune/app
COPY app-dependencies/ ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && mkdir -p public bootstrap/cache /var/lib/kitsune-rehearsal /var/cache/kitsune-rehearsal \
    && chown -R www-data:www-data bootstrap/cache /var/cache/kitsune-rehearsal /var/lib/kitsune-rehearsal \
    && find storage -type f ! -name .gitignore -delete \
    && rm -rf /root/.composer/cache /root/.cache/composer \
    && mv storage /srv/kitsune/storage-template \
    && ln -s /var/lib/kitsune-rehearsal/storage storage \
    && ln -s /var/lib/kitsune-rehearsal/runtime.env .env \
    && ln -s /var/lib/kitsune-rehearsal/storage/app/public public/storage \
    && printf '%s\n' '[www]' 'listen = 127.0.0.1:9000' 'clear_env = yes' > /usr/local/etc/php-fpm.d/zz-kitsune-rehearsal.conf \
    && printf '%s\n' 'expose_php=Off' 'display_errors=Off' 'zend.exception_ignore_args=On' 'memory_limit=128M' > /usr/local/etc/php/conf.d/kitsune-rehearsal.ini
COPY --chmod=0755 start.sh /usr/local/bin/kitsune-rehearsal-start
COPY --chmod=0755 origin-init.sh /usr/local/bin/kitsune-rehearsal-origin-init
COPY nginx.conf /etc/kitsune-rehearsal-nginx.conf

USER www-data
ENTRYPOINT ["/usr/bin/tini", "-g", "--", "/usr/local/bin/kitsune-rehearsal-start"]
