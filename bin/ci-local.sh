#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Run the complete, service-free local gate without changing the host's PHP configuration (#316).
set -euo pipefail

if (($#)); then
  echo 'Usage: bin/ci-local.sh (no arguments; runs the complete SQLite gate)' >&2
  exit 2
fi

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo"

command -v php >/dev/null 2>&1 || { echo 'PHP 8.4 or newer must be on PATH.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' \
  || { echo 'PHP 8.4 or newer must be on PATH.' >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo 'Composer must be on PATH.' >&2; exit 1; }
[[ -f vendor/bin/pint ]] || { echo 'Run composer install before the local gate.' >&2; exit 1; }

# Test generation alone exceeds a default CLI's 128 MiB, and the complete suite outlasts Composer's
# default 300-second process timeout. These are development-process settings, not application defaults
# or a change to ADR-027's hosting floor. Each PHP subprocess reads the same isolated override.
#
# A short /tmp path is deliberate: macOS's default per-user temporary path can leave too little room
# for the suite's Unix-domain socket names. No existing TMPDIR or system INI file is edited or removed.
umask 077
work=$(mktemp -d /tmp/kci-XXXXXXXX)
work=$(cd "$work" && pwd -P)
trap 'rm -rf "$work"' EXIT
mkdir "$work/ini"
cat > "$work/ini/local-tests.ini" <<INI
memory_limit=512M
sys_temp_dir="$work"
INI

if [[ ${PHP_INI_SCAN_DIR+x} ]]; then
  # An explicitly empty scan directory disables the system scan. Preserve that choice; an empty
  # component would re-enable it. A nonempty value may itself contain deliberate empty components.
  if [[ -n "$PHP_INI_SCAN_DIR" ]]; then
    export PHP_INI_SCAN_DIR="$PHP_INI_SCAN_DIR:$work/ini"
  else
    export PHP_INI_SCAN_DIR="$work/ini"
  fi
else
  export PHP_INI_SCAN_DIR=":$work/ini"
fi

export TMPDIR="$work"
export DB_CONNECTION=testing
export COMPOSER_PROCESS_TIMEOUT=0

composer ci
vendor/bin/pint --test skeleton --config skeleton/pint.json
