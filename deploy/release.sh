#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Build one release of the skeleton, in place, from the root of a fresh checkout, before that release is activated.
# Stage runs it from deploy/stage-deploy.sh and alpha runs it from Forge's deploy script, unchanged, so what stage
# rehearses is what alpha runs (issue #111). Activation is not its job: it ends by saying the release is ready.
#
# Inputs arrive in the environment. The first four are required, so nothing guesses which server it is on:
#
#   DEPLOY_SHA    the full 40-character commit being released; the checkout must be exactly that commit
#   SITE_ROOT     the site's root: the shared .env and storage/ live there, and this release is under releases/
#   PHP_BIN       the PHP binary, e.g. php8.5 on stage and $FORGE_PHP on Forge
#   COMPOSER_BIN  Composer's file, always run as "$PHP_BIN" "$COMPOSER_BIN" so it resolves on that PHP
#   KITSUNE_INSTALL_COMMERCE  optional: 1 adds the package to this release; 0 (the default) leaves it absent.
#                           Installing never installs or enables the module receipt. Keep the choice in every deploy.
#
# ⚠️ WHAT IT DOES, in the order it does it:
#
#   1. Refuses before writing anything: a missing input, LARAVEL_CLOUD in any form, root, a site this user does not
#      own, a directory that is not a fresh release under SITE_ROOT/releases, a checkout that is not DEPLOY_SHA or
#      whose tracked files differ from it, a skeleton/.env.<name> Laravel would load instead of .env, and a missing
#      shared .env. A public deploy never creates an .env or generates a key (ADR-026).
#   2. Prints the PHP and Composer versions, so stage's deployment log and alpha's can be compared.
#   3. Refuses a PHP without the extensions nothing declares: dom (core builds DOMDocuments) and pdo_pgsql.
#   4. Links skeleton/.env and skeleton/storage to the shared ones. The app's base path is skeleton/, so the release
#      root's own paths, which Forge's shared paths link, are never read.
#   5. Resolves dependencies fresh. No composer.lock is restored or saved outside the release.
#   6. Points the skeleton at this checkout's packages/*, mirrored rather than symlinked. NOT because the packages are
#      unpublished — core is, since #8 — but because a release pinned to DEPLOY_SHA must install THAT commit's code
#      rather than whatever Packagist resolves at deploy time (ADR-035, amended 2026-09-17). A glob rather than
#      packages/core so a first-party MODULE resolves the same way, on the same reasoning (ADR-038).
#   7. Installs runtime dependencies with --no-scripts, then proves the first-party sources were copied and an
#      opted-in commerce package has the same Composer version as core. ⚠️ Composer's scripts would run
#      package:discover, which boots Laravel before anything has proved the .env parses, and Laravel prints
#      phpdotenv's message for a malformed line to stderr. That message quotes the value: a password, into the log.
#   8. Audits the installed runtime packages for security advisories.
#   9. Parses the shared .env with phpdotenv's own parser, and on failure reports only the line, never the message.
#  10. Boots the app and refuses an unsafe environment, by what Laravel loads rather than by the text of .env. ⚠️ This is
#      the first boot, and the only one that withholds an exception's message: a provider's can quote configuration.
#  11. Runs package:discover itself, which is what Composer's scripts would have done.
#  12. Publishes Filament's assets into this release's public/.
#  13. Links public/storage, and proves the link resolves: storage:link exits 0 when it did nothing.
#  14. Builds config, event, route, view and icon caches, one command at a time, so each failure stops the release.
#      Compiled views go to this release's own skeleton/bootstrap/cache/views, never the shared storage.
#  15. Proves the release serves before the schema changes: the caches exist, no provider registers an optimize task
#      this script does not know, the environment still holds against the cached config, and GET /up through the
#      HTTP kernel answers 200.
#  16. Migrates, then rebuilds the caches and repeats the serving gate against the migrated schema. A newly added
#      module-admission guard table may have refused module boot during the pre-migration cache build (ADR-038).
#  17. Runs kitsune:audit-patterns --strict, Kitsune's deploy gate.
#  18. Runs kitsune:schema-sync as a report. Without --force it never changes anything.
#
# ⚠️ NEVER: --seed or db:seed, because the skeleton's DatabaseSeeder creates accounts whose password is `password`,
# and ADR-026 forbids a default administrator; migrate:fresh, which drops every table; key:generate, because a new key
# invalidates every session and everything encrypted with the old one — since ADR-040, every org's stored credentials
# too, so APP_KEY is backup-critical and rotates through APP_PREVIOUS_KEYS (ADR-040, the credential store as built);
# `optimize` or `filament:optimize`, which exit 0 when one of their tasks fails; kitsune:benchmark-*, which are
# measurement spikes, not deploy steps; npm, because the committed assets are what serves; kitsune:blueprint apply
# --owner, because a first owner is a one-off operator command on an empty installation (ADR-026, as amended), and a
# release that ran it would need the password in its input.
#
# ⚠️ NO --isolated ON migrate. Its mutex locks on the default cache store, which is `database` when CACHE_STORE is
# unset, and that table does not exist before the first migration. Stage serialises its deploys with its own lock.
#
# ⚠️ A SITE IN MAINTENANCE MODE STAYS DOWN. The marker is in the shared storage, so the new release activates down too,
# which is what fixing forward needs. /up is exempt from maintenance mode, so the serving check still proves the
# release.
#
# HOW FORGE RUNS THIS. The site's deploy script, reviewed here rather than only in Forge's UI:
#
#   $CREATE_RELEASE()
#
#   cd $FORGE_RELEASE_DIRECTORY
#
#   # The release body, the same file stage runs. DEPLOY_SHA comes from the deployment hook's `&sha=` parameter, which
#   # Forge injects as FORGE_VAR_SHA. release.sh refuses a checkout that is not that commit, so "Deploy Now" and push
#   # to deploy, which carry no sha, deploy nothing. `|| exit 1` because Forge does not document running with errexit.
#   DEPLOY_SHA="${FORGE_VAR_SHA:-}" SITE_ROOT="$FORGE_SITE_ROOT" PHP_BIN="$FORGE_PHP" COMPOSER_BIN="$FORGE_COMPOSER" bash deploy/release.sh || exit 1
#
#   $ACTIVATE_RELEASE()
#
#   # Kitsune runs no queue workers, so this restarts nothing. Kept because Forge's docs say to include all three macros.
#   $RESTART_QUEUES()
#
#   # No PHP-FPM reload: Forge documents it as unnecessary for zero-downtime deployments.
#
# The site settings that script relies on, set by the operator in Forge's UI:
#
#   - Zero-downtime deployments ON, which can only be chosen when the site is created. PHP 8.5. Web directory
#     /skeleton/public. The repository root is the repository's root.
#   - Push to deploy OFF. It is on by default for new sites, and would deploy the branch tip on every push, skipping
#     stage.
#   - Deploy only through the deployment hook URL, adding &sha=<the 40-character commit stage deployed> and
#     &forge_deploy_commit=<the same>. forge_deploy_commit is only the label in Forge's history; the sha parameter is
#     what this script checks. Keep the hook URL private: requesting it triggers a deployment.
#     ⚠️ $CREATE_RELEASE() clones the branch tip (inferred: Forge's docs do not say which commit it checks out), and the
#     sha only names the commit this script insists on. So alpha can deploy a sha only while it is still the tip of the
#     site's branch: promote to alpha before anything else merges, or re-stage the new tip. Unverified until the first
#     alpha deploy, whose log should show it: which commit $CREATE_RELEASE() checked out, that the release is under
#     $FORGE_SITE_ROOT/releases/, that it keeps .git (the pin needs it), and that the site root and .env belong to the
#     deploying user.
#   - "Install Composer dependencies" UNTICKED. The app is in skeleton/, and this script installs it. The repository
#     root's composer.lock is the monorepo's development tooling, and installing it would spend Forge's 10-minute
#     limit on files nothing serves.
#   - Forge's Laravel panel reads that root composer.lock. Leave its scheduler and maintenance toggles off: artisan is
#     at skeleton/artisan, not at the release root.
#   - "Make .env variables available to deployment script" UNTICKED. This script reads .env through Laravel, and
#     injecting it would put a second copy of every secret into the deploy process's environment.
#   - No shared paths besides the default .env. This script links skeleton/.env and skeleton/storage itself, and
#     refuses a checkout whose committed files a shared path replaced.
#   - The .env is written through the environment editor at $FORGE_SITE_ROOT/.env, owned by the deploying user, with
#     APP_ENV=production, APP_DEBUG=false, an https APP_URL, SESSION_SECURE_COOKIE=true, DB_CONNECTION=pgsql and a
#     real APP_KEY. Never LARAVEL_CLOUD.
#
# ⚠️ AFTER AN .env EDIT, on either server: redeploy the same sha. Each release serves the config it cached when it was
# built, so an edit changes nothing until then, and this script's checks then judge the new values. In Forge's
# environment editor, leave the "config:cache" and "queue:restart" options unticked.
set -euo pipefail

