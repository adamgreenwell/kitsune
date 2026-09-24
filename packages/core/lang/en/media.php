<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The media admin's words — ADR-042.
 *
 * The first strings core ships under its own `kitsune` namespace, so that the media work does not widen the gap
 * ADR-018's rule 1 leaves across the rest of the admin: `__('kitsune::media.…')`.
 */
return [
    'type' => [
        'holds_media' => 'Holds media',
        'holds_media_help' => 'Entries of this type are uploaded files rather than written in a form. Decide now: it cannot be changed once the type exists.',
        'holds_media_locked' => 'Decided when this type was created, and fixed from then on.',
    ],
    'dashboard' => [
        'site_own' => 'this site\'s own, not the files shared across the organisation',
    ],
    'delete' => [
        'refused' => '":title" was not deleted',
        'refused_line' => '":title" was not deleted: :reason',
        'refused_bulk' => '{1} One entry was not deleted; its file could not be taken off the web|[2,*] :count entries were not deleted; their files could not be taken off the web',
    ],
];
