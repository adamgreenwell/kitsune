<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kitsune\Core\Http\Api\SiteTokens;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** @internal */
final class SiteApiTokenCommand extends Command
{
    protected $signature = 'kitsune:api-token
        {action : issue or revoke}
        {--org= : Org slug}
        {--site= : Site handle}
        {--actor= : The staff identifier for issuance}
        {--name= : A token label}
        {--ability=* : An exact entry-type permission to grant}
        {--days=30 : Lifetime in days, from 1 through 365}
        {--id= : Token ID for revocation}';

    protected $description = 'Issue or revoke a Site API token';

    public function handle(Context $context): int
    {
        $action = $this->argument('action');
        $slug = $this->option('org');
        $handle = $this->option('site');

        if (! in_array($action, ['issue', 'revoke'], true)
            || ! is_string($slug) || $slug === '' || ! is_string($handle) || $handle === '') {
            $this->error('Specify issue or revoke with an Org slug and Site handle.');

            return self::FAILURE;
        }

        if (! SiteTokens::enabled()) {
            $this->error('Enable the Site API and configure its staff provider first.');

            return self::FAILURE;
        }

        $priorSite = $context->site();
        $priorOrg = $context->org();

        try {
            $org = Org::query()->where('slug', $slug)->first();
            $context->forget()->setOrg($org);
            $site = $org === null ? null : Site::query()->where('handle', $handle)->first();
            if ($site === null) {
                throw new InvalidArgumentException('The selected Site does not exist in that Org.');
            }

            $context->setSite($site);

            return $action === 'issue' ? $this->issue() : $this->revoke();
        } catch (InvalidArgumentException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        } finally {
            $context->setSite($priorSite)->setOrg($priorOrg);
        }
    }

    private function issue(): int
    {
        $identifier = $this->option('actor');
        $name = $this->option('name');
        $abilities = array_map(static fn (?string $ability): string => $ability ?? '', $this->option('ability'));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $actor = is_string($identifier) ? SiteTokens::actor($identifier) : null;

        if ($actor === null || ! is_string($name) || $days === false) {
            throw new InvalidArgumentException('Specify a staff identifier, name, abilities, and a lifetime of 1 to 365 days.');
        }

        $this->line(SiteTokens::issue($actor, $name, $abilities, now()->addDays($days)));

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $id = filter_var($this->option('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false || ! SiteTokens::revoke($id)) {
            throw new InvalidArgumentException('No token with that ID exists on the selected Site.');
        }

        $this->info('Token revoked.');

        return self::SUCCESS;
    }
}
