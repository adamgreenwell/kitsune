<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\ReaderGuard;

use Illuminate\Console\Command;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Two readers in each of three orgs, and one producer's grant on Golfdom that the browser did not make.
 *
 * ⚠️ IDEMPOTENT, SO IT IS ALSO THE RESET. Readers are found or created; the grant is the writer's, which answers
 * `Granted` on a reader whose rows `kitsune:entitlements forget` erased first, as `entitlements.spec.js` does.
 *
 * ⚠️ IT PRINTS THE OUTCOME, NEVER AN IDENTIFIER, as core's console does — even of readers it invented. A refusal exits 1,
 * so `global-setup.js` stops the run there: a guard that did not take effect is found before any spec runs.
 */
final class SeedPublicReadersCommand extends Command
{
    protected $signature = 'e2e:public-readers-seed';

    protected $description = 'Seed the browser suite\'s public readers and one producer\'s grant (test-only).';

    /**
     * ⚠️ GOLFDOM MEDIA FIRST: its readers are 1 and 2 on the fresh table every run starts with, and the specs name them.
     * Org ids cannot decide it, because inkwell exists before the seeder runs.
     */
    private const ORGS = ['golfdom-media', 'rival', 'inkwell'];

    public function handle(Context $context, EntitlementWriter $writer): int
    {
        try {
            foreach (self::ORGS as $slug) {
                $context->forget()->setOrg(Org::query()->where('slug', $slug)->firstOrFail());

                foreach ([1, 2] as $n) {
                    PublicReader::query()->firstOrCreate(['email' => "public-reader-{$n}@{$slug}.test"]);
                }
            }

            // A producer's row, granted with nobody signed in — the system, as a verified payment will be.
            $context->forget()->setOrg(Org::query()->where('slug', 'golfdom-media')->firstOrFail());
            $context->setSite(Site::query()->where('slug', 'golfdom')->firstOrFail());
            $first = PublicReader::query()->where('email', 'public-reader-1@golfdom-media.test')->firstOrFail();
            $outcome = $writer->grant($first->getKey(), 'course.advanced-php', 'e2e.order:1', null);

            $this->line('e2e.order:1 '.$outcome->name);
        } catch (EntitlementRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        } finally {
            $context->forget();
        }

        return self::SUCCESS;
    }
}
