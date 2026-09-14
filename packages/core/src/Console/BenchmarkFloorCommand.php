<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Console\Concerns\RemovesOnlyWhatItInserted;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Spike #14 — the resource-floor benchmark ADR-027 requires.
 *
 * ADR-027 sets the floor as a designed constraint rather than a readout:
 * 1 vCPU, 1 GB RAM, SQLite, no container runtime. A floor nobody measures
 * is a floor that quietly rises, so this is the measurement that holds it.
 *
 * Memory is the binding constraint at 1 GB and is the part that transfers
 * between machines: peak memory per request does not depend on how fast the
 * host is. Wall-clock here is indicative only — the honest way to test
 * timings at the floor is constrained hardware, and the command prints how.
 *
 * ⚠️ IT RUNS ON AN OPERATOR'S INSTALLATION, WHICH IS WHY IT SHIPS — AND WHY IT
 * LEAVES NOTHING BEHIND. ADR-027's floor is a claim a host can check on its own
 * hardware, so the command is in core rather than in the monorepo's tooling. It
 * used to leave its org, site, entry type and every inserted entry in the host's
 * database, with no prompt in production; it now asks there, and removes what it
 * made unless told to `--keep` it.
 */
final class BenchmarkFloorCommand extends Command
{
    use ConfirmableTrait;
    use RemovesOnlyWhatItInserted;

    /** Rows this command inserts, and — above the mark it takes — the only rows it removes. */
    private const SLUG_PREFIX = 'floor-';

    protected $signature = 'kitsune:benchmark-floor
        {--entries=1000 : Content volume to measure against}
        {--keep : Leave the benchmark org, its site and its entries in place}
        {--force : Run without asking when the application is in production}';

    protected $description = 'Measure memory and query cost against the ADR-027 resource floor (spike #14)';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $budgetMb = Kitsune::FLOOR_MEMORY_MB;

        $this->line("floor: <info>{$budgetMb} MB</info> / <info>".Kitsune::FLOOR_VCPU.' vCPU</info> / <info>'.DB::connection()->getDriverName().'</info>');
        $this->newLine();

        // Without a site context SiteScope adds WHERE 1 = 0, so every Entry
        // sample would measure an empty result set and report timings for
        // nothing at all — confidently. The same shape of mistake as the
        // storage benchmark's index probe.
        [$org, $site, $type] = $this->fixture();