refuse() {
  echo "Refusing to release: $*" >&2
  exit 1
}

sha_re='^[0-9a-f]{40}$'

# Run one of the checks below through the application itself. The PHP arrives on stdin, so its first argument is the
# mode. Refusals are collected and printed one per line, and none of them quotes a value that could be a secret.
check_the_app() {
  # A saved source file avoids a measured Bash 5.3/macOS heredoc pipe hang above a few KiB. PHP still reads
  # the check on stdin, so nothing changes about its arguments, application cwd or pre-boot redaction boundary.
  "$PHP_BIN" -- "$1" "$install_commerce" <"$(dirname "${BASH_SOURCE[0]}")/release-check.php"
}

# 1. Refuse before anything is written.
for input in DEPLOY_SHA SITE_ROOT PHP_BIN COMPOSER_BIN; do
  [[ -n "${!input:-}" ]] || refuse "$input is required"
done

install_commerce=${KITSUNE_INSTALL_COMMERCE-0}
[[ "$install_commerce" == 0 || "$install_commerce" == 1 ]] \
  || refuse "KITSUNE_INSTALL_COMMERCE must be 0 or 1"

[[ -z "${LARAVEL_CLOUD+set}" ]] || refuse "LARAVEL_CLOUD is set in the deploy environment, and it must not be, in any form"
[[ "$(id -u)" != 0 ]] || refuse "never run as root: what it creates in the shared storage would be unwritable by PHP-FPM"
[[ "$SITE_ROOT" == /* && -d "$SITE_ROOT" ]] || refuse "SITE_ROOT must be an absolute, existing directory"

site_root=$(cd "$SITE_ROOT" && pwd -P)
[[ -O "$site_root" ]] || refuse "run as the user that owns $site_root (PHP-FPM's user)"

release=$(pwd -P)
[[ "$release" == "$site_root/releases/"?* ]] || refuse "run from a release directory under $site_root/releases, not $release"
[[ -f skeleton/artisan && -f skeleton/composer.json && -f packages/core/composer.json ]] \
  || refuse "$release is not the root of a Kitsune checkout"

if [[ "$install_commerce" == 1 ]]; then
  [[ -f packages/commerce/composer.json ]] || refuse "this checkout contains no kitsune/commerce package"
fi

[[ "$DEPLOY_SHA" =~ $sha_re ]] || refuse "DEPLOY_SHA must be a full 40-character lowercase commit hash"
head=$(git rev-parse HEAD 2>/dev/null || true)
[[ "$head" == "$DEPLOY_SHA" ]] || refuse "this checkout is not $DEPLOY_SHA"
git diff --quiet HEAD -- || refuse "tracked files differ from $DEPLOY_SHA (a shared path replacing a committed one?)"

[[ ! -e skeleton/vendor ]] || refuse "skeleton/vendor exists, so this is not a fresh checkout"
[[ ! -e skeleton/.env || -L skeleton/.env ]] || refuse "skeleton/.env is a real file (a development checkout?)"

for file in skeleton/.env.*; do
  [[ "$file" == skeleton/.env.example || ! -e "$file" ]] \
    || refuse "$file exists, and Laravel loads it instead of .env when APP_ENV is set in the environment"
done

command -v "$PHP_BIN" >/dev/null || refuse "PHP_BIN is not a command: $PHP_BIN"

# A path is taken as it is, because an installed Composer need not be executable when PHP runs it; a bare name is found.
if [[ "$COMPOSER_BIN" == */* ]]; then
  composer_bin=$COMPOSER_BIN
