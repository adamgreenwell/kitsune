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
use Kitsune\Core\Console\Concerns\LeavesNothingBehind;
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
    use LeavesNothingBehind;

    /** The start of every slug this command inserts; each run adds its own token after it — see LeavesNothingBehind. */
    private const SLUG_PREFIX = 'floor-';

    /**
     * How many entries this process inserted before it measured.
     *
     * ⚠️ NOT ZERO MEANS THE PEAK IS NOT A REQUEST'S. PHP keeps the heap an insert grew — `memory_reset_peak_usage()`
     * resets the recorded high-water mark to what the process currently holds, it does not hand arenas back — so
     * a run that seeded reports a peak that includes the seeding. Measured: 40.5 MB after seeding 100 entries,
     * 42.5 MB after 1,000 or 5,000, for a request that reads the same 25 rows each time. Codex found it on #126.
     * The figure is only a request's when the process that measured it did not seed, which is why
     * bin/benchmark-floor.sh seeds in one process and measures in another, and refuses a measurement that seeded.
     */
    private int $seededThisRun = 0;

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

        // ⚠️ BEFORE SEEDING, OR IT IS NOT THE BOOTSTRAP. Taken after ensureVolume(), this figure carried the
        // high-water mark of inserting the benchmark's own rows — so it grew with `--entries` and was reported
        // as the cost of booting the framework. The seeding is scaffolding for the measurement, not part of the
        // request being measured, and no worker ever does it.
        $bootstrap = memory_get_peak_usage(true);

        try {
            $seeded = $this->ensureVolume($org, $site, $type, max(0, (int) $this->option('entries')));

            $this->line("  content in scope: <info>{$seeded}</info> entries");
            $this->line("  seeded by this run: <info>{$this->seededThisRun}</info> entries");
            $this->newLine();

            // Resetting here leaves out the fixture lookup, so the peak below is the high-water mark of the
            // samples on top of a framework already resident — what a PHP-FPM worker holds. ⚠️ It cannot leave out
            // SEEDING: the reset moves the recorded mark down to what the process holds now, and PHP still holds
            // the heap an insert grew. That is what `$seededThisRun` is for, and why the harness never measures in
            // the process that seeded.
            memory_reset_peak_usage();

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
            $this->line(sprintf('  peak serving a request     %6.1f MB', $peak / 1_048_576));

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

            // Said on the run it concerns, not left to a docblock: a figure printed under "serving a request" that
            // includes the seeding is the one an operator is most likely to copy down.
            if ($this->seededThisRun > 0) {
                $this->newLine();
                $this->warn("  ⚠️ this run seeded {$this->seededThisRun} entries first, and PHP keeps the heap that grew, so the");
                $this->warn('  peak above includes the seeding. For a request alone, measure in a process that did not seed:');
                $this->warn('  run once with --keep to seed, then again with the entries in place.');
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
            /*
             * ⚠️ AND THE LIMITS ARE THE FLOOR CONSTANTS, NOT A SECOND COPY OF THEM. Written out as `--cpus=1
             * --memory=1g`, the recipe was a third place the floor lived, free to disagree with the two above
             * it — and an operator following a stale one would measure against a floor this code no longer
             * claims. FloorTest holds these constants, this hint and bin/benchmark-floor.sh to one number.
             */
            $this->line(sprintf('    docker run --rm --cpus=%d --memory=%dm \\', Kitsune::FLOOR_VCPU, $budgetMb));
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

    /**
     * Top the benchmark site up to the requested volume, and report what the measured queries can actually see.
     *
     * ⚠️ COUNTED THROUGH THE SCOPED MODEL AT THE END, NOT ECHOED BACK FROM THE ARGUMENT. This used to
     * `return $target` — the number it had just been passed — so "content in scope: 1000 entries" was the
     * request repeated, not an observation. Every caller that checked it, this command's own line and the test
     * named for the `WHERE 1 = 0` defect, was therefore comparing the option with itself and could not fail:
     * rows are inserted through the UNSCOPED query builder below, so a run that had lost its site context would
     * insert all 1,000, print all 1,000, and then time four empty result sets — which is exactly the
     * 2026-09-07 defect this was written to make impossible. Proven by removing `setSite()` and watching the
     * whole file still pass. `Entry::count()` goes through SiteScope, so it answers 0 when the samples will.
     */
    private function ensureVolume(Org $org, Site $site, EntryType $type, int $target): int
    {
        // ⚠️ SET ON EVERY RUN, NOT ONLY WHEN IT SEEDS. Laravel resolves a command once and `Artisan::call()` reuses
        // that instance, so a value set by one run survives into the next in the same process — measured: a run
        // that found its entries in place and inserted none reported the previous run's 25. `LeavesNothingBehind`
        // clears its `runToken` for the same reason.
        $this->seededThisRun = 0;

        $existing = Entry::query()->where('site_id', $site->getKey())->count();

        if ($existing >= $target) {
            return Entry::count();
        }

        $prefix = $this->runPrefix(self::SLUG_PREFIX);
        $this->seededThisRun = $target - $existing;

        $now = now();
        $rows = [];

        for ($i = $existing; $i < $target; $i++) {
            $rows[] = [
                'site_id' => $site->id,
                'org_id' => $org->id,
                'entry_type_id' => $type->id,
                'type_handle' => 'article',
                'status' => 'published',
                'slug' => $prefix.$i,
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

        return Entry::count();
    }

    /**
     * Remove what this run made, and nothing it found.
     *
     * ⚠️ AN ORG THIS RUN CREATED GOES WHOLE, and the database takes everything hung off it with it — the site,
     * the entry type, the inserted entries and the audit rows the fixture wrote. `forceDelete()`, because `Org`
     * soft-deletes and a trashed org is precisely the residue this exists to stop leaving — unless another run
     * has joined it, which `removeCreatedOrgUnlessJoined()` decides under a lock.
     *
     * ⚠️ AN ORG THAT WAS ALREADY THERE KEEPS EVERYTHING THIS RUN DID NOT ADD. Only rows carrying this run's token, a
     * site or a type this run created, are removed — the org may be somebody's, or a previous run's with `--keep`.
     */
    private function cleanUp(Org $org, Site $site, EntryType $type): void
    {
        $this->removeInserted($site, self::SLUG_PREFIX);

        if ($org->wasRecentlyCreated) {
            // Its site and type go with it — or, if another run has joined it, all three stay for that run.
            $this->removeCreatedOrgUnlessJoined($org);

            return;
        }

        if ($type->wasRecentlyCreated) {
            $type->delete();
        }

        if ($site->wasRecentlyCreated) {
            $site->delete();
        }
    }
}
