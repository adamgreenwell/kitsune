<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Tenancy\Context;

/** Replay uses minimal verified receipts, never raw bytes or a newly minted provider create. @internal */
final class StripeReplayCommand extends StripeInboxCommand
{
    protected $signature = 'kitsune:stripe-replay {--org= : Organisation slug} {--limit=100 : Maximum receipts, 1 through 1000} {--event= : One local receipt ID} {--seconds=30 : Stop starting receipts after this many seconds, 1 through 300}';

    protected $description = 'Reconcile a bounded batch of verified Stripe test receipts.';

    public function handle(Context $context, StripeEventInbox $inbox): int
    {
        return $this->forOrg($context, function (int $limit) use ($inbox): int {
            $id = $this->option('event');
            $seconds = $this->option('seconds');
            if (! is_string($seconds) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $seconds) !== 1 || (int) $seconds > 300) {
                $this->error('The time budget must be an integer from 1 through 300 seconds.');

                return self::FAILURE;
            }
            if ($id !== null && (! is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1 || (string) (int) $id !== $id)) {
                $this->error('The receipt ID must be a positive canonical integer.');

                return self::FAILURE;
            }
            $counts = $inbox->replay($limit, $id === null ? null : (int) $id, (int) $seconds);
            $this->info(sprintf('Stripe receipts: %d processed, %d retryable, %d blocked, %d pending.', ...array_values($counts)));

            return $counts['retryable'] + $counts['blocked'] + $counts['pending'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
