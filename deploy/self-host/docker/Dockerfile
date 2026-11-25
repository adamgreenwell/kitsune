# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

FROM php@sha256:54ce0ded7582385bf6b522aff920d2eff5ec60da9476d8c52ce6aa69c9c5c7cb AS extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
    && docker-php-ext-install -j2 intl zip

FROM extensions AS application
ARG KITSUNE_ARCHIVE_SHA256
COPY application.tar.gz /tmp/application.tar.gz
RUN test "${#KITSUNE_ARCHIVE_SHA256}" -eq 64 \
    && printf '%s  /tmp/application.tar.gz\n' "$KITSUNE_ARCHIVE_SHA256" | sha256sum -c - \
    && mkdir /srv/kitsune \
    && tar --no-same-owner --no-same-permissions -xzf /tmp/application.tar.gz -C /srv/kitsune \
    && cd /srv/kitsune \
    && php -r 'require "vendor/autoload.php";' \
    && test -f public/index.php \
    && test ! -e .env \
    && test ! -e database/database.sqlite \
    && mkdir -p bootstrap/cache \
    && mv storage /tmp/storage-scaffold \
    && ln -s /var/lib/kitsune/storage storage \
    && ln -s /var/lib/kitsune/.env .env \
    && ln -s /var/lib/kitsune/storage/app/public public/storage

FROM php@sha256:54ce0ded7582385bf6b522aff920d2eff5ec60da9476d8c52ce6aa69c9c5c7cb
ARG KITSUNE_SOURCE_SHA
ARG KITSUNE_ARCHIVE_SHA256
LABEL org.opencontainers.image.revision="$KITSUNE_SOURCE_SHA" \
      org.kitsunecms.runtime-archive.sha256="$KITSUNE_ARCHIVE_SHA256"

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libicu72 libzip4 nginx tini util-linux \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && unlink /usr/local/bin/phpize \
    && unlink /usr/local/bin/php-config \
    && rm -rf /var/lib/apt/lists/* \
    && install -d -m 0755 /etc/kitsune \
    && install -d -m 0700 -o www-data -g www-data /var/lib/kitsune /var/cache/kitsune
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/docker-php-ext-intl.ini /usr/local/etc/php/conf.d/
COPY --from=extensions /usr/local/etc/php/conf.d/docker-php-ext-zip.ini /usr/local/etc/php/conf.d/
COPY --from=application /srv/kitsune /srv/kitsune
COPY --chmod=0755 start.sh /usr/local/bin/kitsune-start
COPY --chmod=0644 nginx.conf /etc/kitsune/
COPY --chmod=0644 php-fpm.conf /usr/local/etc/php-fpm.d/zz-kitsune.conf
RUN test "${#KITSUNE_SOURCE_SHA}" -eq 40 \
    && printf '%s' "$KITSUNE_SOURCE_SHA" | grep -Eq '^[a-f0-9]{40}$' \
    && printf '%s\n' "$KITSUNE_SOURCE_SHA" > /usr/local/share/kitsune-source-commit \
    && chown www-data:www-data /srv/kitsune/bootstrap/cache \
    && printf '%s\n' 'expose_php=Off' 'display_errors=Off' 'zend.exception_ignore_args=On' \
        'memory_limit=128M' 'post_max_size=24M' 'upload_max_filesize=20M' > /usr/local/etc/php/conf.d/kitsune.ini

WORKDIR /srv/kitsune
USER www-data
EXPOSE 8080
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s \
    CMD curl -kfsS --noproxy '*' "$(cat /tmp/kitsune-scheme)://127.0.0.1:8080/up" >/dev/null
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/kitsune-start"]
