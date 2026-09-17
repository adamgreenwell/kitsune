#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Decide what one CI run actually needs, once, for every matrix in .github/workflows/ci.yml to consume.
#
# Inputs, all from the environment so nothing a branch is named can become shell:
#
#   EVENT     the event that started the run: `pull_request`, or anything else
#   REPO      owner/name, for the file listing
#   NUMBER    the pull request's number
#   GH_TOKEN  read by `gh`; never read here
#   FILES_CMD the command that lists a pull request's changed files, one per line. Defaults to `gh`;
#             the tests hand it a stub, because a test that reaches the network tests the network.
#
# Writes `docs_only`, `php` and `engine` to $GITHUB_OUTPUT (or stdout when unset, which is how the
# tests read it).
#
# ⚠️ WHY THIS EXISTS. A run is 13 jobs. Wall clock is 3-7 minutes, but GitHub bills per job, so a run
# costs 32-47 billable minutes and a merged pull request costs two of them — once on the PR, once on
# main. Measured across runs 35217412342, 35229064030, 35234152550 and 35237984311. Two things were
# being paid for and not used: a change touching only prose ran all four engines on both PHP versions,
# and merging re-proved on main the tree the pull request had just proved.
#
# ⚠️ AND IT FAILS CLOSED, which is the only part worth arguing about. Anything this cannot answer — an
# API that will not list the files, an empty list, an event that is not a pull request — is treated as
# "everything runs". What this narrowing risks is a defect reaching main unmeasured; what it protects
# is a bill. Those are not the same size, so the doubt goes to the matrix.
#
# ⚠️ AND PROSE THIS REPOSITORY TESTS IS NOT PROSE. `DerivedWikiPagesTest` reads docs/ off disk and the
# runbook's manifest tests read deploy/runbook/README.md and manifest.txt, so a docs-only change can
# genuinely break the suite. The narrow path therefore still runs lint and a full SQLite lane; what it
# drops is the other seven engine lanes, the bare-clone lanes and the browser lane, none of which a
# markdown file can reach.
set -euo pipefail

: "${EVENT:?EVENT is required}"

FULL_PHP='["8.4","8.5"]'
FULL_ENGINE='["sqlite","pgsql","mysql","mariadb"]'
NARROW_PHP='["8.4"]'
NARROW_ENGINE='["sqlite"]'

emit() {
  local out="${GITHUB_OUTPUT:-/dev/stdout}"
  printf 'docs_only=%s\n' "$1" >> "$out"
  printf 'php=%s\n' "$2" >> "$out"
  printf 'engine=%s\n' "$3" >> "$out"
}

everything() {
  echo "$1, so every lane runs" >&2
  emit false "$FULL_PHP" "$FULL_ENGINE"
  exit 0
}

# A push to main. The pull request that produced this tree already ran the full matrix on the same
# content, so this run is the merge check rather than the proof: lint, one engine, one bare clone and
# the browser lane. What it still catches is a semantic conflict between two pull requests merged close
# together; what it does not catch, the next pull request's full matrix does.
if [[ "$EVENT" != pull_request ]]; then
  echo "this is a [$EVENT] rather than a pull request, so this run is the merge check" >&2
  emit false "$NARROW_PHP" "$NARROW_ENGINE"
  exit 0
fi

[[ -n "${REPO:-}" && -n "${NUMBER:-}" ]] || everything "the pull request could not be identified"

files_cmd=${FILES_CMD:-gh api "repos/$REPO/pulls/$NUMBER/files" --paginate --jq .[].filename}

# ⚠️ NOT `files=$(...)` UNDER `set -e` WITHOUT THE GUARD. A failing lister must reach the fail-closed
# branch below rather than end this script, because ending it takes the whole run's decision with it.
if ! files=$(eval "$files_cmd" 2>/dev/null); then
  everything "the pull request's files could not be listed"
fi

[[ -n "$files" ]] || everything "the pull request listed no files"

docs_only=true

while IFS= read -r file; do
  [[ -n "$file" ]] || continue

  case "$file" in
    docs/*|*.md) ;;
    *) docs_only=false ;;
  esac
done <<< "$files"

echo "changed files:" >&2
echo "$files" >&2

if [[ "$docs_only" == true ]]; then
  echo "every changed file is prose, so the engine matrix narrows to one lane" >&2
  emit true "$NARROW_PHP" "$NARROW_ENGINE"
else
  emit false "$FULL_PHP" "$FULL_ENGINE"
fi
