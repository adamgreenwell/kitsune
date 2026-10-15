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

/** Seven-day physical payload cleanup; receipts remain for safe deduplication and replay. @internal */
final class StripePruneCommand extends StripeInboxCommand
{
    protected $signature = 'kitsune:stripe-prune {--org= : Organisation slug} {--limit=100 : Maximum payloads, 1 through 1000}';

    protected $description = 'Clear a bounded batch of expired Stripe raw payloads.';

    public function handle(Context $context, StripeEventInbox $inbox): int
    {
        return $this->forOrg($context, function (int $limit) use ($inbox): int {
            $this->info(sprintf('Cleared %d expired Stripe payloads; verified receipts retained.', $inbox->prune($limit)));

            return self::SUCCESS;
        });
    }
}
