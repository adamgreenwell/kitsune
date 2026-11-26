<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

/** @internal */
enum ConsentPurpose: string
{
    case Profiling = 'profiling';
    case SupportWidget = 'support_widget';

    public function setting(): string
    {
        return 'reader_consent_'.$this->value;
    }
}
