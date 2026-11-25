# Kitsune in Docker

This profile serves a production Kitsune application with PHP-FPM, nginx and SQLite. It starts with an
empty database and keeps the same configuration and data across container replacement.

## Verify and build the image

Obtain the exact application archive, manifest and detached signature through the
[release verification workflow](../../../docs/release-artifacts.md). Obtain the trusted public key and
fingerprint through the release's independent trust channel. Build with the reviewed profile from the
matching release source.

```sh
deploy/self-host/docker/build.sh "$manifest" "$signature" "$public_key" "$fingerprint" \
  "$version" "$channel" "$application_archive" linux/arm64 "$image"
```

Use `linux/amd64` on AMD64. The command verifies a private copy of the archive before Docker receives it.
The resulting image records the application source commit and archive SHA-256. It retains the
verified manifest and signature at `/usr/local/share/kitsune-release/` for later inspection. Use the
independently trusted key and fingerprint when verifying those retained copies. The image uses the
PHP 8.4 official FPM image family and includes the built app, SQLite, nginx and runtime libraries.
The versioned artifact and production trust-root publication are tracked in [#213](https://github.com/adamgreenwell/kitsune/issues/213).

## Start and create the first owner

```sh
export KITSUNE_IMAGE="$image"
docker compose -f deploy/self-host/docker/compose.yaml up -d
docker compose -f deploy/self-host/docker/compose.yaml exec app \
  php artisan kitsune:blueprint apply blog --org=myblog --owner=you@example.test
```

Open <http://localhost:8080/admin/login>. The first-owner command prompts for a password in your terminal.
Use `KITSUNE_PORT` to change the initial local port. The Compose profile binds to loopback. The app runs as
the image's `www-data` user, UID 33. Run a single application container per data volume.

For public HTTPS, mount a certificate chain as `/run/kitsune-tls/fullchain.pem` and the corresponding key as
`/run/kitsune-tls/privkey.pem`, both read-only and readable by UID 33. Set `KITSUNE_APP_URL` to the HTTPS
origin on first start. nginx terminates TLS inside the container on port 8080. Initialize the owner through
the terminal before exposing that port publicly. Use the persisted `.env` to change the origin later and
keep `SESSION_SECURE_COOKIE=true` for HTTPS. The container health check probes the local nginx listener.
Check the public certificate separately when setting up the public address.

## Restart, recover and upgrade

The named volume at `/var/lib/kitsune` holds the SQLite file, the private `.env` with `APP_KEY`, and
application storage including media. Back up the whole volume, including its hidden receipt and configuration files, with the app stopped. Keep an encrypted
copy of `APP_KEY` alongside the [backup and restore](../../../docs/backup-restore.md) instructions.

Restart or recreate the container with the same image and volume. New installs record the precise archive
and manifest before writing configuration or running migrations. An interrupted first run can resume using
the same release archive and manifest, with its original configuration and key. A volume that contains application state without a
completion or matching interruption receipt needs inspection. Preserve the volume while investigating the
failure. Volumes from earlier builds can still restart when their completed source receipt matches.

An established volume with a missing database, missing configuration or different release is refused.
Follow the [upgrade guidance](../../../docs/upgrades.md) before planning a release change.
`docker compose down` keeps the volume. `docker compose down -v` deletes it.
