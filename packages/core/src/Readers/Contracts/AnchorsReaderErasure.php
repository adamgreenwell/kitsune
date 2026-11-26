<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

/**
 * Optional host capability for erasure replay. The column holds an immutable, randomly generated UUID v4
 * assigned on account creation and stored with the account in backups. Store it lowercase and unique per Org.
 * Never move the marker to a replacement account. Declare it operational and omit it
 * from subject exports. Never derive it from a reader key, address or other account data.
 *
 * On a separate connection the host must support transactional hard deletion and primary readback.
 */
interface AnchorsReaderErasure extends ClassifiesReaderColumns
{
    public static function readerErasureAnchorColumn(): string;
}
