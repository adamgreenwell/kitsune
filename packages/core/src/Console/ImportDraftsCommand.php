<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use DomainException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportPlan;
use Kitsune\Core\Imports\StructuredDraftInput;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Host-registered command for rehearsing the draft importer.
 *
 * @internal
 */
final class ImportDraftsCommand extends Command
{
    protected $signature = 'kitsune:import-drafts
        {action : preview, digest, apply, status or abandon}
        {path : Local input CSV, JSON or exact report file}
        {--org= : Destination Org slug}
        {--site= : Destination Site handle}
        {--source= : Stable source identity for preview}
        {--format= : csv or json for preview}
        {--type=* : Map a source type as source-name=destination-handle}
        {--complete : Confirm that the supplied rows cover the intended draft import}
        {--approval= : Exact digest of the reviewed report}';

    protected $description = 'Rehearse a resumable draft import from a trusted host shell';

    public function handle(Context $context, StructuredDraftInput $input, ImportJournal $journal): int
    {
        $previousOrg = $context->org();
        $previousSite = $context->site();

        try {
            $action = $this->argument('action');
            if (! in_array($action, ['preview', 'digest', 'apply', 'status', 'abandon'], true)) {
                throw new InvalidArgumentException('Choose preview, digest, apply, status or abandon.');
            }

            $org = Org::query()->useWritePdo()->where('slug', $this->requiredOption('org'))->first();
            $context->forget()->setOrg($org);
            $site = $org === null ? null : Site::query()->useWritePdo()->where('org_id', $org->id)->where('handle', $this->requiredOption('site'))->first();
            if ($site === null) {
                throw new InvalidArgumentException('The selected Site does not exist in that Org.');
            }
            $context->setSite($site);

            $bytes = $this->readInput();
            if ($action === 'preview') {
                $plan = $input->preview(
                    $this->requiredOption('format'), $this->requiredOption('source'), $bytes, $this->destinationTypes($site),
                    (bool) $this->option('complete'),
                );
                $this->output->write($plan->report(), false, OutputInterface::OUTPUT_RAW);

                return $plan->complete ? self::SUCCESS : self::FAILURE;
            }

            if ($action === 'digest') {
                $plan = ImportPlan::restore($bytes);
                if ($plan->orgId !== $site->org_id || $plan->siteId !== $site->id || ! $plan->complete) {
                    throw new DomainException('Only a complete report for the selected Site can be approved.');
                }

                $this->line($plan->digest());

                return self::SUCCESS;
            }

            $approval = $this->requiredOption('approval');
            if ($action === 'apply') {
                $journal->apply($bytes, $approval);
            } elseif ($action === 'abandon') {
                $journal->abandon($bytes, $approval);
            }
            $this->output->writeln(json_encode($journal->executionReport($bytes, $approval), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (DomainException|InvalidArgumentException $refused) {
            $this->output->getErrorStyle()->error($refused->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->output->getErrorStyle()->error('The import command stopped. Use status with the same report to check committed drafts.');

            return self::FAILURE;
        } finally {
            $context->setSite($previousSite)->setOrg($previousOrg);
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The --{$name} option is required.");
        }

        return $value;
    }

    private function readInput(): string
    {
        $path = $this->argument('path');
        $file = is_string($path) ? realpath($path) : false;
        $contents = $file !== false && is_file($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            throw new InvalidArgumentException('Provide a readable local input file.');
        }

        return $contents;
    }

    /** @return array<string,int> */
    private function destinationTypes(Site $site): array
    {
        $available = EntryType::visibleFor($site, $site->org_id)->keyBy('handle');
        $types = [];
        foreach ($this->option('type') as $pair) {
            $parts = explode('=', (string) $pair, 2);
            if (count($parts) !== 2 || $parts[0] === '' || isset($types[$parts[0]]) || ! $available->has($parts[1])) {
                throw new InvalidArgumentException('Map each source type once to an available destination entry type.');
            }

            $types[$parts[0]] = $available->get($parts[1])->id;
        }

        return $types;
    }
}
