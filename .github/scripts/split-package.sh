#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Publish one package of this monorepo to its read-only mirror, for one release tag.
#
# Every input arrives in the environment, so nothing a tag is named can become shell:
#
#   TAG          the release being published, e.g. v0.1.0
#   SOURCE_SHA   the monorepo commit that tag points at
#   PREFIX       the package's directory, e.g. packages/core
#   REMOTE       where the mirror lives: a URL carrying the token in CI, a path in the tests
#   MAIN_REF     this clone's view of the monorepo's main branch, e.g. origin/main
#   RETRY_DELAY  seconds between attempts to move the mirror's main (default 5; the tests use 0)
#
# ⚠️ WHAT IT DOES, in the order it does it:
#
#   1. Refuses a tag that is not a version. Git accepts `;`, `$`, `|` and backticks in ref names, and a name
#      is not a thing to run.
#   2. Splits the package with `git subtree split`, so the mirror carries the package's real history — the
#      same commits, authors and messages, which is what makes consecutive releases fast-forwards of each other.
#   3. Pushes the tag. Tags are never forced: a release that already exists with different content is refused.
#   4. Moves the mirror's main to the release only when the tag is on the monorepo's main and the mirror's main
#      does not already contain it — so a release cut from an older line, or published late, cannot drag main
#      backwards.
#   5. Pushes main without force, retrying when a concurrent release got there first. The retry re-reads the
#      mirror, so losing the race to a newer release ends in "already contains", not in an overwrite.
#
# See .github/workflows/split-packages.yml for why this is not a marketplace action any more.
set -euo pipefail

: "${TAG:?TAG is required}"
: "${SOURCE_SHA:?SOURCE_SHA is required}"
: "${PREFIX:?PREFIX is required}"
: "${REMOTE:?REMOTE is required}"
: "${MAIN_REF:?MAIN_REF is required}"
RETRY_DELAY="${RETRY_DELAY:-5}"

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  echo "Refusing to publish [$TAG]: a release tag is vX.Y.Z, optionally with a -prerelease suffix." >&2
  exit 1
fi

split=$(git subtree split --quiet --prefix="$PREFIX" "$SOURCE_SHA")

if [[ ! "$split" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Refusing to publish $TAG: the split of $PREFIX at $SOURCE_SHA did not produce a commit." >&2
  exit 1
fi

git push --quiet "$REMOTE" "$split:refs/tags/$TAG"
echo "Published $TAG as $split."

if ! git merge-base --is-ancestor "$SOURCE_SHA" "$MAIN_REF"; then
  echo "$TAG is not on the monorepo's main, so the mirror's main is left where it is."
  exit 0
fi

mirror_main=refs/kitsune-split/mirror-main

for attempt in 1 2 3 4 5; do
  # An empty mirror has no main to fetch, and that is the first release rather than an error.
  if git fetch --quiet "$REMOTE" "+refs/heads/main:$mirror_main" 2>/dev/null \
    && git merge-base --is-ancestor "$split" "$mirror_main"; then
    echo "The mirror's main already contains $TAG."
    exit 0
  fi

  if git push --quiet "$REMOTE" "$split:refs/heads/main" 2>/dev/null; then
    echo "The mirror's main is now $TAG."
    exit 0
  fi

  sleep $((attempt * RETRY_DELAY))
done

echo "Could not move the mirror's main to $TAG: it holds history this split does not contain. A mirror" >&2
echo "created with a README or licence of its own is refused rather than overwritten; it must start empty." >&2
exit 1