else
  composer_bin=$(command -v "$COMPOSER_BIN" || true)
fi

[[ -n "$composer_bin" && -f "$composer_bin" ]] || refuse "COMPOSER_BIN must name Composer's file, and $COMPOSER_BIN does not"

[[ -f "$site_root/.env" && -r "$site_root/.env" && -O "$site_root/.env" ]] \
  || refuse "the shared .env at $site_root/.env must exist and belong to this user: the operator writes it, and this script never creates one or generates a key"
[[ ! -e "$site_root/storage" || -O "$site_root/storage" ]] || refuse "$site_root/storage belongs to another user"

# 2. The versions, so a resolution difference between stage and alpha can be traced afterwards.
"$PHP_BIN" -r 'echo "PHP ", PHP_VERSION, " at ", PHP_BINARY, PHP_EOL;'
"$PHP_BIN" "$composer_bin" --version --no-interaction

# 3. Only the extensions nothing declares. Composer checks every ext-* a runtime package declares while it resolves.
# exit(1), because exit("a message") exits 0.
"$PHP_BIN" -r 'foreach (["dom", "pdo_pgsql"] as $e) { if (! extension_loaded($e)) { fwrite(STDERR, "Refusing to release: PHP is missing the $e extension.\n"); exit(1); } }'

# 4. The shared state. The committed skeleton/storage is a directory of placeholders, and `ln -s` onto an existing
# directory would put the link inside it, so it goes first. Storage is shared whole, except compiled views (step 14).
mkdir -p "$site_root/storage/app/private" "$site_root/storage/app/public" "$site_root/storage/framework/cache/data" \
  "$site_root/storage/framework/sessions" "$site_root/storage/framework/views" "$site_root/storage/logs"
