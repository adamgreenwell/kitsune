<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The credentials page's words — ADR-040, its admin half. A credential's own label and help are its module's; the store's
 * refusals keep their own sentences and arrive here only as a notice's body.
 */
return [
    'navigation' => 'Credentials',
    'title' => 'Credentials',
    'intro' => 'Keys and secrets the modules enabled here use, kept for :org and shared by every one of its sites. A value saved here is never shown again, to you or to anyone: it can be replaced or removed, and nothing else. If you are not sure which key is stored, paste the right one again. "Set" means a value is stored, not that the provider accepts it.',
    'empty' => [
        'heading' => 'Nothing here keeps a credential yet',
        'description' => 'No module enabled on this installation needs one. A module that does (payments, for example) lists its keys here once it is enabled.',
    ],
    'mode' => [
        'heading' => 'Mode',
        'test' => 'Test mode',
        'live' => 'Live mode',
        'test_meaning' => 'Test credentials are in use. No real money moves.',
        'live_meaning' => 'Live credentials are in use. Real money moves.',
    ],
    'line' => [
        'moded' => ':label (:mode)',
        'test' => 'test mode',
        'live' => 'live mode',
        'in_use' => 'In use',
        'changed' => 'Changed',
        'by' => 'By',
    ],
    'status' => [
        'not_set' => 'Not set',
        'removed' => 'Removed',
        'set' => 'Set',
        'previous_key' => 'Set under a previous app key',
        'unreadable' => 'Unreadable',
    ],
    'note' => [
        'previous_key' => 'It still works. Replacing it (pasting the same key again is enough) moves it to the current app key.',
        'unreadable' => 'It was stored under an app key this installation no longer has, or what is stored is damaged, so nothing can use it. Paste it again from where it was issued. If the app key was changed by mistake, whoever runs this installation can put it back, which brings back every credential at once.',
    ],
    'format' => [
        'begins' => 'Begins :prefixes.',
        'or' => ' or ',
        'line_decides' => 'Nothing in a value says which mode it is for: the line you paste into decides.',
        'length' => ':min to :max characters.',
    ],
    'aria' => [
        'set' => 'Set :line',
        'replace' => 'Replace :line',
        'remove' => 'Remove :line',
    ],
    'set' => [
        'set' => 'Set',
        'replace' => 'Replace',
        'heading_set' => 'Set :line',
        'heading_replace' => 'Replace :line',
        'description' => 'Paste the value. Once saved it is stored encrypted and never shown again, here or anywhere in the admin.',
        'description_mode' => 'This is the value used while the organisation is in :mode.',
        'description_replace' => 'Saving overwrites the value stored now, which cannot be recovered.',
        'field' => 'Value',
        'submit' => 'Save',
        'saved' => 'Saved: :line',
        'saved_body' => 'It is stored encrypted and cannot be shown again.',
        'replaced' => 'Replaced: :line',
        'replaced_body' => 'The previous value is gone.',
        'refused' => 'Not saved: :line',
        'in_address_title' => 'Not saved',
        'in_address' => 'The value arrived in the page address, where server logs keep it, so it was not stored. Treat it as exposed: replace it where it was issued, then paste the new one here.',
    ],
    'remove' => [
        'action' => 'Remove',
        'heading' => 'Remove :line?',
        'description' => 'Anything that uses it stops working until a value is set again. The value cannot be recovered.',
        'in_use' => 'It is in use right now, so that starts at once.',
        'submit' => 'Remove',
        'removed' => 'Removed: :line',
        'nothing' => ':line was not set, so nothing was removed.',
        'refused' => 'Not removed: :line',
    ],
    'switch' => [
        'to_live' => 'Switch to live mode',
        'to_test' => 'Switch to test mode',
        'to_live_heading' => 'Switch :org to live mode?',
        'to_live_description' => 'From now on every credential kept for test and live mode is read from its live line. With a payment provider, real money moves.',
        'missing' => 'These have no usable live value yet, and will refuse to work until one is set: :labels.',
        'to_test_heading' => 'Switch :org to test mode?',
        'to_test_description' => 'From now on every credential kept for test and live mode is read from its test line, and no real money moves.',
        'now_live' => 'Now in live mode',
        'now_test' => 'Now in test mode',
        'already_live' => 'Already in live mode. Nothing was changed.',
        'already_test' => 'Already in test mode. Nothing was changed.',
        'refused' => 'Not switched',
    ],
];