        try {
            $seeded = $this->ensureVolume($org, $site, $type, max(0, (int) $this->option('entries')));

            $this->line("  content in scope: <info>{$seeded}</info> entries");
            $this->newLine();

            $bootstrap = memory_get_peak_usage(true);

            $samples = [
                'count entries' => fn () => Entry::count(),
                'list page (25 rows)' => fn () => Entry::query()->orderByDesc('id')->limit(25)->get(),
                'entry types for navigation' => fn () => EntryType::query()->orderBy('handle')->get(),
                'entry with relations' => fn () => Entry::query()->with(['entryType', 'revisions'])->first(),
            ];

            $rows = [];

            foreach ($samples as $label => $work) {
                $before = memory_get_usage(true);
                $start = hrtime(true);
                $work();
                $rows[] = [
                    $label,
                    (hrtime(true) - $start) / 1_000_000,
                    (memory_get_usage(true) - $before) / 1_048_576,
                ];
            }

            $peak = memory_get_peak_usage(true);

            $this->line(sprintf('  %-30s %9s %10s', 'operation', 'ms', 'ΔMB'));
            $this->line('  '.str_repeat('─', 51));

            foreach ($rows as [$label, $ms, $mb]) {
                $this->line(sprintf('  %-30s %9.1f %10.2f', $label, $ms, $mb));
            }

            $this->newLine();
            $this->line(sprintf('  framework bootstrap peak   %6.1f MB', $bootstrap / 1_048_576));
            $this->line(sprintf('  peak across all operations %6.1f MB', $peak / 1_048_576));

            // A single PHP-FPM worker is what has to fit; the floor must also
            // hold several concurrently alongside the OS and the database.
            $peakMb = $peak / 1_048_576;
            $workers = $peakMb > 0 ? (int) floor(($budgetMb * 0.5) / $peakMb) : 0;

            $this->newLine();
            $this->line("  workers that fit in half the floor: <info>{$workers}</info>");

            if ($peakMb > $budgetMb * 0.25) {
                $this->warn('  ⚠️ one request exceeds a quarter of the floor — that is a ceiling worth watching');
            } else {
                $this->info('  ✓ comfortable inside the floor for a single-site install');
            }

            $this->newLine();
            $this->line('  <comment>Wall-clock above is indicative only. Timings at the floor need');
            $this->line('  constrained hardware — reproduce from the application root with:</comment>');
            /*
             * ⚠️ THE APPLICATION ROOT, NOT A PATH THIS PACKAGE HAPPENS TO LIVE UNDER. The hint used to name the
             * monorepo's own layout (`/repo/skeleton`), which no host has. What does carry over is the trap
             * behind it: an install that resolves kitsune/core through a symlinked path repository needs the
             * symlink's target mounted too, or the autoloader breaks inside the container.
             */
            $this->line('    docker run --rm --cpus=1 --memory=1g \\');
            $this->line('      -v "$PWD":/app -w /app php:8.4-cli \\');
            $this->line('      php artisan kitsune:benchmark-floor');
            $this->line('  <comment>If kitsune/core is a symlinked path repository, mount its target as well.</comment>');

            return self::SUCCESS;
        } finally {
            if (! $this->option('keep')) {
                $this->cleanUp($org, $site, $type);
            }
        }
    }

    /**
     * The org, site and entry type to measure in, found or created.
     *
     * ⚠️ IN ONE TRANSACTION, because cleanup cannot begin until this returns. A site slug is unique across the
     * installation, so another org already owning `floor-benchmark` failed the site insert after the org was
     * created — and the org stayed.
     *
     * @return array{0: Org, 1: Site, 2: EntryType}
     */
    private function fixture(): array
    {
        return DB::transaction(static function (): array {
            $org = Org::firstOrCreate(['slug' => 'floor-benchmark'], ['name' => 'Floor benchmark']);
            app(Context::class)->setOrg($org);

            $site = Site::firstOrCreate(
                ['slug' => 'floor-benchmark'],
                ['org_id' => $org->id, 'handle' => 'floor-benchmark', 'name' => 'Floor benchmark'],
            );
            app(Context::class)->setSite($site);

            $type = EntryType::firstOrCreate(
                ['org_id' => $org->id, 'handle' => 'article'],
                ['name' => 'Article', 'plural_name' => 'Articles'],
            );

            return [$org, $site, $type];
        });
    }

    /** Top the benchmark site up to the requested volume. Returns the total in scope. */
    private function ensureVolume(Org $org, Site $site, EntryType $type, int $target): int
    {
        $existing = Entry::query()->where('site_id', $site->getKey())->count();

        if ($existing >= $target) {
            return $existing;
        }

        $this->markBeforeInserting();

        $now = now();
        $rows = [];

        for ($i = $existing; $i < $target; $i++) {
            $rows[] = [
                'site_id' => $site->id,
                'org_id' => $org->id,
                'entry_type_id' => $type->id,
                'type_handle' => 'article',
                'status' => 'published',
                'slug' => self::SLUG_PREFIX.$i,
                'title' => "Floor benchmark entry {$i}",
                'values' => json_encode(['summary' => str_repeat('x', 120)]),
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 500) {
                DB::table('entries')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('entries')->insert($rows);
        }

        return $target;
    }

    /**
     * Remove what this run made, and nothing it found.
     *
     * ⚠️ AN ORG THIS RUN CREATED GOES WHOLE, and the database takes everything hung off it with it — the site,
     * the entry type, the inserted entries and the audit rows the fixture wrote. `forceDelete()`, because `Org`
     * soft-deletes and a trashed org is precisely the residue this exists to stop leaving.
     *
     * ⚠️ AN ORG THAT WAS ALREADY THERE KEEPS EVERYTHING THIS RUN DID NOT ADD. Only rows above the id mark, a site
     * or a type this run created, are removed — the org may be somebody's, or a previous run's with `--keep`.
     */
    private function cleanUp(Org $org, Site $site, EntryType $type): void
    {
        if ($org->wasRecentlyCreated) {
            $org->forceDelete();

            return;
        }

        $this->removeInserted($site, self::SLUG_PREFIX);

        if ($type->wasRecentlyCreated) {
            $type->delete();
        }

        if ($site->wasRecentlyCreated) {
            $site->delete();
        }
    }
}
