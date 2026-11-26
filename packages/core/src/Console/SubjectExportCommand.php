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
use JsonException;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Schema\SubjectDiscovery;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SubjectExportCommand extends Command
{
    protected $signature = 'kitsune:subject-export
        {--org= : The organisation slug}
        {--site=* : The exact handles of Sites to search for content}
        {--all-sites : Search every Site in this organisation}
        {--type= : The entry type ID}
        {--storage= : The nominated subject field storage ID}';

    protected $description = 'Export subject data and coverage as JSON. Read the identifier and optional reader key as JSON on standard input';

    public function handle(Context $context, EntitlementAuthority $authority, SubjectDiscovery $discovery): int
    {
        $slug = $this->option('org');
        if (! is_string($slug) || $slug === '') {
            $this->error('Choose an organisation with --org.');

            return self::INVALID;
        }

        $org = Org::withTrashed()->where('slug', $slug)->first();
        if ($org === null) {
            $this->error('The organisation is unavailable.');

            return self::INVALID;
        }

        try {
            $context->forget()->setOrg($org);
            $authority->authorise(EntitlementRefused::EXPORT, null);
            $typeId = $this->option('type');
            $storageId = $this->option('storage');
            $handles = $this->option('site');
            $all = $this->option('all-sites');
            $content = $typeId !== null || $storageId !== null || $handles !== [] || $all;
            if ($content && (! self::id($typeId) || ! self::id($storageId) || $all === ($handles !== []))) {
                throw new InvalidArgumentException('Content export needs --type, --storage and either --site or --all-sites.');
            }

            $sites = $content ? Site::query()->where('org_id', $org->id)
                ->when(! $all, static fn ($query) => $query->whereIn('handle', $handles))->orderBy('id')->get() : collect();
            if ($content && ($sites->isEmpty() || (! $all && $sites->count() !== count(array_unique($handles))))) {
                throw new InvalidArgumentException('One or more selected Sites are unavailable.');
            }

            $input = $this->readSubject();
            $identifier = $input['identifier'] ?? null;
            $reader = $input['reader'] ?? null;
            if (($content && ! is_scalar($identifier)) || (! $content && $identifier !== null)
                || ($reader !== null && ! is_int($reader) && ! is_string($reader))
                || (! $content && $reader === null)) {
                throw new InvalidArgumentException('Supply a scalar content identifier and/or the explicit reader key as required by the selected scope.');
            }

            $records = [];
            $coverage = [];
            foreach ($sites as $site) {
                $context->setSite($site);
                $result = $discovery->forSite((int) $typeId, (int) $storageId, $identifier, includeRecords: true);
                foreach ($result['records'] ?? [] as $row) {
                    // Org-shared records can be seen from several Sites.
                    $records[$row['kind'].'/'.$row['id']] = $row;
                }
                $coverage[$site->handle] = [
                    'live_identifier' => $result['live_covered'] ? 'covered' : 'unavailable',
                    'unattributed_revisions' => $result['history_indeterminate'],
                    'revision_fields' => 'captured_classification_only',
                    'other_relations_and_raw_values' => 'not_exported',
                ];
            }

            $context->forget()->setOrg($org);
            $readerData = null;
            if ($reader !== null) {
                $readerData = app(ReaderExport::class)->for($reader, reportCoverage: true);
                if ($readerData['account'] === []) {
                    $readerData['account'] = (object) [];
                }
                $readerData['scope'] = 'org';
            }

            $this->output->writeln(json_encode([
                'org' => $org->slug,
                'content' => array_values($records),
                'content_coverage' => (object) $coverage,
                'reader' => $readerData,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (InvalidArgumentException|EntitlementRefused $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (JsonException) {
            $this->error('Supply a JSON object on standard input. The export requires valid UTF-8.');

            return self::FAILURE;
        } finally {
            $context->forget();
        }
    }

    /** @return array<string, mixed> */
    private function readSubject(): array
    {
        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN;
        $line = fgets($stream, 16385);
        if (! is_string($line) || (strlen($line) === 16384 && ! str_ends_with($line, "\n"))) {
            throw new InvalidArgumentException('Supply the subject as one JSON line on standard input.');
        }

        $data = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_diff_key($data, ['identifier' => true, 'reader' => true]) !== []) {
            throw new InvalidArgumentException('The subject object has only identifier and reader keys.');
        }

        return $data;
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
