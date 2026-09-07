<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

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
 */
final class BenchmarkFloorCommand extends Command
{
    protected $signature = 'kitsune:benchmark-floor {--entries=1000 : Content volume to measure against}';

    protected $description = 'Measure memory and query cost against the ADR-027 resource floor (spike #14)';

    public function handle(): int
    {
        $budgetMb = Kitsune::FLOOR_MEMORY_MB;

        $this->line("floor: <info>{$budgetMb} MB</info> / <info>".Kitsune::FLOOR_VCPU.' vCPU</info> / <info>'.DB::connection()->getDriverName().'</info>');
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
        $this->line('  constrained hardware — reproduce with:</comment>');
        // Mount the REPOSITORY root, not the app: the skeleton resolves
        // kitsune/core through a symlinked path repository that points
        // outside its own directory, so mounting only the app breaks the
        // autoloader inside the container. Verified the hard way.
        $this->line('    docker run --rm --cpus=1 --memory=1g \\');
        $this->line('      -v "$PWD":/repo -w /repo/skeleton php:8.4-cli \\');
        $this->line('      php artisan kitsune:benchmark-floor');

        return self::SUCCESS;
    }
}
