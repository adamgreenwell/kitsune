<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use JsonException;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\ContentSubjectErasure;
use Kitsune\Core\Schema\RevisionErasure;
use Kitsune\Core\Schema\RevisionErasureJournal;
use Kitsune\Core\Schema\RevisionErasureRefused;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** @phpstan-import-type Coverage from ContentSubjectErasure */
final class RevisionErasureCommand extends Command
{
    protected $signature = 'kitsune:revision-erasure
        {action : redact, redact-subject, export-journal or replay}
        {--org= : The organisation slug}
        {--site= : The Site handle}
        {--all-sites : Redact the subject across every Site in the Org}
        {--entry= : The entry ID for redact}
        {--revision= : The revision ID for redact}
        {--storage= : The subject field storage ID for redact or redact-subject}
        {--type= : The entry type ID for redact-subject}
        {--include-live : Redact the live entry when it still matches this revision}
        {--force : Confirm erasure or replay}';

    protected $description = 'Erase witnessed content, save an authenticated journal, or replay it against a restore';

    public function handle(Context $context, EntitlementAuthority $authority, RevisionErasure $erasure, RevisionErasureJournal $journal, ContentSubjectErasure $subjects): int
    {
        $action = $this->argument('action');
        $slug = $this->option('org');
        $handle = $this->option('site');
        $allSites = $this->option('all-sites') === true;
        $oneSite = is_string($handle) && $handle !== '';
        if (! in_array($action, ['redact', 'redact-subject', 'export-journal', 'replay'], true)
            || ! is_string($slug) || $slug === '' || $allSites === $oneSite || ($allSites && $action !== 'redact-subject')) {
            $this->error('Choose an action with --org and --site. Redact-subject also accepts --all-sites.');

            return self::INVALID;
        }

        if ($action !== 'export-journal' && ! $this->option('force')) {
            $this->error('Erasure and replay require --force.');

            return self::INVALID;
        }

        try {
            $org = Org::withTrashed()->where('slug', $slug)->first()
                ?? throw new RevisionErasureRefused('The selected organisation is unavailable.');
            $context->forget()->setOrg($org);
            $authority->authorise(EntitlementRefused::FORGET, null);
            if ($allSites) {
                $sites = Site::query()->where('org_id', $org->id)->orderBy('id')->get();
                $results = $subjects->redactInSites($sites, $this->positiveOption('type'), $this->positiveOption('storage'), $this->identifier());
                $this->output->writeln(json_encode(['sites' => $results], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return array_all($results, self::coverageComplete(...)) ? self::SUCCESS : self::FAILURE;
            }

            $site = Site::query()->where('org_id', $org->id)->where('handle', $handle)->first()
                ?? throw new RevisionErasureRefused('The selected Site is unavailable.');
            $context->setSite($site);

            if ($action === 'export-journal') {
                $this->output->writeln($journal->export(), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            if ($action === 'replay') {
                $counts = $journal->replay($this->inputLine());
                $this->output->writeln(json_encode($counts, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return $counts['unavailable'] === 0 ? self::SUCCESS : self::FAILURE;
            }

            $storage = $this->positiveOption('storage');
            $identifier = $this->identifier();
            if ($action === 'redact-subject') {
                $counts = $subjects->redactInSites([$site], $this->positiveOption('type'), $storage, $identifier)[$site->handle];
                $this->output->writeln(json_encode($counts, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

                return self::coverageComplete($counts) ? self::SUCCESS : self::FAILURE;
            }

            $entry = $this->positiveOption('entry');
            $revision = $this->positiveOption('revision');
            $receipts = $erasure->erase($entry, $revision, $storage, $identifier, includeLive: $this->option('include-live') === true);
            $this->info(sprintf('%s fields erased: %d.', $this->option('include-live') === true ? 'Entry and revision' : 'Revision', count($receipts)));

            return self::SUCCESS;
        } catch (JsonException) {
            $this->error('Supply valid JSON for the typed subject identifier.');

            return self::FAILURE;
        } catch (RevisionErasureRefused|EntitlementRefused $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Revision erasure could not finish. Any previously verified receipts remain retryable.');

            return self::FAILURE;
        } finally {
            $context->forget();
        }
    }

    /** @param Coverage $counts */
    private static function coverageComplete(array $counts): bool
    {
        return $counts['live_identifier_covered']
            && $counts['unavailable_live_entries'] + $counts['indeterminate_revisions'] + $counts['refused_revisions'] === 0;
    }

    private function identifier(): bool|float|int|string
    {
        $value = json_decode($this->inputLine(), true, 2, JSON_THROW_ON_ERROR);
        if (! is_scalar($value)) {
            throw new RevisionErasureRefused('Supply a typed subject identifier as JSON on standard input.');
        }

        return $value;
    }

    private function positiveOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($value)) {
            throw new RevisionErasureRefused(sprintf('Supply a positive --%s ID.', $name));
        }

        return $value;
    }

    private function inputLine(): string
    {
        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN;
        $line = fgets($stream);
        if ($line === false) {
            throw new RevisionErasureRefused('Supply the typed subject identifier or journal on standard input.');
        }

        return rtrim($line, "\r\n");
    }
}
