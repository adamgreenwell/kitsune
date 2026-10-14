<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Illuminate\Console\Command;
use Kitsune\Commerce\Carts\CartPruner;
use RuntimeException;

/** Operator-invoked cleanup only: no worker, schedule or public route. @internal */
final class CartPruneCommand extends Command
{
    protected $signature = 'kitsune:cart-prune {--limit=100 : Maximum expired carts to examine, from 1 through 1000}';

    protected $description = 'Remove a bounded batch of expired carts and their lines.';

    public function handle(CartPruner $pruner): int
    {
        $limit = $this->option('limit');

        if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) !== 1 || (int) $limit > 1000) {
            $this->error('Cart cleanup limit must be an integer from 1 through 1000.');

            return self::FAILURE;
        }

        try {
            $removed = $pruner->prune((int) $limit);
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Removed %d expired cart%s.', $removed, $removed === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
