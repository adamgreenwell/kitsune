#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Deploy one commit to stage, the way Forge's zero-downtime macros deploy alpha: $CREATE_RELEASE() clones the code into
# a new release directory, the release body runs there, and $ACTIVATE_RELEASE() points `current` at it (issue #111).
# The release body is the deployed commit's own deploy/release.sh, the same file alpha's deploy script runs.
#
# ⚠️ ONE DIFFERENCE: this checks out exactly DEPLOY_SHA, while $CREATE_RELEASE() clones the branch tip (inferred from
# Forge's docs, which do not say which commit it checks out). So alpha can deploy the sha stage rehearsed only while
# that sha is still the tip of alpha's branch (see release.sh's header).
#
# Every input arrives in the environment:
#
#   DEPLOY_SHA         the full 40-character commit to deploy (required); alpha's deploy hook later names the same one
#   SITE_ROOT          the site's root (default /home/forge/stage.kitsunecms.org)
#   REPO_URL           where to clone from (default https://github.com/adamgreenwell/kitsune.git; the repo is public)
#   PHP_BIN            the PHP binary release.sh runs (default php8.5)
#   COMPOSER_BIN       Composer's file (default /usr/bin/composer)
#   PHP_FPM_SERVICE    the service reloaded when PHP_FPM_RELOAD=1 (default php8.5-fpm)
#   PHP_FPM_RELOAD     1 to reload PHP-FPM after activating, 0 not to (default 0: Forge documents it as unnecessary)
#   KEEP_RELEASES      how many releases to keep, the active one included (default 4, Forge's default; at least 2)
#   DEPLOY_TIME_LIMIT  seconds a deploy may take before this reports that alpha would have failed it (default 600)
#
# ⚠️ WHAT IT DOES, in the order it does it:
#
#   1. Refuses a bad input before it reaches git, sudo or rm: a DEPLOY_SHA that is not a full hash, a whole number
#      with a leading zero (bash arithmetic reads 08 as octal and fails), root, or a SITE_ROOT this user does not own.
#   2. Takes the deploy lock, SITE_ROOT/.deploy-lock. mkdir is atomic, and flock is not on every machine this is tested
#      on. A refused run never removes a lock it did not take, and a stale lock is left for the operator.
#   3. Clones the repository into releases/<UTC timestamp> and checks out exactly DEPLOY_SHA, detached.
#   4. Runs that commit's deploy/release.sh from the new release, with DEPLOY_SHA, while `current` still points at the
#      previous release. ⚠️ release.sh migrates the shared database before it exits 0.
#   5. Activates with one rename(2) of a new link over `current`, through perl, so `current` always resolves to the old
#      release or the new one. Stage's default mv is uutils and BSD mv has no -T, while perl-base is Essential on
#      Ubuntu, so stage and the tests run identical code.
#   6. Reloads PHP-FPM only when PHP_FPM_RELOAD=1, through the one sudo right stage has. Forge documents a reload as
#      unnecessary for zero-downtime deployments, and a reload here would hide a stale-path problem alpha would show.
#   7. Removes old releases, keeping the newest KEEP_RELEASES by name and always the active one.
#   8. Reports a run longer than DEPLOY_TIME_LIMIT, after activating it. By then the migration has run, so refusing to
#      activate a proven release would leave the new schema under the old code.
#
# A run that fails before activation removes its release directory and leaves `current` untouched.
#
# HOW TO RUN IT, as forge, with the runner inside the release that is live:
#
#   DEPLOY_SHA=<40-character sha> bash /home/forge/stage.kitsunecms.org/current/deploy/stage-deploy.sh
#
# The runner changes exactly when an activation succeeds, because it is always the copy inside `current`. Bash has
# already opened the file it is running, and KEEP_RELEASES is at least 2, so its own swap and pruning do not disturb it.
#
# THE FIRST DEPLOY has no `current`, so it runs from a temporary checkout of the same sha, and explicitly as forge,
# because a root run is refused:
#
#   sudo -u forge -H git clone https://github.com/adamgreenwell/kitsune.git /tmp/kitsune-first
#   sudo -u forge -H git -C /tmp/kitsune-first checkout --detach <sha>
#   sudo -u forge -H env DEPLOY_SHA=<sha> bash /tmp/kitsune-first/deploy/stage-deploy.sh
#
# ⚠️ AFTER AN .env EDIT: redeploy the same sha. The live release serves the config it cached when it was built, so an
# edit changes nothing until then, and release.sh's checks then judge the new values.
#
# MANUAL ROLLBACK, only when no .deploy-lock exists:
#
#   cd /home/forge/stage.kitsunecms.org && rm -f current.tmp && ln -s "$PWD/releases/<old>" current.tmp \
#     && perl -e 'rename($ARGV[0], $ARGV[1]) or die "rename: $!\n"' current.tmp current
#
# ⚠️ A rollback does not revert migrations, and the old release serves the config it cached from the .env as it was at
# its own deploy.
set -euo pipefail

