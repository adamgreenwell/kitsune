<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

/**
 * Whether an org's owners see each reader's address beside their identifier on the entitlements page — the
 * `reader_addresses` setting (ADR-022; ADR-037 decision 1, Adam, 2026-10-10).
 *
 * ⚠️ THE OPERATOR'S CHOICE, NEVER THE OWNER'S. The operator is the data controller (ADR-020), and a row can imply
 * special-category data (ADR-040), so only `kitsune:readers addresses` writes it; nothing in the admin can.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum ReaderAddresses: string
{
    /** Readers are shown by identifier alone. The platform default. */
    case Hidden = 'hidden';

    /** Each reader's address is shown beside their identifier. */
    case Shown = 'shown';
}
