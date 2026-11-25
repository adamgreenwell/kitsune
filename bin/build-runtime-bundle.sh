#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Build the self-host application artifact on a Linux release builder.
set -euo pipefail
export LC_ALL=C TZ=UTC
umask 022

refuse() {
  printf 'Runtime bundle refused: %s\n' "$*" >&2
  exit 1
}

package_plan=
if (( $# > 3 )) && [[ ${!#} == --package-plan=* ]]; then
  package_plan=${!#}
  package_plan=${package_plan#--package-plan=}
  [[ -n "$package_plan" ]] || refuse 'the package plan path is empty'
  package_plan=$(realpath -- "$package_plan") || refuse 'the package plan is unavailable'
  [[ -f "$package_plan" ]] || refuse 'the package plan is unavailable'
  set -- "${@:1:$#-1}"
fi
if (( $# < 3 || $# > 4 )); then
  refuse 'usage: bin/build-runtime-bundle.sh SOURCE EXACT_COMMIT OUTPUT.tar.gz [COMPOSER_LOCK] [--package-plan=RELEASE_PLAN_JSON]'
fi

builder_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
source_root=$1
commit=$2
output=$3
lock=${4:-}

[[ "$commit" =~ ^[0-9a-f]{40}$ ]] || refuse 'an exact Git commit is required'
[[ "$(git --no-replace-objects -C "$source_root" cat-file -t "$commit")" == commit ]] \
  || refuse 'the requested Git commit is unavailable'
[[ "$output" == *.tar.gz && ! -e "$output" && ! -L "$output" ]] || refuse 'use a new .tar.gz output path'
if [[ -n "$lock" ]]; then
  [[ -f "$lock" ]] || refuse 'the supplied Composer lock is missing'
fi
tar_version=$(tar --version)
[[ "$tar_version" == 'tar (GNU tar)'* ]] || refuse 'GNU tar is required on the build host'
php -r 'exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 90000 ? 0 : 1);' || refuse 'PHP ^8.4 is required on the build host'

work=$(mktemp -d "$(dirname -- "$output")/.kitsune-runtime.XXXXXXXX")
work=$(cd "$work" && pwd -P)
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/source"
# Read the reviewed objects from a private Git directory. Archive honors local
# info/attributes, which must not be able to add or remove files from the bundle.
objects=$(git --no-replace-objects -C "$source_root" rev-parse --path-format=absolute --git-path objects)
[[ "$objects" != *$'\n'* ]] || refuse 'invalid Git object directory'
GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
  git init --quiet --bare --template= --object-format=sha1 "$work/repository"
printf '%s\n' "$objects" > "$work/repository/objects/info/alternates"
GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_ATTR_NOSYSTEM=1 \
  git --no-replace-objects -C "$work/repository" -c core.attributesFile=/dev/null \
    archive "$commit" skeleton packages | tar -xf - -C "$work/source"

app="$work/source/skeleton"
if [[ -n "$lock" ]]; then
  cp -- "$lock" "$app/composer.lock"
fi

# Keep Composer's global config and plugins out of the dependency closure.
export COMPOSER_HOME="$work/composer-home"
unset COMPOSER COMPOSER_VENDOR_DIR COMPOSER_IGNORE_PLATFORM_REQ COMPOSER_IGNORE_PLATFORM_REQS
cd "$app"
composer --no-plugins config platform.php 8.4.0
composer --no-plugins config platform-check true
# Composer otherwise assigns the same dev-main version to snapshots from different commits.
path_repository=$(php -r '
    require $argv[3]."/release/Manifest.php";
    $plan = $argv[2] === "" ? null : json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    if ($argv[2] !== "" && !is_array($plan["packages"] ?? null)) {
        fwrite(STDERR, "The release plan must include package versions.\n");
        exit(1);
    }
    $versions = [];
    foreach (glob("../packages/*/composer.json", GLOB_ERR) as $manifest) {
        $package = json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        $name = $package["name"];
        $version = $plan === null ? "dev-".$argv[1] : ($plan["packages"][$name]["version"] ?? null);
        if (!is_string($version) || ($version !== "dev-".$argv[1] && !Kitsune\Release\Manifest::validVersion("v".$version))) {
            fwrite(STDERR, "No valid release version was supplied for ".$name.".\n");
            exit(1);
        }
        $versions[$name] = $version;
    }
    echo json_encode(["type" => "path", "url" => "../packages/*",
        "options" => ["symlink" => false, "versions" => $versions]], JSON_THROW_ON_ERROR);
' "$commit" "$package_plan" "$builder_root")
composer --no-plugins config repositories.kitsune-packages "$path_repository"
if [[ -z "$lock" ]]; then
  composer --no-plugins update --no-install --no-dev --no-scripts --no-interaction --prefer-dist
fi
composer --no-plugins validate --no-check-publish --check-lock
lock_digest=$(sha256sum composer.lock)
lock_digest=${lock_digest%% *}

# A runtime lock must resolve Kitsune packages from this commit, even on replay.
php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
    $versions = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR)["options"]["versions"];
    if (($lock["platform-overrides"]["php"] ?? null) !== "8.4.0") {
        fwrite(STDERR, "Runtime lock does not target PHP 8.4.0.\n");
        exit(1);
    }
    foreach ($lock["packages"] as $package) {
        $type = $package["dist"]["type"] ?? null;
        if (!str_starts_with($package["name"], "kitsune/")) {
            if ($type === "path") {
                fwrite(STDERR, "Runtime lock contains an external path package.\n");
                exit(1);
            }
            continue;
        }
        $name = substr($package["name"], strlen("kitsune/"));
        $path = "../packages/".$name;
        if (!preg_match("/\A[a-z0-9-]+\z/", $name)
            || $type !== "path"
            || ($package["dist"]["url"] ?? null) !== $path
            || ($package["version"] ?? null) !== ($versions[$package["name"]] ?? null)
            || !is_file($path."/composer.json")) {
            fwrite(STDERR, "Runtime lock contains a Kitsune package outside the source or with an unexpected version.\n");
            exit(1);
        }
        echo $name, "\n";
    }
' "$path_repository" > "$work/packages"
[[ -s "$work/packages" ]] || refuse 'the runtime lock contains no Kitsune packages'
composer --no-plugins install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
[[ "$(sha256sum composer.lock)" == "$lock_digest  composer.lock" ]] || refuse 'Composer changed the runtime lock'
while IFS= read -r name; do
  [[ -d "vendor/kitsune/$name" && ! -L "vendor/kitsune/$name" ]] || refuse "Kitsune package is not a local copy: $name"
  diff -qr "../packages/$name" "vendor/kitsune/$name" >/dev/null || refuse "Kitsune package differs from the source commit: $name"
done < "$work/packages"

APP_ENV=production LOG_CHANNEL=stderr php artisan package:discover --no-interaction
APP_ENV=production LOG_CHANNEL=stderr php artisan filament:assets --no-interaction
[[ ! -e .env && ! -e database/database.sqlite ]] || refuse 'the build created runtime state'
[[ -z "$(find . ! -type f ! -type d -print -quit)" ]] || refuse 'the app contains a non-regular archive entry'

# Archive ordering, timestamps and ownership must be stable across fresh builds.
tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner \
  --exclude='./storage/logs/*.log' -cf - . | gzip -n > "$work/application.tar.gz"

cd - >/dev/null
ln -T -- "$work/application.tar.gz" "$output" || refuse 'could not publish the archive at the requested path'
printf 'source_commit=%s\ncomposer_lock_sha256=%s\n' "$commit" "$lock_digest"
sha256sum -- "$output"
