<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Illuminate\Console\Command;
use Kitsune\Commerce\Models\RetentionPolicy;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/** Cold installed-data maintenance: no module runtime, external service, schedule or worker is required. @internal */
final class RetentionCommand extends Command
{
    protected $signature = 'kitsune:commerce-retention
        {--org= : Exact Org slug to examine}
        {--limit=100 : Maximum orders to examine, from 1 through 1000}
        {--apply : Apply the previewed kind of retention work; otherwise preview only}';

    protected $description = 'Preview or apply a bounded batch of operator-configured commerce retention work.';

    public function handle(Context $context, OrderPrivacy $privacy): int
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
            // Org is global: the exact operator-supplied slug establishes the scope for this one batch.
            $org = Org::query()->useWritePdo()->where('slug', $slug)->first();
            $connection = (new RetentionPolicy)->getConnection();
            if ($org === null || ! $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->exists()) {
                throw new RuntimeException;
            }
            $context->forget()->setOrg($org);
            $dryRun = $this->option('apply') !== true;
            $result = $privacy->sweep((int) $limit, dryRun: $dryRun);
            $this->info(sprintf('%s: %d orders examined; %d retained orders minimized; %d order parents deleted; %d held. Minimized parents are not counted as deleted.',
                $dryRun ? 'Preview only' : 'Retention applied', $result['examined'], $result['minimized'], $result['purged'], $result['held']));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Commerce retention could not complete. Private details are withheld; check the recorded policy and retained data before retrying.');

            return self::FAILURE;
        } finally {
            $context->forget();
            $beforeSite !== null ? $context->setSite($beforeSite) : $context->setOrg($beforeOrg);
        }
    }
}
