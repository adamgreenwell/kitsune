<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Closure;
use Illuminate\Console\Command;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Explicit-org operator bootstrap; no ambient org or site survives maintenance. @internal */
abstract class StripeInboxCommand extends Command
{
    /** @param Closure(int):int $work */
    protected function forOrg(Context $context, Closure $work): int
    {
        $beforeOrg = $context->org();
        $beforeSite = $context->site();
        try {
            $slug = $this->option('org');
            $limit = $this->option('limit');
            if (! is_string($slug) || $slug === '' || strlen($slug) > 255 || preg_match('/[\x00-\x20\x7f]/', $slug) === 1
                || ! is_string($limit) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) !== 1 || (int) $limit > 1000) {
                $this->error('An explicit --org=<slug> and --limit from 1 through 1000 are required.');

                return self::FAILURE;
            }
            // Org is itself Unscoped: this exact operator-supplied slug establishes, rather than bypasses, context.
            $org = Org::query()->useWritePdo()->where('slug', $slug)->first();
            if ($org === null) {
                throw new \RuntimeException;
            }
            $context->forget()->setOrg($org);

            return $work((int) $limit);
        } catch (Throwable) {
            $this->error('Stripe inbox maintenance could not complete. Private error details are withheld; check configuration and retry.');

            return self::FAILURE;
        } finally {
            $context->forget();
            $beforeSite !== null ? $context->setSite($beforeSite) : $context->setOrg($beforeOrg);
        }
    }
}