refuse() {
  echo "Refusing to deploy: $*" >&2
  exit 1
}

[[ -n "${DEPLOY_SHA:-}" ]] || refuse "DEPLOY_SHA is required"

SITE_ROOT=${SITE_ROOT:-/home/forge/stage.kitsunecms.org}
REPO_URL=${REPO_URL:-https://github.com/adamgreenwell/kitsune.git}
PHP_BIN=${PHP_BIN:-php8.5}
COMPOSER_BIN=${COMPOSER_BIN:-/usr/bin/composer}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-php8.5-fpm}
PHP_FPM_RELOAD=${PHP_FPM_RELOAD:-0}
KEEP_RELEASES=${KEEP_RELEASES:-4}
DEPLOY_TIME_LIMIT=${DEPLOY_TIME_LIMIT:-600}

# Held in variables and used unquoted, which is how [[ =~ ]] reads a pattern the same way on bash 3.2 and 5.
sha_re='^[0-9a-f]{40}$'
fpm_re='^php[0-9]+\.[0-9]+-fpm$'
positive_re='^[1-9][0-9]*$'
whole_re='^(0|[1-9][0-9]*)$'
stamp_re='^[0-9]{14}$'

locked=0
created=0

# 1. Refuse before anything is written.
[[ $DEPLOY_SHA =~ $sha_re ]] || refuse "DEPLOY_SHA must be a full 40-character lowercase commit hash"
[[ $PHP_FPM_SERVICE =~ $fpm_re ]] || refuse "PHP_FPM_SERVICE must look like php8.5-fpm"
[[ "$PHP_FPM_RELOAD" == 0 || "$PHP_FPM_RELOAD" == 1 ]] || refuse "PHP_FPM_RELOAD must be 0 or 1"
[[ $KEEP_RELEASES =~ $positive_re ]] && (( KEEP_RELEASES >= 2 )) \
  || refuse "KEEP_RELEASES must be a whole number of at least 2, without leading zeros"
