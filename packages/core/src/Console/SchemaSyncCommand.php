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
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\SchemaManager;
use Throwable;

/**
 * Reconcile the generated columns on `entries` with `field_storage`.
 *
 * The repair path the schema engine needs because DDL implicitly commits on
 * MySQL: a row write and its schema change cannot be one transaction there,
 * so a failure between them leaves the two disagreeing. This is also what an
 * operator runs after restoring a dump taken mid-change, and what a
 * deployment runs after a migration that adds field storage rows directly.
 *
 * Read-only by default. Schema changes on a live `entries` table can lock it
 * (ADR-006), so `--force` is required to actually apply them rather than
 * being a convenience flag.
 */
final class SchemaSyncCommand extends Command
{
    protected $signature = 'kitsune:schema-sync {--force : Apply the changes rather than only reporting them}';

    protected $description = 'Reconcile generated columns on entries with field_storage (ADR-006, ADR-028)';

    public function handle(SchemaManager $manager): int
    {
        $this->line('engine: <info>'.DB::connection()->getDriverName().'</info>');
        $this->newLine();

        if (! $this->option('force')) {
            return $this->report();
        }

        try {
            $result = $manager->reconcile();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['added'] as $column) {
            $this->line("  <info>+</info> {$column}");
        }

        foreach ($result['dropped'] as $column) {
            $this->line("  <comment>-</comment> {$column}");
        }

        $changes = count($result['added']) + count($result['dropped']);

        $this->newLine();
        $this->line($changes === 0
            ? '<info>already in sync</info>'
            : "<info>{$changes}</info> change(s) applied");

        return self::SUCCESS;
    }

    /**
     * Say what would change, and say it in terms of the rows that want it.
     *
     * A bare column list is not actionable — ADR-028 shares one column
     * between every row that projects the same way, so "drop idx_price__number"
     * is only safe to read alongside how many rows still ask for it.
     */
    private function report(): int
    {
        $wanted = [];

        foreach (FieldStorage::query()->where('is_indexed', true)->get() as $storage) {
            $wanted[$storage->generatedColumnName()][] = $storage->handle;
        }

        $present = array_values(array_filter(
            DB::getSchemaBuilder()->getColumnListing('entries'),
            static fn (string $column): bool => str_starts_with($column, 'idx_'),
        ));

        $missing = array_diff(array_keys($wanted), $present);
        $orphaned = array_diff($present, array_keys($wanted));

        foreach ($missing as $column) {
            $rows = count($wanted[$column]);
            $this->line("  <info>+</info> {$column} — wanted by {$rows} field storage row(s), not present");
        }

        foreach ($orphaned as $column) {
            $this->line("  <comment>-</comment> {$column} — present, wanted by no field storage row");
        }

        $this->newLine();

        if ($missing === [] && $orphaned === []) {
            $this->line('<info>already in sync</info>');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d change(s) pending. Re-run with --force to apply. On a large entries table this '
            .'rewrites it and may lock; do it in a maintenance window.',
            count($missing) + count($orphaned),
        ));

        return self::SUCCESS;
    }
}
