<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers\Contracts;

enum ReaderColumnClass: string
{
    case Personal = 'personal';
    case Sensitive = 'sensitive';
    case Operational = 'operational';
    case Secret = 'secret';
}
