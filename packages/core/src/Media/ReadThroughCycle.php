<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;

/**
 * A `read-through` disk whose halves name each other — ADR-042 decision 5, review of slice 5c.
 *
 * @internal
 *
 * ⚠️ NEVER BUILT. Laravel builds a read-through disk by building its halves first, and one whose halves name each other
 * recurses until memory runs out: a fatal error no catch can answer. So custody asks the configuration first, and such a
 * disk holds nothing, serves nothing, and makes a row naming it one whose presence cannot be told.
 */
final class ReadThroughCycle extends RuntimeException {}