rm -f skeleton/.env
ln -s "$site_root/.env" skeleton/.env
rm -rf skeleton/storage
ln -s "$site_root/storage" skeleton/storage
[[ -f skeleton/.env && -d skeleton/storage/framework/views ]] || refuse "the shared .env or storage did not link"

# 5. No lock to restore: the skeleton commits none, and a lock saved per server would freeze each at its first resolve.

# 6. kitsune/core from this checkout, as real files, the way a Packagist install will put them. The edit exists only in
# this release's working tree, after step 1 proved it clean. The package is on Packagist since #8; this stays a path
# repository so the release installs the core of the commit it is pinned to, which step 7 then proves.
"$PHP_BIN" "$composer_bin" config -d skeleton repositories.kitsune-packages '{"type":"path","url":"../packages/*","options":{"symlink":false}}'

# Commerce stays absent from the default skeleton. This changes only the fresh release's manifest after the clean check;
# the module remains uninstalled and disabled until the operator deliberately uses kitsune:module.
if [[ "$install_commerce" == 1 ]]; then
  "$PHP_BIN" "$composer_bin" require -d skeleton 'kitsune/commerce:@dev' --no-update --no-scripts --no-interaction
fi

# 7. Runtime dependencies, with Forge's documented flags plus --no-scripts (see the header).
"$PHP_BIN" "$composer_bin" install -d skeleton --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
[[ -f skeleton/vendor/kitsune/core/composer.json && ! -L skeleton/vendor/kitsune/core ]] \
  || refuse "kitsune/core was not installed as a copy of packages/core"
