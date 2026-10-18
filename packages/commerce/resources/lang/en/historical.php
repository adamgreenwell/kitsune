<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'title' => 'Review historical access terms',
    'help' => 'Review only access terms missing from the original purchase. Every correction is a new audited decision; the original invoice, recipient, awarded units and payment time stay unchanged. Refunds, disputes and account erasure still apply.',
    'line' => 'Original purchase line',
    'choose' => 'Choose a line',
    'decision' => 'Review decision',
    'hold' => 'Keep on hold',
    'none' => 'No access was sold',
    'award' => 'Award evidenced access',
    'kind' => 'Evidenced term',
    'fixed' => 'Fixed term',
    'permanent' => 'Permanent',
    'days' => 'Whole days from original payment confirmation',
    'reason' => 'Review reason',
    'evidence' => 'Evidence reference',
    'evidence_help' => 'Use an archive or document reference, not credentials, invoice contents or personal details. Private notes are removed on account erasure or invoice expiry.',
    'confirm' => 'I reviewed the original evidence and authorize this decision, replacing the previous decision for this line if any.',
    'save' => 'Record access review',
    'saved' => 'Historical access review recorded',
    'refused' => 'The review could not be recorded. Check the evidence, terms and current order state, then reload before retrying a changed decision.',
    'expired_notes' => 'Invoice personal data has expired. This review keeps only the decision and hash commitments; its private notes and staff reference will not be stored.',
    'confirmed' => 'Original payment confirmation',
    'expires' => 'Resulting fixed-term expiry',
    'expired_help' => 'An expiry in the past grants no new time. Later review, recovery and guest claim never restart the original clock.',
    'history' => 'Historical access reviews',
    'summary' => 'Original missing access terms have been reviewed. The current access and financial summaries show what remains available.',
];
