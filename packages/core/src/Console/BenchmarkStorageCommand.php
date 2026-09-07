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
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\DriverFactory;
use Kitsune\Core\Tenancy\Context;

/**
 * Spike #13 — the storage benchmark ADR-006 and ADR-001 both depend on.
 *
 * Drupal's join explosion took seven years to get filed (core issue #3022864:
 * 27+ joins, one production query at 697 seconds). The point of this command
 * is to find Kitsune's ceiling now rather than in year two.
 *
 * It deliberately measures with the write-amplifying decisions switched ON,
 * because a benchmark on an idealised table would answer the wrong question:
 *
 *   ADR-017  translation fans shared fields into every locale row
 *   ADR-016  one entries row per media asset
 *   ADR-006  every indexed field costs write throughput and disk
 */
final class BenchmarkStorageCommand extends Command
{
    protected $signature = 'kitsune:benchmark-storage
        {--rows=10000 : How many entries to generate}
        {--locales=1 : Locale count, to model ADR-017 row multiplication}
        {--indexed=0 : Generated columns to create before writing}
        {--keep : Leave the generated rows in place}';

    protected $description = 'Measure entry storage and query cost at scale (spike #13)';

    public function handle(): int
    {
        $rows = max(1, (int) $this->option('rows'));
        $locales = max(1, (int) $this->option('locales'));
        $indexed = max(0, (int) $this->option('indexed'));
        $total = $rows * $locales;

        $driver = DriverFactory::for(DB::connection());
        $this->line("engine: <info>{$driver->name()}</info>  rows: <info>{$total}</info> ({$rows} × {$locales} locales)  indexed columns: <info>{$indexed}</info>");

        [$org, $site, $type] = $this->fixture();

        for ($i = 0; $i < $indexed; $i++) {
            $column = "bench_idx_{$i}";

            if (! in_array($column, DB::getSchemaBuilder()->getColumnListing('entries'), true)) {
                // Ask the driver for the spelling — MySQL rejects NUMERIC
                // inside CAST and needs DECIMAL, which is exactly the kind of
                // divergence the caller must not have to know.
                DB::statement($driver->addGeneratedColumnSql('entries', $column, 'values', "f{$i}", $driver->sqlType('decimal')));
                DB::statement($driver->createIndexSql('entries', "entries_bench_{$i}", 'site_id', $column));
            }
        }

        $insert = $this->measure(fn () => $this->seed($org, $site, $type, $rows, $locales));

        $results = [
            ['count(*)', $this->measure(fn () => Entry::count())],
            ['list page, type + status', $this->measure(fn () => Entry::ofType('article')->published()->orderByDesc('published_at')->limit(25)->get())],
            ['slug lookup (unique index)', $this->measure(fn () => Entry::where('slug', 'bench-'.intdiv($rows, 2))->first())],
            ['title LIKE (no index)', $this->measure(fn () => Entry::where('title', 'like', '%500%')->limit(25)->get())],
        ];

        if ($indexed > 0) {
            $results[] = ['generated column range', $this->measure(fn () => Entry::whereBetween('bench_idx_0', [10, 200])->limit(25)->get())];
        }

        $this->newLine();
        $this->line(sprintf('  %-32s %10s', 'operation', 'ms'));
        $this->line('  '.str_repeat('─', 43));
        $this->line(sprintf('  %-32s %10.1f', "insert {$total} rows", $insert));

        foreach ($results as [$label, $ms]) {
            $flag = $ms > 200 ? ' ⚠️ over the 200ms Phase 4 target' : '';
            $this->line(sprintf('  %-32s %10.1f%s', $label, $ms, $flag));
        }

        $this->newLine();
        $this->line('  table bytes: <info>'.number_format($this->tableBytes($driver->name())).'</info>');

        if (! $this->option('keep')) {
            Entry::withoutScopeBecause('benchmark cleanup', fn ($q) => $q->where('slug', 'like', 'bench-%')->forceDelete());
        }

        return self::SUCCESS;
    }

    /** @return array{0: Org, 1: Site, 2: EntryType} */
    private function fixture(): array
    {
        $org = Org::firstOrCreate(['slug' => 'benchmark'], ['name' => 'Benchmark']);
        app(Context::class)->setOrg($org);

        $site = Site::firstOrCreate(['slug' => 'benchmark'], ['org_id' => $org->id, 'handle' => 'benchmark', 'name' => 'Benchmark']);
        app(Context::class)->setSite($site);

        $type = EntryType::firstOrCreate(
            ['org_id' => $org->id, 'handle' => 'article'],
            ['name' => 'Article', 'plural_name' => 'Articles'],
        );

        return [$org, $site, $type];
    }

    private function seed(Org $org, Site $site, EntryType $type, int $rows, int $locales): void
    {
        $now = now();
        $chunk = [];

        for ($i = 0; $i < $rows; $i++) {
            for ($l = 0; $l < $locales; $l++) {
                // ADR-017: shared fields are denormalised into every locale
                // row, so the payload is written in full each time rather
                // than referenced. That is the cost being measured.
                $chunk[] = [
                    'site_id' => $site->id,
                    'org_id' => $org->id,
                    'entry_type_id' => $type->id,
                    'type_handle' => 'article',
                    'status' => $i % 3 === 0 ? 'draft' : 'published',
                    'slug' => $locales === 1 ? "bench-{$i}" : "bench-{$i}-{$l}",
                    'title' => "Benchmark entry {$i}",
                    'values' => json_encode(['f0' => $i % 500, 'summary' => str_repeat('x', 120)]),
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($chunk) >= 500) {
                DB::table('entries')->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table('entries')->insert($chunk);
        }
    }

    private function measure(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (hrtime(true) - $start) / 1_000_000;
    }

    private function tableBytes(string $engine): int
    {
        try {
            return match ($engine) {
                'sqlite' => (int) DB::selectOne("SELECT SUM(pgsize) AS b FROM dbstat WHERE name='entries'")?->b,
                'pgsql' => (int) DB::selectOne("SELECT pg_total_relation_size('entries') AS b")?->b,
                'mysql' => (int) DB::selectOne('SELECT data_length + index_length AS b FROM information_schema.tables WHERE table_name = ?', ['entries'])?->b,
                default => 0,
            };
        } catch (\Throwable) {
            return 0; // dbstat is optional in SQLite builds
        }
    }
}