[[ $DEPLOY_TIME_LIMIT =~ $whole_re ]] || refuse "DEPLOY_TIME_LIMIT must be whole seconds, without leading zeros"
[[ "$(id -u)" != 0 ]] || refuse "never run as root; run as the user PHP-FPM runs as (forge)"
[[ "$SITE_ROOT" == /* && -d "$SITE_ROOT" ]] || refuse "SITE_ROOT must be an absolute, existing directory"
[[ -O "$SITE_ROOT" ]] || refuse "run as the user that owns $SITE_ROOT (PHP-FPM's user)"
[[ ! -e "$SITE_ROOT/current" || -L "$SITE_ROOT/current" ]] || refuse "$SITE_ROOT/current exists and is not a symlink"

# Removes what a failed run created, and only that, then keeps the exit status the run ended with.
#
# ⚠️ WHETHER THE RELEASE IS LIVE IS READ FROM current, NEVER FROM A FLAG. Bash runs a trap only after the command it
# interrupted returns, so a TERM that arrives while perl renames runs this before any line after the rename: a flag set
# there would still say "not activated", and the release current already names would be removed.
cleanup() {
  local status=$?

  if (( created == 1 )) && [[ "$(readlink "$SITE_ROOT/current" 2>/dev/null || true)" != "${release:-}" ]]; then
    if [[ "${release:-}" == "$SITE_ROOT/releases/"?* ]]; then
      rm -rf "$release"
    fi

    rm -f "$SITE_ROOT/current.tmp"
    echo "The deploy of $DEPLOY_SHA did not activate. Its release was removed, and current is unchanged." >&2
  elif (( created == 1 && (status == 130 || status == 143) )); then
    echo "The deploy of $DEPLOY_SHA was interrupted after activating: releases/${release##*/} IS ACTIVE." >&2
  fi

  if (( locked == 1 )); then
    rm -rf "$SITE_ROOT/.deploy-lock"
  fi

  exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# 2. One deploy at a time.
mkdir "$SITE_ROOT/.deploy-lock" 2>/dev/null \
  || refuse "another deploy holds $SITE_ROOT/.deploy-lock ($(cat "$SITE_ROOT/.deploy-lock/owner" 2>/dev/null || true)). If none is running, remove that directory."
locked=1
printf '%s %s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$SITE_ROOT/.deploy-lock/owner"

# Forge's clock starts before $CREATE_RELEASE(), so this one starts before the clone.
SECONDS=0

# 3. $CREATE_RELEASE(). A UTC timestamp sorts in creation order. created is set before the clone, so a half-finished
# clone, or a sha the repository does not have, is removed too.
mkdir -p "$SITE_ROOT/releases"
name=$(date -u +%Y%m%d%H%M%S)
release="$SITE_ROOT/releases/$name"
[[ ! -e "$release" ]] || refuse "a release named $name exists"
created=1
git clone --quiet --no-checkout "$REPO_URL" "$release"
git -C "$release" checkout --quiet --detach "$DEPLOY_SHA"
[[ "$(git -C "$release" rev-parse HEAD)" == "$DEPLOY_SHA" ]] || refuse "checked out a different commit from $DEPLOY_SHA"

# 4. The release body, from the root of the new release, as Forge runs it after `cd $FORGE_RELEASE_DIRECTORY`.
(
  cd "$release"
  DEPLOY_SHA="$DEPLOY_SHA" SITE_ROOT="$SITE_ROOT" PHP_BIN="$PHP_BIN" COMPOSER_BIN="$COMPOSER_BIN" bash deploy/release.sh
)

# 5. $ACTIVATE_RELEASE(). A stale current.tmp goes first, because `ln -s` onto a link to a directory would put the new
# link inside it. From the rename on, current names this release, and that is what keeps the cleanup away from it.
rm -f "$SITE_ROOT/current.tmp"
ln -s "$release" "$SITE_ROOT/current.tmp"
perl -e 'rename($ARGV[0], $ARGV[1]) or die "rename: $!\n"' "$SITE_ROOT/current.tmp" "$SITE_ROOT/current"
echo "releases/$name is active."

# 6. Spelled exactly as the sudoers rule is; -n fails at once instead of waiting for a password.
if [[ "$PHP_FPM_RELOAD" == 1 ]]; then
  sudo -n /usr/sbin/service "$PHP_FPM_SERVICE" reload || {
    echo "The release $name IS ACTIVE, but reloading $PHP_FPM_SERVICE failed. Check sudoers and the service before the next deploy." >&2
    exit 1
  }
fi

# 7. Retention, newest first by name. Anything that is not a 14-digit release directory is left alone, and the active
# release is kept even when a clock that went backwards gave it an older name.
LC_COLLATE=C
shopt -s nullglob
releases=("$SITE_ROOT"/releases/*)
kept=0

for (( i = ${#releases[@]} - 1; i >= 0; i-- )); do
  dir=${releases[i]}
  base=${dir##*/}

  [[ -d "$dir" && ! -L "$dir" && $base =~ $stamp_re ]] || continue
  kept=$((kept + 1))

  if (( kept > KEEP_RELEASES )) && [[ "$base" != "$name" ]]; then
    rm -rf "$dir"
  fi
done

# 8. Stage has no time limit and alpha does, so a slow run is surfaced here first.
echo "Deployed $DEPLOY_SHA as releases/$name in ${SECONDS}s."

if (( SECONDS > DEPLOY_TIME_LIMIT )); then
  echo "releases/$name IS ACTIVE, but the run took ${SECONDS}s. Forge fails a deployment that takes longer than ${DEPLOY_TIME_LIMIT}s, so alpha would not have activated it." >&2
  exit 1
fi
