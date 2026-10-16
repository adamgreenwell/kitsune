<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Console;

use Illuminate\Console\Command;
use Kitsune\Commerce\Exports\ExportCursor;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/** Cold installed-data export: complete checked data on stdout, diagnostics on stderr. @internal */
final class ExportCommand extends Command
{
    protected $signature = 'kitsune:commerce-export
        {--org= : Exact Org slug to export}
        {--site= : Exact Site handle within that Org; omitted exports the Org}
        {--format=jsonl : jsonl or csv}
        {--limit=100 : Maximum orders in this page, from 1 through 100}
        {--cursor= : Continuation cursor from the previous page with the same scope and profile}
        {--include-billing : Explicitly include only policy-permitted retained billing fields}';

    protected $description = 'Download a bounded page of checked commerce financial history.';

    public function handle(Context $context, OrderHistoryExport $exports): int
    {
        $beforeOrg = $context->org();
        $beforeSite = $context->site();
        try {
            $slug = $this->option('org');
            $siteHandle = $this->option('site');
            $format = $this->option('format');
            $limit = $this->option('limit');
            $cursor = $this->option('cursor');
            if (! self::scope($slug) || ($siteHandle !== null && ! self::scope($siteHandle))
                || ! is_string($format) || ! in_array($format, ['jsonl', 'csv'], true)
                || ! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1 || (int) $limit > 100
                || ($cursor !== null && (! is_string($cursor) || $cursor === '' || strlen($cursor) > ExportCursor::MAX_BYTES))) {
                throw new RuntimeException;
            }
            $org = Org::query()->useWritePdo()->where('slug', $slug)->first() ?? throw new RuntimeException;
            $orgId = $org->getKey();
            if (! $org->getConnection()->table('orgs')->useWritePdo()->where('id', $orgId)->where('slug', $slug)->exists()) {
                throw new RuntimeException;
            }
            $context->forget()->setOrg($org);
            $siteId = null;
            if ($siteHandle !== null) {
                $site = Site::query()->useWritePdo()->where('org_id', $orgId)->where('handle', $siteHandle)->first()
                    ?? throw new RuntimeException;
                $siteId = $site->getKey();
                if (! $site->getConnection()->table('sites')->useWritePdo()->where('id', $siteId)->where('org_id', $orgId)->where('handle', $siteHandle)->exists()) {
                    throw new RuntimeException;
                }
                $context->setSite($site);
            }
            if ($context->orgId() !== $orgId || $context->siteId() !== $siteId) {
                throw new RuntimeException;
            }
            $page = $exports->forConsole($format, (int) $limit, $cursor, $this->option('include-billing') === true);
            $this->output->write($page->body, false, OutputInterface::OUTPUT_RAW);
            if ($page->nextCursor !== null) {
                $this->diagnostic('Next cursor: '.$page->nextCursor);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->diagnostic('Commerce export could not complete. Check its scope, format, cursor and retained custody before retrying.');

            return self::FAILURE;
        } finally {
            $context->forget();
            $beforeSite !== null ? $context->setSite($beforeSite) : $context->setOrg($beforeOrg);
        }
    }

    private static function scope(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
    }

    private function diagnostic(string $message): void
    {
        $output = $this->output->getOutput();
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new StreamOutput(fopen('php://stderr', 'w'));
        $error->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
