<?php

declare(strict_types=1);

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

return [
    'title' => 'Blueprints',
    'intro' => 'Blueprints add structure and roles across :org, including its other sites. They do not create an owner or sample content. Review the changes before continuing.',
    'versions' => 'Installed: :installed · Available: :available',
    'none' => 'Not applied',
    'unavailable' => 'Definition no longer registered',
    'status' => [
        'not_applied' => 'Not yet applied to this organisation.',
        'applied' => 'The receipt records a completed apply. Operator edits stay yours.',
        'unfinished' => 'An earlier apply did not finish. Reapply resumes from its receipt; it does not start over.',
    ],
    'type' => 'Entry type: :name (:handle)',
    'field' => ':type field: :name (:handle)',
    'role' => 'Role: :name (:handle)',
    'grant' => ':role may :actions on :type entries.',
    'action' => ['apply' => 'Apply or update', 'reverse' => 'Review reverse'],
    'confirm_action' => ['apply' => 'Confirm apply or update', 'reverse' => 'Confirm reverse'],
    'review_heading' => 'Review :handle for :org',
    'review_description' => 'This affects the whole organisation. Review is advisory: the kernel checks current data again when you confirm. Cancel to leave everything as it is.',
    'apply_review' => 'This is the registered declaration, not a successful dry run. Apply adds missing structure, resumes an interrupted apply, or performs an additive upgrade. It preserves operator edits and names any refusal.',
    'preview' => [
        'refusals' => 'Blocks this reverse',
        'removed' => 'Would remove if nothing blocks',
        'kept' => 'Would keep',
        'gone' => 'Already gone',
        'notes' => 'Review notes',
        'problems' => 'Receipt problems',
    ],
    'receipt_only' => 'This would clear the receipt alone. No structure or content would be removed.',
    'confirmation' => 'Confirm blueprint and organisation',
    'confirmation_help' => 'Type exactly: :confirmation',
    'invalid_action' => 'Choose Apply or Review reverse.',
    'no_receipt' => 'This organisation has no receipt for that blueprint. Reload before reviewing it again.',
    'not_registered' => 'That blueprint is not registered by the installed code.',
    'review_changed' => 'The confirmation or reviewed state no longer matches. Cancel, review the current blueprint and organisation, and try again.',
    'refused' => 'Blueprint action did not complete',
    'completed' => 'Blueprint action completed',
    'completed_body' => 'The action for :handle in :org completed. The page now shows its current receipt.',
    'cleanup_owed' => 'The row operation completed, but indexed-column cleanup still needs attention: :columns. Check the blueprint command status before retrying.',
    'storage_failure' => 'A storage operation could not complete. Check the blueprint status before retrying; an interrupted apply can be resumed. No complete result is claimed.',
    'unexpected_failure' => 'The action did not complete. Check the blueprint status before retrying. No complete result is claimed.',
];
