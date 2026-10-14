<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use RuntimeException;

/** A complete privacy operation was refused, with neither personal data nor a chained exception. @internal */
final class ReaderModuleDataUnavailable extends RuntimeException
{
    /** @param 'export'|'erase' $action */
    public function __construct(string $action, public readonly ?string $state = null)
    {
        $past = $action === 'export' ? 'exported' : 'erased';
        parent::__construct('A reader\'s module data could not be '.$past.'. A complete privacy operation requires '
            .'every installed module\'s data store to be available and compatible. Restore its package and review its '
            .'stored schema before retrying.'.($state !== null ? ' The database refused (SQLSTATE '.$state.'); its message is not repeated.' : ''));
    }
}
