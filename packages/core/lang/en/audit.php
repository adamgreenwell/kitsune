<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Who an audit row names, in the owner's pages' words — `Kitsune\Core\Filament\AuditActors`, shared by the credentials
 * page and an entitlement's history, so the two can never name the same departed owner differently.
 */
return [
    'who' => [
        'system' => 'the system',
        'former' => 'someone no longer in this organisation',
        'other' => 'an account of another kind',
    ],
];
