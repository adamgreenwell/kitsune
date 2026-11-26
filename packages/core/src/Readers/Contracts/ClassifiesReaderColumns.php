<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

/**
 * Optional subject-access capability for host-owned reader models. Implementing it does not change ReaderAccount.
 * Storage remains host-owned. Each export key maps directly to its physical column, and secrets have no export key.
 */
interface ClassifiesReaderColumns
{
    /** @return array<string, array{class: ReaderColumnClass, export_as?: string}> */
    public static function readerColumnPrivacy(): array;
}
