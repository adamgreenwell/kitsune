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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportMediaAsset;
use Kitsune\Core\Imports\ImportPlan;
use Kitsune\Core\Imports\ImportRedirectPlan;
use Kitsune\Core\Imports\StructuredDraftInput;
use Kitsune\Core\Imports\WordPressBodyRead;
use Kitsune\Core\Imports\WordPressDatabaseExport;
use Kitsune\Core\Imports\WordPressDraftInput;
use Kitsune\Core\Imports\WordPressSourceInput;
use Kitsune\Core\Imports\WordPressWxrExport;
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
 *
 * @phpstan-import-type SourceRow from WordPressWxrExport as WordPressSourceRow
 */
final class ImportDraftsCommand extends Command
{
    protected $signature = 'kitsune:import-drafts
        {action : preview, digest, stage-media, apply, status, purge-staged-media, abandon, plan-redirects, redirect-digest or apply-redirects}
        {path : Local draft input, WordPress export, reviewed ACF report or exact approval report}
        {--org= : Destination Org slug}
        {--site= : Destination Site handle}
        {--source= : Stable source identity for preview}
        {--format= : csv, json, wxr, wordpress-database, wordpress-direct or wordpress-wxr for preview}
        {--wordpress-blog= : Confirmed blog address for WordPress preview}
        {--wordpress-connection= : A separate Laravel source connection for the offline WordPress database}
        {--wordpress-prefix= : Exact WordPress source table prefix}
        {--acf-schema= : Reviewed ACF field report for WordPress preview}
        {--wordpress-body=* : Map a raw WordPress post type as source-type=rich-text-field}
        {--wordpress-inline-media= : Reviewed local inventory of inline images}
        {--type=* : Map a source type as source-name=destination-handle}
        {--complete : Confirm that the supplied rows cover the intended draft import}
        {--media-manifest= : Local JSON list of media descriptors for preview}
        {--media-root= : Local directory containing approved source files}
        {--redirect-inventory= : Local former URL inventory for redirect planning or apply}
        {--entry-route=site.home : Host fallback route name for redirect planning}
        {--redirect-report= : Exact reviewed redirect report for apply-redirects}
        {--redirect-approval= : Approved digest of the reviewed redirect report}
        {--approval= : Exact digest of the reviewed report}';

    protected $description = 'Rehearse a resumable draft import from a trusted host shell';