# ⚠️ AND EVERY OTHER FIRST-PARTY PACKAGE THE SKELETON PULLED IN, because the repository is now a glob over
# packages/* and a module resolved through it must be pinned to this commit exactly as core is. A symlink here
# would make the release read files outside itself, which step 6 exists to prevent.
for kitsune_package in skeleton/vendor/kitsune/*; do
  [[ -e "$kitsune_package" ]] || continue
  [[ ! -L "$kitsune_package" ]] || refuse "$kitsune_package was symlinked rather than copied into the release"
done

check_the_app packages

# 8. Known advisories block the release. --abandoned is explicit, so the policy does not depend on Composer's version.
"$PHP_BIN" "$composer_bin" audit -d skeleton --no-dev --abandoned=report

# 9. The .env parses, before anything boots Laravel.
check_the_app syntax

# 10. The environment, as the booted app loads it, before config:cache bakes it in.
#
# ⚠️ NOTHING BOOTS LARAVEL BEFORE THIS. Artisan prints an uncaught exception's message, and a provider that throws while
# booting can quote configuration in it, so an artisan command here would put that message into the deployment log. The
# boot builds the missing package manifest itself, so package providers boot here too, under the same redaction.
check_the_app environment

# 11. The package manifest, which Composer's post-autoload-dump would have built.
"$PHP_BIN" skeleton/artisan package:discover --no-interaction

# 12. The committed Filament assets can lag the freshly resolved Filament.
"$PHP_BIN" skeleton/artisan filament:assets --no-interaction

# 13. storage:link reports an existing link as an error and still exits 0, so the test after it is the gate.
"$PHP_BIN" skeleton/artisan storage:link --no-interaction
[[ -L skeleton/public/storage && -d skeleton/public/storage ]] || refuse "skeleton/public/storage does not resolve"

# 14. What `optimize` would run, one command at a time: its four core tasks, plus Blade Icons' registered one. Filament's
# task is skipped on purpose (docs/architecture.md), and step 15 refuses any task this list does not know.
#
# ⚠️ COMPILED VIEWS STAY IN THIS RELEASE. view:cache runs view:clear first, which empties the compiled-view directory,
# so in the shared storage it would delete the live release's compiled views while that release still serves them.
# config:cache bakes VIEW_COMPILED_PATH into this release's cached config, because a variable in the environment wins
# over .env, and the directory goes when the release is pruned.
mkdir -p skeleton/bootstrap/cache/views
export VIEW_COMPILED_PATH="$release/skeleton/bootstrap/cache/views"
"$PHP_BIN" skeleton/artisan config:cache --no-interaction
"$PHP_BIN" skeleton/artisan event:cache --no-interaction
"$PHP_BIN" skeleton/artisan route:cache --no-interaction
"$PHP_BIN" skeleton/artisan view:cache --no-interaction
"$PHP_BIN" skeleton/artisan icons:cache --no-interaction

# 15. The release serves, proven before the schema changes, so a refusal here leaves the live release untouched.
check_the_app serving

# 16. The schema, with exactly the cached config that will serve. --force because APP_ENV is production.
"$PHP_BIN" skeleton/artisan migrate --force --no-interaction

# The first cache build proves the release before a schema write. It cannot be the final cache when a new
# admission table is missing in the old schema: fail-closed module boot omitted its routes/listeners/config.
# Keep that preflight, then rebuild every explicit cache on the migrated schema before accepting the release.
"$PHP_BIN" skeleton/artisan route:clear --no-interaction
"$PHP_BIN" skeleton/artisan config:cache --no-interaction
"$PHP_BIN" skeleton/artisan event:cache --no-interaction
"$PHP_BIN" skeleton/artisan route:cache --no-interaction
"$PHP_BIN" skeleton/artisan view:cache --no-interaction
"$PHP_BIN" skeleton/artisan icons:cache --no-interaction
check_the_app serving

# Refuse promotion when retained credentials need an APP_KEY absent from APP_KEY / APP_PREVIOUS_KEYS.
# This counts every stored slot, including disabled modules, without exposing or decrypting any value.
"$PHP_BIN" skeleton/artisan kitsune:credentials status --strict --no-interaction

# 17. Kitsune's deploy gate. It reads field_storage, which does not exist before the first migration.
"$PHP_BIN" skeleton/artisan kitsune:audit-patterns --strict --no-interaction

# 18. A report. Applying it can rewrite and lock a large entries table, so --force stays an operator's decision.
"$PHP_BIN" skeleton/artisan kitsune:schema-sync --no-interaction

echo "Release $release ($DEPLOY_SHA) is ready to activate."
