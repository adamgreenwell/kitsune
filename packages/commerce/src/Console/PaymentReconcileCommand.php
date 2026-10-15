<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Kitsune\Commerce\Payments\PaymentMaintenance;
use Kitsune\Core\Tenancy\Context;

/** Explicit bounded original-account test-payment catch-up and refund recovery. @internal */
final class PaymentReconcileCommand extends StripeInboxCommand
{
    protected $signature = 'kitsune:payments-reconcile {--org= : Organisation slug} {--limit=100 : Maximum operations, 1 through 1000} {--seconds=30 : Stop starting operations after this budget, 1 through 300} {--refresh-successful : Also refresh previously succeeded refunds for later failure}';

    protected $description = 'Process a fair bounded batch of test confirmations, purchased access and refund recovery without a worker.';

    public function handle(Context $context, PaymentMaintenance $payments): int
    {
        return $this->forOrg($context, function (int $limit) use ($payments): int {
            $seconds = $this->option('seconds');
            if (! is_string($seconds) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $seconds) !== 1 || (int) $seconds > 300) {
                $this->error('The time budget must be an integer from 1 through 300 seconds.');

                return self::FAILURE;
            }
            $counts = $payments->run($limit, (int) $seconds, (bool) $this->option('refresh-successful'));
            $this->info(sprintf('Payments: %d processed, %d need attention.', $counts['processed'], $counts['blocked']));

            return $counts['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