    public function handle(Context $context, StructuredDraftInput $input, WordPressDraftInput $wordPress, WordPressSourceInput $sourceInput, ImportJournal $journal): int
    {
        $previousOrg = $context->org();
        $previousSite = $context->site();

        try {
            $action = $this->argument('action');
            if (! in_array($action, ['preview', 'digest', 'stage-media', 'apply', 'status', 'purge-staged-media', 'abandon', 'plan-redirects', 'redirect-digest', 'apply-redirects'], true)) {
                throw new InvalidArgumentException('Choose preview, digest, stage-media, apply, status, purge-staged-media, abandon, plan-redirects, redirect-digest or apply-redirects.');
            }

            $org = Org::query()->useWritePdo()->where('slug', $this->requiredOption('org'))->first();
            $context->forget()->setOrg($org);
            $site = $org === null ? null : Site::query()->useWritePdo()->where('org_id', $org->id)->where('handle', $this->requiredOption('site'))->first();
            if ($site === null) {
                throw new InvalidArgumentException('The selected Site does not exist in that Org.');
            }
            $context->setSite($site);

            if ($action !== 'preview' && ($this->option('wordpress-body') !== [] || $this->option('wordpress-inline-media') !== null)) {
                throw new InvalidArgumentException('Select WordPress body fields when previewing the source.');
            }

            if ($action === 'preview') {
                $media = $this->mediaManifest();
                $format = $this->requiredOption('format');
                $source = $this->requiredOption('source');
                $destination = $this->destinationTypes($site);
                $bodyFields = $this->wordpressBodyFields($format, $site, $destination);
                $bodyRead = $bodyFields === [] ? null : new WordPressBodyRead(array_map(strval(...), array_keys($bodyFields)));
                $inlinePath = $this->option('wordpress-inline-media');
                if ($inlinePath !== null && $bodyRead === null) {
                    throw new InvalidArgumentException('Inline images require a reviewed raw WordPress body mapping.');
                }
                $inline = $inlinePath === null ? null : $this->readLocalFile($inlinePath);
                $complete = (bool) $this->option('complete');
                $mediaRoot = $media === [] ? null : $this->requiredOption('media-root');
                $plan = match ($format) {
                    'wxr', 'wordpress-database' => $sourceInput->preview(
                        $format === 'wxr' ? 'wxr' : 'direct', $this->wordpressSource($format, $site, $bodyRead), $source,
                        $format === 'wxr' ? $this->readLocalFile($this->requiredOption('acf-schema')) : $this->readInput(),
                        $destination, $complete, $media, $mediaRoot, $bodyFields, $inline,
                    ),
                    'wordpress-direct', 'wordpress-wxr' => $wordPress->preview(
                        substr($format, strlen('wordpress-')), $source, $this->readInput(), $destination, $complete, $media, $mediaRoot,
                    ),
                    default => $input->preview($format, $source, $this->readInput(), $destination, $complete, $media, $mediaRoot),
                };
                $this->output->write($plan->report(), false, OutputInterface::OUTPUT_RAW);

                return $plan->complete ? self::SUCCESS : self::FAILURE;
            }

            $bytes = $this->readInput();
            if ($action === 'digest') {
                $plan = ImportPlan::restore($bytes);
                if ($plan->orgId !== $site->org_id || $plan->siteId !== $site->id || ! $plan->complete) {
                    throw new DomainException('Only a complete report for the selected Site can be approved.');
                }

                $this->line($plan->digest());

                return self::SUCCESS;
            }

            if ($action === 'redirect-digest') {
                $reviewed = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($reviewed) || ($reviewed['org_id'] ?? null) !== $site->org_id
                    || ($reviewed['site_id'] ?? null) !== $site->id || ($reviewed['version'] ?? null) !== 1) {
                    throw new DomainException('The redirect report does not belong to the selected Org and Site.');
                }
                $this->line(ImportRedirectPlan::digest($bytes));

                return self::SUCCESS;
            }

            $approval = $this->requiredOption('approval');
            if ($action === 'plan-redirects') {
                $this->output->write(app(ImportRedirectPlan::class)->preview(
                    $site, $bytes, $approval, $this->readLocalFile($this->requiredOption('redirect-inventory')), $this->requiredOption('entry-route'),
                ), false, OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            if ($action === 'apply-redirects') {
                $ids = app(ImportRedirectPlan::class)->apply(
                    $site, $bytes, $approval, $this->readLocalFile($this->requiredOption('redirect-inventory')), $this->requiredOption('entry-route'),
                    $this->readLocalFile($this->requiredOption('redirect-report')), $this->requiredOption('redirect-approval'),
                );
                $this->output->writeln(json_encode(['redirect_ids' => $ids], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            if ($action === 'stage-media') {
                $ids = $journal->stageMedia($bytes, $approval, $this->requiredOption('media-root'));
                $this->output->writeln(json_encode(['state' => 'staged', 'reservations' => $ids], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            if ($action === 'purge-staged-media') {
                $ids = $journal->purgeStagedMedia($bytes, $approval);
                $this->output->writeln(json_encode(['state' => 'purged', 'reservations' => $ids], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
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

    /** @return array{sha256: string, blog: string, rows: list<WordPressSourceRow>} */
    private function wordpressSource(string $format, Site $site, ?WordPressBodyRead $bodies): array
    {
        $blog = $this->requiredOption('wordpress-blog');
        if ($format === 'wxr') {
            return app(WordPressWxrExport::class)->read((string) $this->argument('path'), $blog, $bodies);
        }

        $name = $this->requiredOption('wordpress-connection');
        if ($name === $site->getConnection()->getName() || $name === DB::getDefaultConnection()) {
            throw new InvalidArgumentException('Choose a separate connection for the offline WordPress database.');
        }

        return app(WordPressDatabaseExport::class)->read(DB::connection($name)->getPdo(), $this->requiredOption('wordpress-prefix'), $blog, $bodies);
    }

    /**
     * @param  array<string, int>  $types
     * @return array<string, string>
     */
    private function wordpressBodyFields(string $format, Site $site, array $types): array
    {
        $options = $this->option('wordpress-body');
        if ($options === []) {
            return [];
        }
        if (! in_array($format, ['wxr', 'wordpress-database'], true)) {
            throw new InvalidArgumentException('WordPress body mappings require a raw WXR or offline database source.');
        }

        $available = EntryType::visibleFor($site, $site->org_id)->keyBy('id');
        $mappings = [];
        foreach ($options as $pair) {
            [$source, $handle] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $type = isset($types[$source]) ? $available->get($types[$source]) : null;
            if ($type === null || isset($mappings[$source]) || in_array($source, ['attachment', 'revision'], true)) {
                throw new InvalidArgumentException('Map the body of each available WordPress post type once.');
            }
            $config = EntryFieldContract::forType($type)->definitions()[$handle] ?? null;
            if ($config === null || $config->storage->type !== RichTextType::handle()
                || $config->storage->strategy() !== StorageStrategy::Inline || $config->isMultiValue()) {
                throw new InvalidArgumentException('Map WordPress bodies to an existing single rich-text field.');
            }
            $mappings[$source] = $handle;
        }

        ksort($mappings, SORT_STRING);

        return $mappings;
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
        return $this->readLocalFile($this->argument('path'));
    }

    /** @return list<ImportMediaAsset> */
    private function mediaManifest(): array
    {
        $path = $this->option('media-manifest');
        if ($path === null) {
            return [];
        }

        $items = json_decode($this->readLocalFile($path), flags: JSON_THROW_ON_ERROR);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('The import media manifest must be a list.');
        }

        return array_map(ImportMediaAsset::fromDescriptor(...), $items);
    }

    private function readLocalFile(mixed $path): string
    {
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
