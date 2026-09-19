# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# The interpreter ADR-027's floor is measured on. bin/benchmark-floor.sh builds this when no --image is given.
#
# ⚠️ THE OFFICIAL IMAGE CANNOT RUN THE APPLICATION IT WAS MEASURING. `php:8.4-cli` loads neither ext-intl, which
# filament/support requires, nor ext-zip, which openspout/openspout requires. The benchmark still booted, because its
# samples never call either, so for eleven days the floor was measured on an interpreter Composer itself would refuse
# to install the application for — found on #126 once Composer resolved for the image's platform rather than the
# host's. With both added the heap figure did not move, since `memory_get_peak_usage()` counts PHP's heap and not
# memory a native library such as ICU allocates for itself; the point is that the measured install is now one the
# interpreter can actually run.
#
# ⚠️ THE BASE IS PINNED; WHAT apt INSTALLS IS NOT. The digest fixes PHP itself. libicu-dev and libzip-dev come from
# the Debian mirror at build time, so the ICU and libzip versions can move between builds — the harness records the
# ICU version beside every result instead, which is where a reader can see it moved.
FROM php@sha256:a545b9041fb0e378cb597b4d0509f77c6a4d996dd485763af92e5b7e59c469cc

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
 && docker-php-ext-install intl zip \
 && rm -rf /var/lib/apt/lists/*
