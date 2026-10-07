<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The entitlements page's words — ADR-040, entitlements' second half. The writer's refusals keep their own sentences
 * and arrive here only as a notice's body; who changed a source is `kitsune::audit`'s.
 *
 * ⚠️ EVERY KEY IS A STRING OR AN ARRAY, NEVER BOTH: a key holding an array cannot also be read as a sentence.
 */
return [
    'title' => 'Entitlements',
    'navigation' => 'Entitlements',
    'intro' => 'Who may reach what on :site, from which source, and until when. Readers are shown by their identifier until reader accounts arrive.',
    'holds' => 'A reader holds an entitlement while any of its sources is live.',
    'fault' => [
        'heading' => 'No reader can be told apart yet',
        'description' => 'Nothing can be listed or given here, because :fault. Until whoever runs this installation fixes it, every check answers no.',
    ],
    'empty' => [
        'heading' => 'Nothing has been given on this site',
        'description' => 'A comp, or a producer such as a payment, adds a row here.',
        'filtered' => 'No row matches these filters',
    ],
    'column' => [
        'reader' => 'Reader',
        'entitlement' => 'Entitlement',
        'source' => 'Source',
        'state' => 'State',
        'ends' => 'Ends',
        'changed' => 'Changed',
    ],
    'source' => [
        'comp' => 'Comp',
    ],
    'state' => [
        'live' => 'Live',
        'lapsed' => 'Lapsed',
        'revoked' => 'Revoked',
    ],
    'ends' => [
        'none' => 'No end',
    ],
    'filter' => [
        'reader' => 'Reader',
        'entitlement' => 'Entitlement',
        'source' => 'Source',
        'comps' => 'Comps only',
        'state' => 'State',
        'exact' => 'Exactly as shown in the table.',
        'source_help' => 'Exactly as stored, such as commerce.order:4821. For comps, use Comps only.',
    ],
    'comp' => [
        'action' => 'Comp',
        'heading' => 'Give access by hand',
        'description' => 'A comp is its own source, beside anything the reader already holds, and is recorded under your name.',
        'reader' => 'Reader',
        'reader_help' => 'The reader\'s identifier.',
        'entitlement' => 'Entitlement',
        'entitlement_help' => 'Two lower-case words joined by a dot, such as course.advanced-php.',
        'ends' => 'Ends',
        'ends_none' => 'No end',
        'ends_on' => 'On a date',
        'until' => 'Ends on',
        'submit' => 'Give',
        'granted' => 'Given.',
        'extended' => 'Extended.',
        'reinstated' => 'Given again — this comp had been revoked.',
        'unchanged' => 'Already comped for as long or longer — nothing changed.',
        'refused' => 'Not given',
        'uncertain' => 'Perhaps given, perhaps not',
    ],
    'revoke' => [
        'action' => 'Revoke',
        'heading_comp' => 'Revoke this comp?',
        'description_comp' => 'The reader keeps any access another source gives. You can comp again later.',
        'heading' => 'Revoke :source?',
        'description' => 'It stays revoked: if it is granted again — a replayed payment, a re-run import — nothing changes. The reader keeps any access another source gives. To give access back yourself, use Comp.',
        'submit' => 'Revoke',
        'revoked' => 'Revoked.',
        'nothing' => 'Nothing was revoked: it had already been revoked.',
        'refused' => 'Not revoked',
        'uncertain' => 'Perhaps revoked, perhaps not',
    ],
    'history' => [
        'action' => 'History',
        'heading' => ':entitlement from :source',
        'close' => 'Close',
        'change' => 'Change',
        'when' => 'When',
        'by' => 'By',
        'empty' => 'Nothing is recorded for this source.',
        'granted' => 'Granted',
        'extended' => 'Extended',
        'reinstated' => 'Given again',
        'revoked' => 'Revoked',
        'erased' => 'Erased',
    ],
];
