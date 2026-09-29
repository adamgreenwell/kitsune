<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * What custody holds a lock for, on one engine — ADR-042 decision 5's measurement.
 *
 *     DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=kitsune_bench_withdrawal \
 *         DB_USERNAME=kitsune DB_PASSWORD=kitsune php bin/benchmark-media-withdrawal.php
 *
 *     DB_CONNECTION=sqlite DB_DATABASE=/tmp/kitsune_bench_withdrawal.sqlite php bin/benchmark-media-withdrawal.php
 *
 * ⚠️ IT DROPS AND REBUILDS THE DATABASE IT IS POINTED AT, and refuses any but one named exactly
 * `kitsune_bench_withdrawal` — for SQLite, a file of that name — `kitsune_bench` included, which is the shared-media
 * benchmark's. It refuses an in-memory database, which would measure no lock at all, and a production environment.
 *
 * ⚠️ AND IT WRITES MEDIA ONLY UNDER A DIRECTORY OF ITS OWN: the storage path is set before the application boots, to a
 * directory made for this run, and both media disks must resolve inside it or it stops. It removes the directory when
 * it finishes, and after a fatal error, Ctrl-C, Ctrl-\, SIGTERM or SIGHUP where pcntl is loaded (`withdrawalBenchCleanup()`);
 * any other signal, SIGKILL among them, leaves it.
 *
 * ⚠️ WHAT IS TIMED IS WHAT IS BUILT: custody as it runs — the copy written beside the path, read back and renamed; no
 * fsync; presence asked before any hash. A hold runs from the statement that takes the lock — the entry read `FOR
 * UPDATE` on PostgreSQL, MySQL and MariaDB, the first write on SQLite — to the outermost commit.
 *
 * ⚠️ A FIGURE IS PRINTED ONLY WHEN EVERY RUN VERIFIED, and a hold's only when its lock statement was seen. A case that
 * moves bytes verifies after each operation that both disks hold what they should, by SHA-256; a read-only listing, by
 * its child's own report and the limit it ran under — for prune, the reads of its pass and exactly the rows or names its
 * report lists; for reconcile, its exit code, the rows its summary counts under each label, and the copies it opened on
 * each disk, which say how many rows but not which; the migration's, by whether the index is there. A run that moved
 * nothing, moved the wrong thing or listed wrongly prints no number (review of slice 5c).
 *
 * One warm-up, then seven runs; the median and the maximum. Every figure is warm-cache. A case that moves bytes seeds
 * what each run moves afresh; a read-only listing, and the migration, run over one seed, written before the warm-up.
 * No threshold is proposed here: the numbers are for deciding on (ADR-042 decision 5).
 *
 * ~~⚠️ AT ADR-027'S FLOOR, GIVE IT MORE THAN PHP'S DEFAULT 128 MB — `php -d memory_limit=512M` — or it stops in (G'):
 * prune reads every media row at once, and over 100,000 rows that exhausts the default. The figure it then prints is
 * the peak prune reached; the stop is itself the finding (slice 5b).~~ ⚠️ AT ADR-027'S FLOOR, RUN IT AT PHP'S DEFAULT
 * 128 MB, which is the point: since slice 5c prune reads rows in batches (Adam, decision 11, 2026-09-26), and (G'), (N),
 * (N'), (O) and (O') completing is the pass — (O') the heaviest, the pass that asks the volume holding a key for every
 * name it claims (#155). ⚠️ EACH READ-ONLY LISTING RUNS IN A FRESH PHP PROCESS, as an operator runs it, AT
 * 128 MB WHATEVER THIS ONE RUNS AT — PHP's default, and the floor image's — so the pass is tested on every host, a
 * laptop whose CLI sets no limit included: in this process, the seed's hundred thousand rows leave the memory manager's
 * chunks half full, and the real size — what the limit is enforced on — read nearly twice what the command allocated
 * (slice 5c's first trial). The child times the command once the console application is built, reports its peak twice — what PHP allocated, bootstrap
 * included, and the real size — and the limit it ran under, which a figure verifies against; it writes its report to a
 * file of the run's, never into memory beside what it holds, and the report is read back a line at a time.
 *
 * `"--only=G',N,N',I,I',I''"` (quoted: the names carry primes) runs just the groups named — each by the name its first label
 * opens with — and no contention section; a name no group opens with refuses the run.
 *
 * ⚠️ SLICE 5B'S GROUPS (I, J, J', K, G', M) RUN AFTER EVERY 5A GROUP, EACH ON ITS OWN SEED, AND 5C'S (N, N', O, O'', O', I', I'') AFTER (K),
 * BEFORE THE TWO (M) GROUPS. Each clears every media row,
 * entry and file before it seeds and after it finishes, so what earlier groups left — byte-less rows among them — never
 * reaches a figure; and each is verified as ⚠️ A FIGURE IS PRINTED ONLY WHEN EVERY RUN VERIFIED says, above.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Console\MediaPruneCommand;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\Process;

/** The one database name this harness runs against. */
const WITHDRAWAL_BENCH_DATABASE = 'kitsune_bench_withdrawal';

/** What prune says of each extra copy the (N) groups seed: its row names the public disk, which holds its file. */
const WITHDRAWAL_BENCH_REMOVABLE = ' — live, its row names [public], it belongs on [public]: removed, once asked again under the lock';

/** What each read-only listing runs at: PHP's default `memory_limit`, which the floor image keeps (slice 5c). */
const WITHDRAWAL_BENCH_CHILD_LIMIT = '128M';

/**
 * Why the harness must not run here, or null. Asked before anything is touched.
 *
 * @param  array<string, string>  $mediaRoots  each media disk's `media/` directory, as the filesystem resolves it
 */
function withdrawalBenchRefusal(string $driver, string $database, string $environment, string $directory, array $mediaRoots): ?string
{
    if ($database === ':memory:' || str_contains($database, 'mode=memory')) {
        return 'an in-memory database holds no lock another connection could wait on, so there is nothing to measure';
    }

    $name = $driver === 'sqlite' ? pathinfo($database, PATHINFO_FILENAME) : $database;

    if ($name === 'kitsune_bench') {
        return '[kitsune_bench] is the shared-media benchmark\'s database, and this drops and rebuilds the one it is pointed at';
    }

    if ($name !== WITHDRAWAL_BENCH_DATABASE) {
        return sprintf('[%s] is not [%s], and this drops and rebuilds the database it is pointed at', $name, WITHDRAWAL_BENCH_DATABASE);
    }

    if ($environment === 'production') {
        return 'APP_ENV is production';
    }

    $inside = rtrim($directory, '/').'/';

    foreach ($mediaRoots as $disk => $root) {
        if (! str_starts_with($root, $inside)) {
            return sprintf('the [%s] disk writes media to [%s], outside this run\'s own directory [%s]', $disk, $root, $directory);
        }
    }

    return null;
}

/**
 * A figure's line — median and maximum in milliseconds — only when every run verified; null otherwise.
 *
 * @param  list<array{ms: float, verified: bool}>  $runs
 */
function withdrawalBenchFigure(string $label, array $runs): ?string
{
    if ($runs === [] || in_array(false, array_column($runs, 'verified'), true)) {
        return null;
    }

    $times = array_column($runs, 'ms');
    sort($times);
    $middle = intdiv(count($times), 2);
    $median = count($times) % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;

    return sprintf('| %s | %.1f | %.1f |', $label, $median, max($times));
}

/**
 * A figure no run verified: why, when a run stopped rather than listed wrongly — its first line, as a table cell holds it.
 *
 * @param  list<array{ms: float, verified: bool, stopped?: ?string}>  $runs
 */
function withdrawalBenchUnverified(string $label, array $runs): string
{
    $stopped = array_values(array_filter(array_column($runs, 'stopped')))[0] ?? null;

    return $stopped === null
        ? "| {$label} | not verified — no figure | |"
        : sprintf('| %s | not verified — no figure (%s) | |', $label, str_replace('|', '/', (string) strtok((string) $stopped, "\n")));
}

/**
 * The share of a run its holds took, in percent: the time from each lock statement to its commit, summed, over the
 * whole run — null when a hold never closed or nothing ran, so an unfinished run prints no share.
 *
 * @param  list<array{start: ?int, end: ?int}>  $holds  nanoseconds, from `hrtime()`
 */
function withdrawalBenchHeld(array $holds, float $wholeMs): ?float
{
    $taken = array_values(array_filter($holds, static fn (array $hold): bool => $hold['start'] !== null));

    if ($taken === [] || $wholeMs <= 0.0 || in_array(null, array_column($taken, 'end'), true)) {
        return null;
    }

    $held = array_sum(array_map(static fn (array $hold): float => ($hold['end'] - $hold['start']) / 1e6, $taken));

    return 100.0 * $held / $wholeMs;
}

/**
 * Whether a command's summary table counted exactly these rows under these labels — and none under any other.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 * @param  array<string, int>  $expected  label => rows
 */
function withdrawalBenchCounts(iterable $lines, array $expected): bool
{
    $counted = [];

    foreach ($lines as $line) {
        if (preg_match('/^\|\s*([a-z][a-z ]*[a-z])\s*\|\s*(\d+)\s*\|/', $line, $match) === 1 && $match[1] !== 'Label') {
            $counted[$match[1]] = (int) $match[2];
        }
    }

    ksort($counted);
    ksort($expected);

    return $counted === $expected;
}

/**
 * The exit code a read-only reconcile over this seed gives: 0 where it finds nothing — an extra copy, while every copy can
 * be read, is not a finding, and a run of settled rows has none — and 1 otherwise.
 *
 * @param  array<string, int>  $counts  label => rows
 */
function withdrawalBenchReconcileExit(array $counts): int
{
    return in_array(array_keys($counts), [[], ['extra']], true) ? 0 : 1;
}

/**
 * Whether a listing child's last line says it ran at the floor's limit and, for reconcile, opened as many copies on the
 * public disk, and as many on the served one, as there are rows held twice — none where there are none. A pass at another
 * limit is no pass at the floor, and a seed reconcile opens nothing of verifies no opening (review of slice 5c: both were
 * checked only where no test reached them). Counted per disk: a total passed a run that read the target's copy twice and
 * the served copy never (review of slice 5c). Whose copies they were, no count can say.
 *
 * @param  array<string, mixed>  $child
 * @param  array<string, int>  $counts  label => rows
 */
function withdrawalBenchChildVerified(array $child, string $command, array $counts): bool
{
    return ($child['limit'] ?? null) === WITHDRAWAL_BENCH_CHILD_LIMIT
        && ($command !== 'kitsune:media-reconcile' || ($child['opened'] ?? null) === ['public' => $counts['extra'] ?? 0, 'bench-served' => $counts['extra'] ?? 0]);
}

/**
 * On PostgreSQL, the tables a group seeds vacuumed and analysed before it is timed: each group deletes the last one's rows
 * and inserts its own, and the dead rows a hundred thousand deletes leave, which autovacuum clears when it chooses, slowed
 * the group that met them — a control with no orphan (O''), seeded after (O)'s 99,000 rows were deleted, read 5.7 s where
 * the same rows with orphans read 3.5; no run put the control first (review of #155's fix). Nothing on another engine:
 * none left the figures so.
 */
function withdrawalBenchSettleTables(string $driver, int $transactionLevel, Closure $statement): void
{
    // Inside a transaction VACUUM is refused, and the harness is never inside one: only a test calling it is.
    if ($driver !== 'pgsql' || $transactionLevel > 0) {
        return;
    }

    foreach (['media_files', 'entries', 'entry_relations'] as $table) {
        $statement('VACUUM ANALYZE '.$table);
    }
}

/**
 * Whether a query is the pass prune makes over the table to ask the volume whether a row's path reaches a listed name
 * (`MediaPruneCommand::reachedByRows()`): the one statement that selects exactly `id`, `entry_id`, `disk` and `path` from
 * `media_files`, unqualified, whatever the grammar quotes them with (review of #155's fix).
 */
function withdrawalBenchPassQuery(string $sql): bool
{
    return preg_match('/^select\W+id\W+,\W+entry_id\W+,\W+disk\W+,\W+path\W+from\W+media_files\W/i', $sql) === 1;
}

/**
 * Whether a prune listing child made exactly the pass's reads of the table — one per `MediaPruneCommand::BATCH` rows and a
 * last short or empty one — where its seed gives prune an orphan on a local disk, and none where it gives none: a figure
 * said to cost the pass is one the pass ran in (review of #155's fix), over every row and not only its first batch
 * (review of slice 5c).
 *
 * @param  array<string, mixed>  $child
 */
function withdrawalBenchPassVerified(array $child, int $expected): bool
{
    $passed = $child['passed'] ?? null;

    return is_int($passed) && $passed === $expected;
}

/**
 * Whether a read-only prune said it found no orphan and, given a count, closed on exactly that many removable extra
 * copies — its own summary, matched whole, so 11000 is not 1000. Read a line at a time.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 */
function withdrawalBenchPruneClosing(iterable $lines, ?int $removable): bool
{
    [$orphanless, $summary] = [false, $removable === null];
    $closing = $removable === null ? null : sprintf(' and %d removable extra cop%s listed and nothing removed.', $removable, $removable === 1 ? 'y' : 'ies');

    foreach ($lines as $line) {
        $orphanless = $orphanless || $line === 'No orphaned media files.';
        $summary = $summary || ($closing !== null && preg_match('/^\d+ orphaned files?, \d+ leftover partial cop(?:y|ies)'.preg_quote($closing, '/').'/', $line) === 1);
    }

    return $orphanless && $summary;
}

/**
 * Whether a read-only prune closed on exactly this many orphans, and no leftover partial copy or removable extra copy —
 * its own summary, matched from its start, so 11000 is not 1000. Read a line at a time.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 */
function withdrawalBenchOrphansClosing(iterable $lines, int $orphans): bool
{
    $closing = sprintf('%d orphaned file%s, 0 leftover partial copies and 0 removable extra copies listed and nothing removed.', $orphans, $orphans === 1 ? '' : 's');

    foreach ($lines as $line) {
        if (str_starts_with($line, $closing)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether prune listed exactly these orphans of one disk under its heading, a line each — none missing, none extra, none
 * twice, and the heading printed once. Read a line at a time.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 * @param  iterable<string>  $paths
 */
function withdrawalBenchOrphans(iterable $lines, string $disk, iterable $paths): bool
{
    $expected = [];

    foreach ($paths as $path) {
        $expected[sprintf('  [%s]  %s', $disk, $path)] = true;
    }

    [$found, $ended] = [false, false];

    foreach ($lines as $line) {
        if (str_starts_with($line, 'Orphaned media files')) {
            if ($found) {
                return false;
            }

            $found = true;

            continue;
        }

        if (! $found || $ended) {
            continue;
        }

        if (! str_starts_with($line, '  [')) {
            $ended = true;

            continue;
        }

        if (! isset($expected[$line])) {
            return false;
        }

        unset($expected[$line]);
    }

    return $found && $expected === [];
}

/**
 * Whether prune printed exactly these rows under a heading, a line each, as `MediaPruneCommand::rowLine()` writes them and
 * followed by what it says of each — no row missing, none extra, none twice, none under another entry, and the heading
 * printed once: a list printed again is not the list printed once, whatever it holds (review of slice 5c) — read a line
 * at a time. Rows under other headings are theirs: `withdrawalBenchOnlyList()` rules those lists out.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 * @param  iterable<array{0: int, 1: string, 2: string}>  $rows  entry, disk and path
 * @param  string  $verdict  what follows each row's line: an extra copy's, what --force would do with it
 */
function withdrawalBenchListed(iterable $lines, string $heading, iterable $rows, string $verdict = ''): bool
{
    $expected = [];

    foreach ($rows as $row) {
        $expected[MediaPruneCommand::rowLine((object) ['entry_id' => $row[0], 'disk' => $row[1], 'path' => $row[2]]).$verdict] = true;
    }

    [$found, $ended] = [false, false];

    foreach ($lines as $line) {
        if (str_starts_with($line, $heading)) {
            if ($found) {
                return false;
            }

            $found = true;

            continue;
        }

        if (! $found || $ended) {
            continue;
        }

        if (! str_starts_with($line, '  entry ')) {
            $ended = true;

            continue;
        }

        if (! isset($expected[$line])) {
            return false;
        }

        unset($expected[$line]);
    }

    // Every row expected was read under its heading — or, with no heading printed, none was expected.
    return $expected === [];
}

/**
 * Whether prune printed no list but this one — nor partial copies, copies a row reaches under another spelling, extra
 * copies, rows awaiting publication or rows trashed on a served disk under any other heading — so a figure is not verified
 * by a run that listed more than its seed. A heading no list opens with asks for no list at all.
 *
 * @param  iterable<string>  $lines  the report, a line each, without line endings
 */
function withdrawalBenchOnlyList(iterable $lines, string $heading): bool
{
    foreach ($lines as $line) {
        foreach (['Orphaned media files', 'Leftover partial copies', 'Copies a row reaches', 'Extra copies', 'Awaiting publication', 'Trashed on a served disk'] as $list) {
            if ($list !== $heading && str_starts_with($line, $list)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * A report file's lines, one at a time, without line endings: a hundred thousand of them are not read whole.
 *
 * @return Generator<int, string>
 */
function withdrawalBenchLinesOf(string $file): Generator
{
    $handle = fopen($file, 'rb');

    try {
        while (($line = fgets($handle)) !== false) {
            yield rtrim($line, "\r\n");
        }
    } finally {
        fclose($handle);
    }
}

/**
 * The names `--only` gives that no group's first label opens with — each of which would run nothing, silently.
 *
 * @param  list<string>  $firstLabels
 * @param  list<string>  $only
 * @return list<string>
 */
function withdrawalBenchUnknownOnly(array $firstLabels, array $only): array
{
    return array_values(array_filter($only, static fn (string $name): bool => ! withdrawalBenchSelectedAny($firstLabels, $name)));
}

/** @param  list<string>  $firstLabels */
function withdrawalBenchSelectedAny(array $firstLabels, string $name): bool
{
    foreach ($firstLabels as $label) {
        if (withdrawalBenchSelected([$label], [$name])) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a group is one `--only` names, by the name its first label opens with: every group when nothing is named.
 *
 * @param  list<string>  $labels
 * @param  list<string>|null  $only
 */
function withdrawalBenchSelected(array $labels, ?array $only): bool
{
    if ($only === null) {
        return true;
    }

    return preg_match('/^\(([^)]+)\) /', $labels[0] ?? '', $match) === 1 && in_array($match[1], $only, true);
}

/**
 * Whether an (M) contention row happened as its heading says: the holder, where there is one, held its row before the
 * build was armed, exited cleanly and said it committed after the build began; the build statement was seen; and the
 * prober reported.
 *
 * @param  array{held: bool, holder: ?array{exit: ?int, last: string}, built: bool, prober: array{exit: ?int, outcome: string}}  $seen
 */
function withdrawalBenchContentionVerified(array $seen): bool
{
    $holder = $seen['holder'];
    $said = $holder === null ? null : json_decode($holder['last'], true);

    return $seen['held']
        && ($holder === null || ($holder['exit'] === 0 && is_array($said) && ($said['outcome'] ?? null) === 'held until two seconds into the build'))
        && $seen['built']
        && $seen['prober']['exit'] === 0
        && ! str_starts_with($seen['prober']['outcome'], 'no result');
}

/**
 * An (M) contention row's line: its figures only when it happened as its heading says; otherwise what was seen, and none.
 *
 * @param  array{attempt: string, outcome: string, waited: float, statement: float, up: float, verified: bool}  $row
 */
function withdrawalBenchContentionLine(array $row): string
{
    return $row['verified']
        ? sprintf('| %s | %s | %.1f | %.1f | %.1f |', $row['attempt'], $row['outcome'], $row['waited'], $row['statement'], $row['up'])
        : sprintf('| %s | not verified — no figure (%s) | | | |', $row['attempt'], $row['outcome']);
}

/** @param  list<string>  $argv */
function withdrawalBenchMain(array $argv): int
{
    $rival = null;
    $child = null;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--rival=')) {
            $rival = substr($argument, 8);
        }

        if (str_starts_with($argument, '--child=')) {
            $child = substr($argument, 8);
            // A child runs in the parent's directory, as a rival does, and leaves it for the parent to remove.
            $rival = '';
        }
    }

    $directory = $rival === null
        ? sys_get_temp_dir().'/kitsune-bench-withdrawal-'.bin2hex(random_bytes(6))
        : (string) getenv('KITSUNE_BENCH_DIRECTORY');

    if ($rival === null) {
        // The storage the application expects, all of it here: its disks, and the framework's own caches and logs.
        foreach (['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $made) {
            if (! is_dir($directory.'/storage/'.$made) && ! mkdir($directory.'/storage/'.$made, 0700, true)) {
                fwrite(STDERR, "Refusing to measure: could not make [{$directory}/storage/{$made}].\n");

                return 1;
            }
        }
    }

    if ($rival === null) {
        withdrawalBenchCleanup($directory);
    }

    try {
        withdrawalBenchBoot($child, static function () use ($directory): void {
            $root = dirname(__DIR__).'/skeleton';
            require_once $root.'/vendor/autoload.php';
            $app = require $root.'/bootstrap/app.php';
            // Before the application boots, so every disk rooted in storage_path() — core's included — is rooted here.
            $app->useStoragePath($directory.'/storage');
            $app->make(Kernel::class)->bootstrap();
        });

        if ($child !== null) {
            return withdrawalBenchChild($child);
        }

        if ($rival !== null) {
            return withdrawalBenchRival($rival, $directory);
        }

        return withdrawalBenchRun($directory);
    } catch (Throwable $failure) {
        // A harness that fails must say so in its exit status, not only on the screen.
        fwrite(STDERR, 'The measurement stopped: '.$failure->getMessage().' ('.$failure->getFile().':'.$failure->getLine().")\n");

        return 1;
    } finally {
        if ($rival === null) {
            exec('rm -rf '.escapeshellarg($directory));
        }
    }
}

function withdrawalBenchRun(string $directory): int
{
    $driver = DB::connection()->getDriverName();
    $database = (string) DB::connection()->getDatabaseName();
    $config = app('config');

    // Read from the configuration, building nothing: building a local disk creates its root, wherever that is.
    $refusal = withdrawalBenchRefusal($driver, $database, (string) app()->environment(), (string) realpath($directory), [
        'public' => (string) MediaDisks::resolved($config, 'public')['root'].'media/',
        MediaDisks::PRIVATE => (string) MediaDisks::resolved($config, MediaDisks::PRIVATE)['root'].'media/',
    ]);

    if ($refusal !== null) {
        fwrite(STDERR, "Refusing to measure: {$refusal}.\n");

        return 1;
    }

    MediaDisks::refuseOverlaps($config);
    MediaDisks::refuseRedefinition($config);
    MediaDisks::refuseUnsafeMediaDisks($config);

    // A run's files are removed once it is verified: the most a run holds is ten 64 MiB files, a copy of each and a
    // partial, and the contention cases' one file and its copy — with room to spare.
    $planned = 4 * 10 * (64 << 20);

    if ((float) disk_free_space($directory) < $planned) {
        fwrite(STDERR, sprintf("Refusing to measure: [%s] has %.1f GB free, and a run writes up to %.1f GB.\n", $directory, disk_free_space($directory) / 1e9, $planned / 1e9));

        return 1;
    }

    $say = static function (string $line): void {
        echo $line, PHP_EOL;
    };

    $version = (string) (DB::selectOne($driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v ?? '');
    $say("# {$driver} {$version} — ".php_uname('s').' '.php_uname('m').', PHP '.PHP_VERSION.', memory_limit '.ini_get('memory_limit').' (each read-only listing: '.WITHDRAWAL_BENCH_CHILD_LIMIT.'), warm cache');

    if ($driver === 'sqlite') {
        $pdo = DB::connection()->getPdo();
        $say(sprintf('# journal_mode %s, busy_timeout %s ms, transaction_mode %s',
            $pdo->query('PRAGMA journal_mode')->fetchColumn(),
            $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
            (string) ($config->get('database.connections.sqlite.transaction_mode') ?? 'DEFERRED'),
        ));
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    withdrawalBenchSeed();

    if ($driver === 'sqlite' && in_array('--wal', $GLOBALS['argv'] ?? [], true)) {
        // Persistent in the file, so the rival process opens it in WAL too.
        DB::connection()->getPdo()->exec('PRAGMA journal_mode = WAL');
        $say('# journal_mode now '.DB::connection()->getPdo()->query('PRAGMA journal_mode')->fetchColumn().' (--wal)');
    }

    $bench = new WithdrawalBench($driver);
    $only = null;

    foreach ($GLOBALS['argv'] ?? [] as $argument) {
        if (str_starts_with((string) $argument, '--only=')) {
            $only = array_values(array_filter(array_map('trim', explode(',', substr((string) $argument, 7))), 'strlen'));
        }
    }

    if ($only !== null) {
        $unknown = withdrawalBenchUnknownOnly(array_map(static fn (array $group): string => (string) array_key_first($group['figures']), $bench->groups()), $only);

        if ($only === [] || $unknown !== []) {
            fwrite(STDERR, sprintf("Refusing to measure: --only names %s, which no group's first label opens with.\n", $only === [] ? 'nothing' : '['.implode('], [', $unknown).']'));

            return 1;
        }

        $say('# only: '.implode(', ', $only));
    }

    $say('');
    $say('## Holds (ms): median | max, seven runs after one warm-up');
    $say('| case | median | max |');
    $say('|---|---|---|');

    foreach ($bench->groups() as $group) {
        if (! withdrawalBenchSelected(array_keys($group['figures']), $only)) {
            continue;
        }

        foreach ($bench->measure($group) as $label => $runs) {
            $say(withdrawalBenchFigure($label, $runs) ?? withdrawalBenchUnverified($label, $runs));
        }
    }

    if ($only !== null) {
        return 0;
    }

    if ($driver === 'sqlite' || in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $say('');
        $say('## Contention: a second process, signalled when the lock is taken');
        $say('| rival attempt | outcome | waited ms | custody verified |');
        $say('|---|---|---|---|');

        foreach ($bench->contention($directory) as $row) {
            $say(sprintf('| %s | %s | %.1f | %s |', $row['attempt'], $row['outcome'], $row['waited'], $row['verified'] ? 'yes' : 'NO'));
        }

        $say('');
        $say('## Contention: a second process, signalled when a forced reconcile of a 64 MiB file trashed on the public disk takes its lock');
        $say('| rival attempt | outcome | waited ms | custody verified |');
        $say('|---|---|---|---|');

        foreach ($bench->contention($directory, 'reconcile') as $row) {
            $say(sprintf('| %s | %s | %.1f | %s |', $row['attempt'], $row['outcome'], $row['waited'], $row['verified'] ? 'yes' : 'NO'));
        }
    }

    // (M): over its own seed, as the (M) groups had it.
    [$before, $after] = $bench->migrationSeed();
    $before();

    try {
        $say('');
        $say($driver === 'sqlite'
            ? '## (M) the unique-path migration over 100,000 rows, with read-first writes throughout up()'
            : '## (M) the unique-path migration over 100,000 rows, its build behind a transaction that wrote a media_files row and commits two seconds into it');
        $say('| attempt | outcome | waited ms | the build statement ms | up() ms |');
        $say('|---|---|---|---|---|');

        foreach (withdrawalBenchMigrationContention($directory, $driver) as $row) {
            $say(withdrawalBenchContentionLine($row));
        }
    } finally {
        $after();
    }

    return 0;
}

/** The org, site and types every case uses, below every guard: a measurement, not content. */
function withdrawalBenchSeed(): void
{
    DB::table('orgs')->insert(['id' => 1, 'slug' => 'bench', 'name' => 'Bench']);
    DB::table('sites')->insert(['id' => 1, 'org_id' => 1, 'handle' => 'here', 'slug' => 'here', 'name' => 'Here', 'locale' => 'en']);
    DB::table('entry_types')->insert([
        ['id' => 1, 'org_id' => 1, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true],
        ['id' => 2, 'org_id' => 1, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'is_media' => false],
    ]);

    app(Context::class)->setOrg(Org::query()->findOrFail(1));
    app(Context::class)->setSite(Site::query()->findOrFail(1));
}

/**
 * The cases, and the clock that times each hold.
 */
final class WithdrawalBench
{
    /**
     * One record per outermost transaction, in the order they began: when its lock statement ran, and when it began to
     * commit. Callbacks that run after a commit — publication, the cleanup — begin transactions of their own before the
     * first one's `TransactionCommitted` fires, so a hold ends at `TransactionCommitting`, the moment before COMMIT.
     *
     * @var list<array{start: ?int, end: ?int}>
     */
    private array $holds = [];

    /** @var list<int> indices into `$holds` of the outermost transactions still open */
    private array $open = [];

    private bool $timing = false;

    /**
     * @var array<int, string> the residue rows the last seed wrote, entry => path — a hundred thousand of them, so held
     *                         as little as a row can be (review of slice 5c); for `orphan`, the orphans' paths, which no
     *                         entry has
     */
    private array $seeded = [];

    /** Every residue's checksum: the seed writes each one the same bytes. */
    private string $seededSum = '';

    /** What the last seed's residue rows are: `exposed`, `extra`, `awaiting`, `served`, `orphan`, `spelt` or `none`. */
    private string $seededKind = 'none';

    private string $lockPattern;

    /** The step each group's seed runs before it is timed: PostgreSQL's tables settled (`withdrawalBenchSettleTables()`). */
    private Closure $settle;

    public function __construct(private readonly string $driver)
    {
        $this->settle = static function (): void {
            withdrawalBenchSettleTables(DB::connection()->getDriverName(), DB::transactionLevel(), static fn (string $sql): bool => DB::statement($sql));
        };

        // The statement that takes the lock: the entry read FOR UPDATE, or on SQLite, the transaction's first write.
        $this->lockPattern = $driver === 'sqlite'
            ? '/^(update|delete from|insert into) "(entries|media_files)"/'
            : '/^select .* from .(entries|media_files). .*for update/';

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            if ($this->timing && $event->connection->transactionLevel() === 1) {
                $this->holds[] = ['start' => null, 'end' => null];
                $this->open[] = array_key_last($this->holds);
            }
        });

        DB::listen(function (QueryExecuted $query): void {
            if (! $this->timing || $this->open === [] || preg_match($this->lockPattern, strtolower($query->sql)) !== 1) {
                return;
            }

            $current = $this->open[array_key_last($this->open)];
            $this->holds[$current]['start'] ??= hrtime(true);
        });

        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            if ($this->timing && $this->open !== [] && $event->connection->transactionLevel() === 1) {
                $current = $this->open[array_key_last($this->open)];
                $this->holds[$current]['end'] ??= hrtime(true);
            }
        });

        $close = function (object $event): void {
            if ($this->timing && $this->open !== [] && $event->connection->transactionLevel() === 0) {
                array_pop($this->open);
            }
        };

        Event::listen(TransactionCommitted::class, $close);
        Event::listen(TransactionRolledBack::class, $close);
    }

    /**
     * Each group times one operation — a warm-up and seven runs — and reads several figures off the same runs: a hold
     * by its place in the order custody took them, the operation as a whole, or its peak memory.
     *
     * ⚠️ THE OPERATION ALONE IS TIMED — review. Each case is three steps: setup, which seeds what the run moves, or for a
     * listing names the file its child writes its report to — what that report must hold is the group's seed, written
     * before the warm-up — or for (M) drops the index; the operation; and a verification, which reads what the disks hold
     * by hash, or the listing's own report, or whether the index is there. Only the operation is inside the clock and the
     * memory reading, and each run's files are removed once verified, so a run's disk use never piles onto the next.
     *
     * @return list<array{figures: array<string, int|string>, case: array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}, runs?: int, before?: Closure(): void, after?: Closure(): void}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach (['8 KB' => 8 << 10, '200 KB' => 200 << 10, '4 MB' => 4 << 20, '64 MiB' => 64 << 20] as $size => $bytes) {
            $groups[] = ['figures' => ["(A) trash, {$size}: its hold" => 0, "(E) trash, {$size}: peak memory, MB" => 'memory'], 'case' => $this->trash($bytes)];
            $groups[] = ['figures' => ["(A') erase, {$size}: its hold" => 0, "(A') erase, {$size}: disposal's hold" => 1], 'case' => $this->erase($bytes)];
            $groups[] = ['figures' => [
                "(C) restore, {$size}: its hold" => 0,
                "(C) restore, {$size}: publication's hold" => 1,
                "(C) restore, {$size}: the cleanup's hold" => 2,
                "(C) restore, {$size}: end to end" => 'whole',
            ], 'case' => $this->restore($bytes, MediaDisks::PRIVATE)];
        }

        $groups[] = ['figures' => ['(A) control: trash a private file, 4 MB' => 0], 'case' => $this->trash(4 << 20, visibility: 'private')];
        $groups[] = ['figures' => ['(A) control: trash an article' => 0], 'case' => $this->trashArticle()];
        $groups[] = [
            'figures' => ['(C) restore onto the public disk from a former public disk, 4 MB: publication\'s hold' => 1],
            'case' => $this->restore(4 << 20, 'old-cdn'),
            'before' => static fn () => config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => storage_path('app/old-cdn'), 'url' => 'https://old-cdn.bench']]),
            'after' => static function (): void {
                config(['filesystems.disks.old-cdn' => null]);
                Storage::forgetDisk('old-cdn');
            },
        ];

        foreach ([[10, 200 << 10, '200 KB'], [100, 200 << 10, '200 KB'], [10, 4 << 20, '4 MB'], [100, 4 << 20, '4 MB'], [10, 64 << 20, '64 MiB']] as [$n, $bytes, $size]) {
            $groups[] = ['figures' => ["(B) bulk trash, {$n} × {$size}: its hold" => 0], 'case' => $this->bulk($n, $bytes)];
        }

        $groups[] = ['figures' => ['(B) bulk trash, 50 articles and 10 images of 200 KB: its hold' => 0], 'case' => $this->bulk(10, 200 << 10, articles: 50)];
        $groups[] = ['figures' => ['(F) a refused bulk trash, 10 × 4 MB, to the refusal with everything put back' => 'whole'], 'case' => $this->refusedBulk(10, 4 << 20)];
        // Seven runs, as the header says: the 5a figure was three (slice 5b's review).
        $groups[] = ['figures' => ['(G) prune --force: 1,000 orphans, 500 identical and 500 differing extra copies, about 10,000 rows' => 'whole'], 'case' => $this->prune()];

        foreach ([1, 3] as $extra) {
            [$before, $after] = $this->servedDisks($extra, 0);
            $groups[] = ['figures' => ["(H) trash, 4 MB, with {$extra} more served local disk(s): its hold" => 0], 'case' => $this->trash(4 << 20), 'before' => $before, 'after' => $after];
        }

        foreach ([1, 10, 50] as $delay) {
            [$before, $after] = $this->servedDisks(1, $delay);
            $groups[] = ['figures' => ["(H) trash, 4 MB, one served disk answering each presence check in {$delay} ms (synthetic): its hold" => 0], 'case' => $this->trash(4 << 20), 'before' => $before, 'after' => $after];
        }

        // Slice 5b: kitsune:media-reconcile, prune's removal of extra copies, and the migration making paths unique.
        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 1 << 10, 'exposed'));
        $groups[] = ['figures' => ['(I) reconcile, read-only: 100,000 rows, 1,000 findings' => 'whole', '(I) reconcile, read-only: peak memory, MB' => 'memory', '(I) reconcile, read-only: peak real memory, MB' => 'real memory'], 'case' => $this->listing('kitsune:media-reconcile', ['exposed' => 1_000]), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 1 << 10, 'exposed'));
        // Its memory too: ~~prune reads every row at once, where reconcile reads them in chunks (found at the floor, slice
        // 5b)~~ prune reads rows in batches since slice 5c, and this group completing at the floor's 128 MB is the pass.
        $groups[] = ['figures' => ['(G\') prune, read-only: 100,000 rows' => 'whole', '(G\') prune, read-only: peak memory, MB' => 'memory', '(G\') prune, read-only: peak real memory, MB' => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

        foreach (['4 MB' => 4 << 20, '64 MiB' => 64 << 20] as $size => $bytes) {
            foreach (['R1: live public, only on the private disk', 'R3: awaiting publication', 'R4: a differing private copy', 'R6: trashed on the public disk', 'R7: private, on a legacy disk'] as $kind) {
                [$before, $after] = $this->isolated(static fn (): null => null, legacy: true);
                $groups[] = ['figures' => [
                    "(J) reconcile --force, {$kind}, {$size}: settle's hold" => 0,
                    "(J) reconcile --force, {$kind}, {$size}: the cleanup's hold" => 1,
                ], 'case' => $this->residue(substr($kind, 0, 2), $bytes), 'before' => $before, 'after' => $after];
            }
        }

        // Measured as 5b measured it: in this process, its report buffered, the peak PHP allocated — beside 5b's figure, not
        // a read-only listing's from a child.
        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 200 << 10, 'exposed'));
        $groups[] = ['figures' => [
            '(J\') reconcile --force: 100,000 rows, 1,000 trashed on the public disk, 200 KB' => 'whole',
            '(J\') reconcile --force: the share of the run holding a lock, %' => 'held',
            '(J\') reconcile --force: peak memory, MB' => 'memory',
        ], 'case' => $this->atScale(), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(static fn (): null => null);
        $groups[] = ['figures' => ['(K) prune\'s removal of an extra copy, 64 MiB: its hold' => 0], 'case' => $this->extraCopy(64 << 20), 'before' => $before, 'after' => $after];

        /*
         * Slice 5c: what prune holds is what it lists, so its memory is measured where it lists the most — every file an
         * extra copy (N), and every row awaiting publication (N'), at two sizes for the slope — and reconcile's opening of
         * each copy of a file held twice (I') beside (I''): the same rows settled, the same served disk asked of each,
         * its media/ tree the same, and no findings, so neither run asks its findings again at the end (Adam, decisions 11 and 12, 2026-09-26). (I')
         * − (I'') is the whole cost of 1,000 rows held twice — their 2,000 opens, their lines and the served disk's 1,000
         * presence hits, and the check whether that disk nests with the target, asked once a pair since it cost as much as
         * the opens asked before each (review of slice 5c) — not the opens alone, which a whole run cannot resolve. Since
         * review round 25 a presence check that finds nothing on a local disk also asks why — a second stat, posix_access()'s
         * lookup and its access(2) — so (I'')'s 1,000 misses on the served disk cost more than the hits (I') has in their
         * place, some 6 ms a thousand on the laptop: (I') − (I'') is the opens, the lines and the nesting check less that.
         * Beside (I), (I') also asks one more disk of every row, which cost some 40% of the run (review of slice 5c, twice).
         */
        foreach ([10_000 => '10,000', 100_000 => '100,000'] as $rows => $size) {
            [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(0, $rows, 1 << 10, 'extra'));
            $groups[] = ['figures' => ["(N) prune, read-only: {$size} rows, each with an extra copy" => 'whole', "(N) prune, read-only, {$size} extra copies: peak memory, MB" => 'memory', "(N) prune, read-only, {$size} extra copies: peak real memory, MB" => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

            [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(0, $rows, 1 << 10, 'awaiting'));
            $groups[] = ['figures' => ["(N') prune, read-only: {$size} rows awaiting publication" => 'whole', "(N') prune, read-only, {$size} awaiting: peak memory, MB" => 'memory', "(N') prune, read-only, {$size} awaiting: peak real memory, MB" => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];
        }

        /*
         * After #155 (Codex): a local disk that lists an orphan asks, for every row's path, whether it reaches a listed
         * name — one more pass over the table and a stat of each row's path — so it is measured where it runs, 1,000
         * orphans beside 99,000 rows (O), against the same rows with no orphan and so no pass (O''), in the same run; and
         * where it claims every file, 100,000 rows each spelt as the disks read another (O'), holding a key for each.
         */
        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 1 << 10, 'orphan'));
        $groups[] = ['figures' => ['(O) prune, read-only: 99,000 rows where they belong, and 1,000 orphans' => 'whole', '(O) prune, read-only, 1,000 orphans: peak memory, MB' => 'memory', '(O) prune, read-only, 1,000 orphans: peak real memory, MB' => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 0, 1 << 10, 'none'));
        $groups[] = ['figures' => ["(O'') control: (O)'s 99,000 rows, no orphan and so no pass" => 'whole', "(O'') control: peak memory, MB" => 'memory', "(O'') control: peak real memory, MB" => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(0, 100_000, 1 << 10, 'spelt'));
        $groups[] = ['figures' => ["(O') prune, read-only: 100,000 rows each spelt as the disks read another" => 'whole', "(O') prune, read-only, 100,000 spelt otherwise: peak memory, MB" => 'memory', "(O') prune, read-only, 100,000 spelt otherwise: peak real memory, MB" => 'real memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

        // Held twice where reconcile calls it `extra` and opens both copies: on the public disk and on a served one — a copy on
        // core's private disk is a `private copy`, a finding opened by nothing (review of slice 5c).
        [$before, $after] = $this->withBenchServed($this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 1 << 10, 'served')));
        $groups[] = [
            'figures' => ['(I\') reconcile, read-only: 100,000 rows, 1,000 held twice, both copies of each opened' => 'whole', '(I\') reconcile, read-only, 1,000 held twice: peak memory, MB' => 'memory', '(I\') reconcile, read-only, 1,000 held twice: peak real memory, MB' => 'real memory'],
            'case' => $this->listing('kitsune:media-reconcile', ['extra' => 1_000]), 'before' => $before, 'after' => $after,
        ];

        [$before, $after] = $this->withBenchServed($this->isolated(fn (): mixed => $this->seedRows(100_000, 0, 1 << 10, 'none')));
        $groups[] = [
            'figures' => ['(I\'\') control: (I\')\'s 100,000 rows settled, its served disk empty, no findings' => 'whole', '(I\'\') control: peak memory, MB' => 'memory', '(I\'\') control: peak real memory, MB' => 'real memory'],
            'case' => $this->listing('kitsune:media-reconcile', []), 'before' => $before, 'after' => $after,
        ];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(100_000, 0, 0, 'none'));
        $groups[] = ['figures' => ['(M) the unique-path migration\'s check alone: 100,000 rows' => 'whole'], 'case' => $this->migrationCheck(), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(100_000, 0, 0, 'none'));
        $groups[] = ['figures' => ['(M) the unique-path migration\'s up(), check and build: 100,000 rows' => 'whole'], 'case' => $this->migrationUp(), 'before' => $before, 'after' => $after];

        return $groups;
    }

    /**
     * A warm-up, then the runs; each figure read off every run.
     *
     * @param  array{figures: array<string, int|string>, case: array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}, runs?: int, before?: Closure(): void, after?: Closure(): void}  $group
     * @return array<string, list<array{ms: float, verified: bool}>>
     */
    public function measure(array $group): array
    {
        $results = array_fill_keys(array_keys($group['figures']), []);
        $runs = $group['runs'] ?? 7;
        ['setup' => $setup, 'run' => $run, 'verify' => $verify] = $group['case'];

        if (isset($group['before'])) {
            ($group['before'])();
        }

        try {
            for ($i = 0; $i <= $runs; $i++) {
                $state = $setup();
                $this->holds = [];
                $this->open = [];
                memory_reset_peak_usage();
                $this->timing = true;
                $started = hrtime(true);
                $failed = false;
                $stopped = null;

                try {
                    $run($state);
                } catch (Throwable $failure) {
                    $failed = true;
                    // Kept, so a figure that could not be verified says why — a stop is not a wrong listing.
                    $stopped = $failure->getMessage();
                }

                $ended = hrtime(true);
                $this->timing = false;
                $peak = memory_get_peak_usage();
                $real = memory_get_peak_usage(true);
                $verified = ! $failed && $verify($state);
                $this->removeFiles();

                if ($i === 0) {
                    continue;
                }

                foreach ($group['figures'] as $label => $figure) {
                    // The transactions that took a lock, in order: the others — a read, a no-op — are not holds.
                    $holds = array_values(array_filter($this->holds, static fn (array $hold): bool => $hold['start'] !== null));
                    $hold = is_int($figure) ? ($holds[$figure] ?? null) : null;
                    // A case run in a child reports its own: the command's time, and the child's memory.
                    $child = $state['child'] ?? null;
                    $value = match (true) {
                        $child !== null && $figure === 'whole' => $child['ms'],
                        $child !== null && $figure === 'memory' => $child['peak'] / (1 << 20),
                        $child !== null && $figure === 'real memory' => $child['real'] / (1 << 20),
                        $figure === 'whole' => ($ended - $started) / 1e6,
                        $figure === 'held' => withdrawalBenchHeld($this->holds, ($ended - $started) / 1e6),
                        $figure === 'memory' => $peak / (1 << 20),
                        // What `memory_limit` is enforced on: the memory manager's chunks, not the bytes in them.
                        $figure === 'real memory' => $real / (1 << 20),
                        $hold !== null && $hold['end'] !== null => ($hold['end'] - $hold['start']) / 1e6,
                        default => null,
                    };

                    $results[$label][] = ['ms' => $value ?? 0.0, 'verified' => $verified && $value !== null, 'stopped' => $stopped];
                }
            }
        } finally {
            if (isset($group['after'])) {
                ($group['after'])();
            }
        }

        return $results;
    }

    /** Every file a run left under media/ on the disks the cases write to — the prune case's seeded ones excepted. */
    private function removeFiles(): void
    {
        foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $disk) {
            if ($disk === 'old-cdn' && config('filesystems.disks.old-cdn') === null) {
                continue;
            }

            foreach (['media/1/2026/09', 'media/1/2026/10'] as $directory) {
                Storage::disk($disk)->deleteDirectory($directory);
            }
        }
    }

    /** A file of fresh random bytes on a disk, and its entry, seeded below every guard. @return array{0: int, 1: string, 2: string} */
    private function file(int $bytes, string $visibility = 'public', string $disk = 'public', bool $trashed = false, string $month = '09'): array
    {
        $path = sprintf('media/1/2026/%s/%s.bin', $month, bin2hex(random_bytes(16)));
        $content = random_bytes(min($bytes, 1 << 20));
        $stream = fopen('php://temp', 'w+b');

        for ($written = 0; $written < $bytes; $written += strlen($content)) {
            fwrite($stream, substr($content, 0, min(strlen($content), $bytes - $written)));
        }

        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        $checksum = hash_final($hash);
        rewind($stream);
        $target = $visibility === 'private' && $disk === 'public' ? MediaDisks::PRIVATE : $disk;

        if (! Storage::disk($target)->writeStream($path, $stream)) {
            throw new RuntimeException("Could not seed [{$target}:{$path}].");
        }

        fclose($stream);

        $id = (int) DB::table('entries')->insertGetId([
            'site_id' => 1, 'org_id' => 1, 'entry_type_id' => 1, 'type_handle' => 'image', 'status' => 'published',
            'slug' => bin2hex(random_bytes(6)), 'title' => 'Bench', 'deleted_at' => $trashed ? now() : null,
        ]);
        DB::table('media_files')->insert([
            'entry_id' => $id, 'disk' => $visibility === 'private' ? MediaDisks::PRIVATE : $disk, 'path' => $path,
            'mime' => 'application/octet-stream', 'size_bytes' => $bytes, 'checksum' => $checksum, 'visibility' => $visibility,
            'created_at' => now(),
        ]);

        return [$id, $path, $checksum];
    }

    private function holds(string $disk, string $path, ?string $checksum): bool
    {
        $file = Storage::disk($disk)->path($path);

        return $checksum === null ? ! is_file($file) : is_file($file) && hash_file('sha256', $file) === $checksum;
    }

    private function article(): int
    {
        return (int) DB::table('entries')->insertGetId([
            'site_id' => 1, 'org_id' => 1, 'entry_type_id' => 2, 'type_handle' => 'article', 'status' => 'published',
            'slug' => bin2hex(random_bytes(6)), 'title' => 'Bench',
        ]);
    }

    /** @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool} */
    private function trash(int $bytes, string $visibility = 'public'): array
    {
        return [
            'setup' => fn (): array => ['file' => $this->file($bytes, $visibility)],
            'run' => static fn (array $state) => Entry::query()->findOrFail($state['file'][0])->delete(),
            'verify' => function (array $state) use ($visibility): bool {
                [, $path, $checksum] = $state['file'];

                return $visibility === 'private'
                    ? $this->holds(MediaDisks::PRIVATE, $path, $checksum)
                    : $this->holds('public', $path, null) && $this->holds(MediaDisks::PRIVATE, $path, $checksum);
            },
        ];
    }

    /** @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool} */
    private function trashArticle(): array
    {
        return [
            'setup' => fn (): array => ['id' => $this->article()],
            'run' => static fn (array $state) => Entry::query()->findOrFail($state['id'])->delete(),
            'verify' => static fn (array $state): bool => DB::table('entries')->where('id', $state['id'])->value('deleted_at') !== null,
        ];
    }

    /** @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool} */
    private function erase(int $bytes): array
    {
        return [
            'setup' => fn (): array => ['file' => $this->file($bytes)],
            'run' => static fn (array $state) => Entry::query()->findOrFail($state['file'][0])->forceDelete(),
            'verify' => fn (array $state): bool => $this->holds('public', $state['file'][1], null)
                && $this->holds(MediaDisks::PRIVATE, $state['file'][1], null)
                && ! DB::table('entries')->where('id', $state['file'][0])->exists(),
        ];
    }

    /** @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool} */
    private function restore(int $bytes, string $from): array
    {
        return [
            'setup' => fn (): array => ['file' => $this->file($bytes, 'public', $from, trashed: true)],
            'run' => static fn (array $state) => Entry::withTrashed()->findOrFail($state['file'][0])->restore(),
            // Published, moved off the disk it was on, and — the cleanup — no private copy left behind.
            'verify' => function (array $state) use ($from): bool {
                [$id, $path, $checksum] = $state['file'];

                return $this->holds('public', $path, $checksum)
                    && DB::table('media_files')->where('entry_id', $id)->value('disk') === 'public'
                    && $this->holds(MediaDisks::PRIVATE, $path, null)
                    && $this->holds($from, $path, null);
            },
        ];
    }

    /** @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool} */
    private function bulk(int $n, int $bytes, int $articles = 0): array
    {
        return [
            'setup' => function () use ($n, $bytes, $articles): array {
                $files = [];

                for ($i = 0; $i < $n; $i++) {
                    $files[] = $this->file($bytes);
                }

                $ids = array_column($files, 0);

                for ($i = 0; $i < $articles; $i++) {
                    $ids[] = $this->article();
                }

                return ['files' => $files, 'ids' => $ids];
            },
            'run' => static fn (array $state) => Entry::query()->whereKey($state['ids'])->delete(),
            'verify' => function (array $state): bool {
                foreach ($state['files'] as [, $path, $checksum]) {
                    if (! $this->holds('public', $path, null) || ! $this->holds(MediaDisks::PRIVATE, $path, $checksum)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /**
     * A bulk trash whose last file's public copy cannot be removed: refused, and everything moved put back. The directory
     * holding that one file is made unwritable — which root ignores, so a run as root verifies nothing and prints nothing.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function refusedBulk(int $n, int $bytes): array
    {
        return [
            'setup' => function () use ($n, $bytes): array {
                $files = [];

                for ($i = 0; $i < $n - 1; $i++) {
                    $files[] = $this->file($bytes);
                }

                $files[] = $pinned = $this->file($bytes, month: '10');
                $directory = dirname(Storage::disk('public')->path($pinned[1]));
                chmod($directory, 0555);

                return ['files' => $files, 'directory' => $directory, 'refused' => false];
            },
            'run' => static function (array &$state): void {
                try {
                    Entry::query()->whereKey(array_column($state['files'], 0))->delete();
                } catch (Throwable) {
                    $state['refused'] = true;
                }
            },
            'verify' => function (array $state): bool {
                chmod($state['directory'], 0755);

                if (! $state['refused']) {
                    return false;
                }

                foreach ($state['files'] as [$id, $path, $checksum]) {
                    if (! $this->holds('public', $path, $checksum) || DB::table('entries')->where('id', $id)->value('deleted_at') !== null) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /**
     * 1,000 orphans and 1,000 extra copies to remove — half identical to the public copy, half differing from it — and
     * about 10,000 rows to list; every orphan and extra copy verified gone, and every public copy still there.
     *
     * ⚠️ THE EXTRA COPIES ARE WRITTEN FOR EVERY RUN (review of slice 5b). Prune removes them now, so copies written once
     * would leave the timed runs nothing to remove, and a figure for a removal that never happened.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function prune(): array
    {
        $kept = [];

        return [
            'setup' => function () use (&$kept): array {
                if ($kept === []) {
                    for ($i = 0; $i < 1000; $i++) {
                        [, $path, $checksum] = $this->file(1 << 10, month: '11');
                        $kept[] = [$path, $checksum];
                    }

                    $rows = [];

                    for ($i = 0; $i < 9000; $i++) {
                        $rows[] = ['site_id' => 1, 'org_id' => 1, 'entry_type_id' => 1, 'type_handle' => 'image', 'status' => 'published', 'slug' => 'p'.$i, 'title' => 'Bench', 'deleted_at' => $i % 10 === 0 ? now() : null];
                    }

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('entries')->insert($chunk);
                    }
                }

                foreach ($kept as $i => [$path]) {
                    Storage::disk(MediaDisks::PRIVATE)->put($path, $i < 500 ? (string) Storage::disk('public')->get($path) : 'a differing copy');
                }

                for ($i = 0; $i < 1000; $i++) {
                    Storage::disk(MediaDisks::PRIVATE)->put(sprintf('media/1/2026/08/orphan-%d.bin', $i), 'an orphan');
                }

                return [];
            },
            'run' => static fn () => Artisan::call('kitsune:media-prune', ['--force' => true]),
            'verify' => function () use (&$kept): bool {
                if (Storage::disk(MediaDisks::PRIVATE)->files('media/1/2026/08') !== []) {
                    return false;
                }

                foreach ($kept as [$path, $checksum]) {
                    if (! $this->holds('public', $path, $checksum) || Storage::disk(MediaDisks::PRIVATE)->exists($path)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /**
     * A group's own seed, and nothing left before or after it: every media row, entry and file cleared, then seeded.
     *
     * ⚠️ CLEARED BOTH WAYS. The 5a groups remove their files and never their rows, so thousands of byte-less rows would
     * otherwise be listed by the reconcile groups as missing; and a 100,000-row seed left behind would slow every
     * group after it. With `$legacy`, a local disk nothing configures as a media disk, for ADR-041's `local` rows.
     *
     * @param  Closure(): mixed  $seed
     * @return array{0: Closure(): void, 1: Closure(): void}
     */
    private function isolated(Closure $seed, bool $legacy = false): array
    {
        $clear = function (): void {
            DB::table('media_files')->delete();
            DB::table('entry_relations')->delete();
            DB::table('entries')->delete();

            foreach (['public', MediaDisks::PRIVATE, 'legacy', 'bench-served'] as $disk) {
                if (in_array($disk, ['public', MediaDisks::PRIVATE], true) || config("filesystems.disks.{$disk}") !== null) {
                    Storage::disk($disk)->deleteDirectory('media');
                }
            }

            // What the last seed left in memory goes with it: a hundred thousand residues, beside the next seed's.
            $this->seeded = [];
            $this->seededKind = 'none';
        };

        return [
            function () use ($clear, $seed, $legacy): void {
                if ($legacy) {
                    config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => storage_path('app/legacy')]]);
                }

                $clear();
                $seed();
                ($this->settle)();
            },
            function () use ($clear, $legacy): void {
                $clear();

                if ($legacy) {
                    config(['filesystems.disks.legacy' => null]);
                    Storage::forgetDisk('legacy');
                }
            },
        ];
    }

    /**
     * Rows at scale, below every guard: `$ok` live public files each on the public disk, where their rows say, and
     * `$residue` files trashed while still on it — the residue slice 5a leaves from before it — spread over a hundred
     * directories. Written straight to the disk's root: a hundred thousand writes through Flysystem would time the seed.
     * Slice 5c's residues: `extra`, live public files on the public disk with a second copy on core's private disk, which
     * prune lists and reconcile calls a `private copy`; `served`, the second copy on a served disk instead, which reconcile
     * calls `extra` and opens; and `awaiting`, live public files whose rows name core's private disk, which holds them.
     * After #155 (Codex): `orphan`, files on core's private disk no row names; and `spelt`, live public files whose rows
     * spell their paths with a doubled slash, which the disks read as the file's own path.
     *
     * ⚠️ FIVE HUNDRED ROWS AT A TIME, holding none of them after (review of slice 5c): a list of every row to insert
     * held more than the floor's 128 MB beside the residues of the seed before it.
     */
    private function seedRows(int $ok, int $residue, int $residueBytes, string $kind): void
    {
        $root = rtrim(Storage::disk('public')->path(''), '/');
        $private = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
        $served = storage_path('app/bench-served');
        $small = random_bytes(1 << 10);
        $large = $residueBytes > 0 ? random_bytes($residueBytes) : '';
        $smallSum = hash('sha256', $small);
        $largeSum = $large === '' ? $smallSum : hash('sha256', $large);
        $this->seeded = [];
        $this->seededSum = $largeSum;
        $this->seededKind = $kind;

        for ($from = 0; $from < $ok + $residue; $from += 500) {
            $chunk = [];

            for ($n = $from; $n < min($from + 500, $ok + $residue); $n++) {
                $isResidue = $n >= $ok;
                $directory = sprintf('media/1/2027/%02d', $n % 100);
                $path = sprintf('%s/%s-%d.bin', $directory, $isResidue ? $kind : 'settled', $n);

                if ($n < 100) {
                    @mkdir($root.'/'.$directory, 0700, true);
                    @mkdir($private.'/'.$directory, 0700, true);

                    // Wherever the served disk is configured, so every miss there walks as deep in (I'') as in (I'): a
                    // disk with no media/ tree fails each at its first component, which cost (I') − (I'') more than the
                    // copies it is said to measure (review of slice 5c).
                    if ($kind === 'served' || config('filesystems.disks.bench-served') !== null) {
                        @mkdir($served.'/'.$directory, 0700, true);
                    }
                }

                // An orphan is a file on core's private disk that no row names: no entry, no row.
                if ($isResidue && $kind === 'orphan') {
                    file_put_contents($private.'/'.$path, $large);
                    $this->seeded[] = $path;

                    continue;
                }

                if (! $isResidue || $kind !== 'awaiting') {
                    file_put_contents($root.'/'.$path, $isResidue ? $large : $small);
                }

                if ($isResidue && in_array($kind, ['extra', 'awaiting', 'served'], true)) {
                    file_put_contents(($kind === 'served' ? $served : $private).'/'.$path, $large);
                }

                // Spelt as the disks read another — a doubled slash, as a direct import may write it — over the file.
                $chunk[] = ['n' => $n, 'path' => $isResidue && $kind === 'spelt' ? dirname($path).'//'.basename($path) : $path, 'residue' => $isResidue];
            }

            if ($chunk === []) {
                continue;
            }

            DB::table('entries')->insert(array_map(static fn (array $row): array => [
                'site_id' => 1, 'org_id' => 1, 'entry_type_id' => 1, 'type_handle' => 'image', 'status' => 'published',
                'slug' => 'seed-'.$row['n'], 'title' => 'Bench', 'deleted_at' => $row['residue'] && $kind === 'exposed' ? now() : null,
            ], $chunk));

            $ids = DB::table('entries')->whereIn('slug', array_map(static fn (array $row): string => 'seed-'.$row['n'], $chunk))->pluck('id', 'slug');

            DB::table('media_files')->insert(array_map(static fn (array $row): array => [
                'entry_id' => (int) $ids['seed-'.$row['n']], 'disk' => $row['residue'] && $kind === 'awaiting' ? MediaDisks::PRIVATE : 'public', 'path' => $row['path'],
                'mime' => 'application/octet-stream', 'size_bytes' => $row['residue'] ? strlen($large) : strlen($small),
                'checksum' => $row['residue'] ? $largeSum : $smallSum, 'visibility' => 'public', 'created_at' => now(),
            ], $chunk));

            foreach ($chunk as $row) {
                if ($row['residue'] && $kind !== 'spelt') {
                    $this->seeded[(int) $ids['seed-'.$row['n']]] = $row['path'];
                }
            }
        }
    }

    /**
     * A group's before and after, with (I')'s served disk configured around them — `bench-served`, a local disk with a url.
     *
     * @param  array{0: Closure(): void, 1: Closure(): void}  $isolated
     * @return array{0: Closure(): void, 1: Closure(): void}
     */
    private function withBenchServed(array $isolated): array
    {
        [$before, $after] = $isolated;

        return [
            static function () use ($before): void {
                @mkdir(storage_path('app/bench-served'), 0755, true);
                config(['filesystems.disks.bench-served' => ['driver' => 'local', 'root' => storage_path('app/bench-served'), 'url' => 'https://bench-served.bench']]);
                $before();
            },
            static function () use ($after): void {
                $after();
                config(['filesystems.disks.bench-served' => null]);
                Storage::forgetDisk('bench-served');
            },
        ];
    }

    /**
     * A read-only command over the seed, verified by its child's report: for reconcile, the counts it printed under each
     * label; for prune, exactly the rows or names the seed gives it.
     *
     * @param  array<string, int>  $counts  label => rows, for reconcile; prune is verified against the seed itself
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function listing(string $command, array $counts): array
    {
        return [
            'setup' => static fn (): array => ['file' => storage_path('bench-report.txt')],
            'run' => static function (array &$state) use ($command): void {
                // In a fresh process at the floor's limit, its report to a file as plain text, without colour codes whatever
                // the environment asks (withdrawalBenchReportOutput()).
                $process = new Process(
                    [PHP_BINARY, '-d', 'memory_limit='.WITHDRAWAL_BENCH_CHILD_LIMIT, __FILE__, '--child='.$command],
                    null,
                    [
                        'KITSUNE_BENCH_DIRECTORY' => dirname(storage_path()),
                        'KITSUNE_BENCH_REPORT' => $state['file'],
                        'KITSUNE_BENCH_SERVED' => config('filesystems.disks.bench-served') === null ? '' : '1',
                    ] + getenv(),
                    null,
                    3600,
                );
                $process->run();
                $said = json_decode(trim((string) strrchr("\n".trim($process->getOutput()), "\n")), true);

                if (is_array($said) && isset($said['stopped'])) {
                    throw new RuntimeException(sprintf('stopped at %s: %s — peak %.1f MB, real %.1f MB', $said['limit'], $said['stopped'], $said['peak'] / (1 << 20), $said['real'] / (1 << 20)));
                }

                if (! $process->isSuccessful() || ! is_array($said)) {
                    throw new RuntimeException('The listing\'s process stopped: '.trim($process->getErrorOutput().' '.$process->getOutput()));
                }

                $state['exit'] = $said['exit'];
                $state['child'] = $said;
            },
            'verify' => function (array $state) use ($command, $counts): bool {
                try {
                    // A pass at the floor is a pass at the floor's limit, and says so; and reconcile opened a copy on each
                    // disk for each row held twice, and none where no row is.
                    if (! withdrawalBenchChildVerified($state['child'] ?? [], $command, $counts)) {
                        return false;
                    }

                    if ($command === 'kitsune:media-reconcile') {
                        return $state['exit'] === withdrawalBenchReconcileExit($counts)
                            && withdrawalBenchCounts(withdrawalBenchLinesOf($state['file']), $counts);
                    }

                    // And the pass that asks the volume ran exactly where the seed lists an orphan (review of #155's fix).
                    return $state['exit'] === 0
                        && withdrawalBenchPassVerified($state['child'] ?? [], in_array($this->seededKind, ['orphan', 'spelt'], true)
                            ? intdiv(DB::table('media_files')->count(), MediaPruneCommand::BATCH) + 1
                            : 0)
                        && $this->prunePrinted($state['file']);
                } finally {
                    @unlink($state['file']);
                }
            },
        ];
    }

    /**
     * Whether a read-only prune printed what the last seed left, and nothing else: no orphan, and each seeded residue
     * where it belongs, exactly — every extra copy as one --force would remove, under the entry it belongs to, every row
     * awaiting publication, or every row trashed on a served disk (review of slice 5c: extra copies were counted, not
     * matched, so a copy listed under another entry, or twice, still passed); for (O), exactly the orphans seeded and
     * their count; for (O'), no list at all (#155). Read a line at a time.
     */
    private function prunePrinted(string $file): bool
    {
        // Every seeded orphan listed, and nothing else (review of the fix for Codex, #155).
        if ($this->seededKind === 'orphan') {
            return withdrawalBenchOrphansClosing(withdrawalBenchLinesOf($file), count($this->seeded))
                && withdrawalBenchOnlyList(withdrawalBenchLinesOf($file), 'Orphaned media files')
                && withdrawalBenchOrphans(withdrawalBenchLinesOf($file), MediaDisks::PRIVATE, $this->seeded);
        }

        return withdrawalBenchPruneClosing(withdrawalBenchLinesOf($file), $this->seededKind === 'extra' ? count($this->seeded) : null) && match ($this->seededKind) {
            'extra' => withdrawalBenchOnlyList(withdrawalBenchLinesOf($file), 'Extra copies')
                && withdrawalBenchListed(withdrawalBenchLinesOf($file), 'Extra copies', $this->seededRows(MediaDisks::PRIVATE), WITHDRAWAL_BENCH_REMOVABLE),
            'awaiting' => withdrawalBenchOnlyList(withdrawalBenchLinesOf($file), 'Awaiting publication')
                && withdrawalBenchListed(withdrawalBenchLinesOf($file), 'Awaiting publication', $this->seededRows(MediaDisks::PRIVATE)),
            // Each row's file reached by its path in another spelling, and listed nowhere.
            'spelt' => withdrawalBenchOnlyList(withdrawalBenchLinesOf($file), 'no list'),
            default => withdrawalBenchOnlyList(withdrawalBenchLinesOf($file), 'Trashed on a served disk')
                && withdrawalBenchListed(withdrawalBenchLinesOf($file), 'Trashed on a served disk', $this->seededRows('public')),
        };
    }

    /**
     * The seeded residues as entry, disk and path, one at a time.
     *
     * @return Generator<int, array{0: int, 1: string, 2: string}>
     */
    private function seededRows(string $disk): Generator
    {
        foreach ($this->seeded as $entryId => $path) {
            yield [$entryId, $disk, $path];
        }
    }

    /**
     * One row of each residue kind reconcile repairs, forced: settle's hold, then the cleanup's — and the row, and every
     * disk, left as its state says.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function residue(string $kind, int $bytes): array
    {
        return [
            'setup' => function () use ($kind, $bytes): array {
                [$id, $path, $checksum] = match ($kind) {
                    'R1', 'R3' => $this->file($bytes, 'public', MediaDisks::PRIVATE),
                    'R7' => $this->file($bytes, 'public', 'legacy'),
                    'R6' => $this->file($bytes, trashed: true),
                    default => $this->file($bytes),
                };

                match ($kind) {
                    // A compensation that failed: the row names the public disk, the only copy is on the private one.
                    'R1' => DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'public']),
                    // ADR-041's private files on `local`.
                    'R7' => DB::table('media_files')->where('entry_id', $id)->update(['visibility' => 'private']),
                    // A private copy, of the same size, that differs from the public one.
                    'R4' => Storage::disk(MediaDisks::PRIVATE)->put($path, random_bytes($bytes)),
                    default => null,
                };

                return ['id' => $id, 'path' => $path, 'checksum' => $checksum];
            },
            'run' => static function (array &$state): void {
                $state['exit'] = Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $state['id']]]);
            },
            'verify' => function (array $state) use ($kind): bool {
                $target = in_array($kind, ['R6', 'R7'], true) ? MediaDisks::PRIVATE : 'public';
                $other = $target === 'public' ? MediaDisks::PRIVATE : 'public';

                return $state['exit'] === 0
                    && DB::table('media_files')->where('entry_id', $state['id'])->value('disk') === $target
                    && $this->holds($target, $state['path'], $state['checksum'])
                    && $this->holds($other, $state['path'], null)
                    && ($kind !== 'R7' || ! Storage::disk('legacy')->exists($state['path']));
            },
        ];
    }

    /**
     * Reconcile forced over a hundred thousand rows, a thousand of them trashed while on the public disk: the whole run,
     * the share of it holding a lock, and its memory. Each run puts the thousand back where the seed left them.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function atScale(): array
    {
        return [
            'setup' => function (): array {
                $public = rtrim(Storage::disk('public')->path(''), '/');
                $private = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');

                foreach ($this->seeded as $path) {
                    if (is_file($private.'/'.$path)) {
                        @mkdir(dirname($public.'/'.$path), 0700, true);
                        rename($private.'/'.$path, $public.'/'.$path);
                    }
                }

                foreach (array_chunk(array_keys($this->seeded), 500) as $ids) {
                    DB::table('media_files')->whereIn('entry_id', $ids)->update(['disk' => 'public']);
                }

                return [];
            },
            'run' => static function (array &$state): void {
                $state['exit'] = Artisan::call('kitsune:media-reconcile', ['--force' => true]);
            },
            'verify' => function (array $state): bool {
                if ($state['exit'] !== 0 || DB::table('media_files')->where('disk', MediaDisks::PRIVATE)->count() !== count($this->seeded)) {
                    return false;
                }

                foreach ($this->seeded as $path) {
                    if (! $this->holds(MediaDisks::PRIVATE, $path, $this->seededSum) || ! $this->holds('public', $path, null)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /**
     * Prune's removal of one extra copy: an identical private copy of a live public file, removed under the lock after
     * both are hashed.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function extraCopy(int $bytes): array
    {
        return [
            'setup' => function () use ($bytes): array {
                [$id, $path, $checksum] = $this->file($bytes);
                $copy = Storage::disk(MediaDisks::PRIVATE)->path($path);
                @mkdir(dirname($copy), 0700, true);

                if (! copy(Storage::disk('public')->path($path), $copy)) {
                    throw new RuntimeException('Could not seed the extra copy.');
                }

                return ['id' => $id, 'path' => $path, 'checksum' => $checksum];
            },
            'run' => static function (array &$state): void {
                $state['outcome'] = MediaCustody::removeExtra(DB::connection(), $state['id'], MediaDisks::PRIVATE);
            },
            'verify' => fn (array $state): bool => $state['outcome'] === MediaCustody::SETTLED
                && $this->holds('public', $state['path'], $state['checksum'])
                && $this->holds(MediaDisks::PRIVATE, $state['path'], null),
        ];
    }

    /** @return array{0: Closure(): void, 1: Closure(): void} (M)'s seed, for the migration's contention section */
    public function migrationSeed(): array
    {
        return $this->isolated(fn (): mixed => $this->seedRows(100_000, 0, 0, 'none'));
    }

    /** The migration that makes media file paths unique, as a fresh instance. */
    private function migration(): object
    {
        return require dirname(__DIR__).'/packages/core/database/migrations/0001_01_01_000010_make_media_file_paths_unique.php';
    }

    private function pathsIndexed(): bool
    {
        return collect(DB::connection()->getSchemaBuilder()->getIndexes('media_files'))
            ->contains(static fn (array $index): bool => (bool) $index['unique'] && $index['columns'] === ['path']);
    }

    /**
     * The migration's check alone, over the seed: every path read, grouped and compared as the disks read it — with the
     * index dropped before each run, as a deploy runs it: the index would answer the grouping (review of slice 5b).
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function migrationCheck(): array
    {
        return [
            'setup' => function (): array {
                if ($this->pathsIndexed()) {
                    $this->migration()->down();
                }

                return [];
            },
            'run' => function (array &$state): void {
                $this->migration()->refuseUnsafePaths(DB::table('media_files'));
                $state['checked'] = true;
            },
            'verify' => fn (array $state): bool => ($state['checked'] ?? false) === true && ! $this->pathsIndexed(),
        ];
    }

    /**
     * The migration's `up()` over the seed — its check, then the build — with the index dropped before each run, and
     * inside a transaction where `migrate` would run it in one.
     *
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function migrationUp(): array
    {
        return [
            'setup' => function (): array {
                if ($this->pathsIndexed()) {
                    $this->migration()->down();
                }

                return [];
            },
            'run' => fn () => withdrawalBenchMigrate($this->migration()),
            'verify' => fn (): bool => $this->pathsIndexed(),
        ];
    }

    /**
     * Extra served disks for a group — local ones, or ones whose every presence check waits (synthetic) — and their
     * removal after it.
     *
     * @return array{0: Closure(): void, 1: Closure(): void}
     */
    private function servedDisks(int $count, int $delayMs): array
    {
        $names = array_map(static fn (int $i): string => "bench-served-{$i}", range(0, $count - 1));

        $before = static function () use ($names, $delayMs): void {
            foreach ($names as $name) {
                $root = storage_path("app/{$name}");
                @mkdir($root, 0755, true);
                config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $root, 'url' => "https://{$name}.bench"]]);

                if ($delayMs > 0) {
                    // Declared here rather than at the top: the skeleton's autoloader is not loaded until the harness runs.
                    $adapter = new class($root, $delayMs) extends League\Flysystem\Local\LocalFilesystemAdapter
                    {
                        public function __construct(string $root, private readonly int $delayMs)
                        {
                            parent::__construct($root);
                        }

                        public function fileExists(string $path): bool
                        {
                            usleep($this->delayMs * 1000);

                            return parent::fileExists($path);
                        }
                    };
                    Storage::set($name, new LocalFilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 'local', 'root' => $root]));
                }
            }
        };

        $after = static function () use ($names): void {
            foreach ($names as $name) {
                config(["filesystems.disks.{$name}" => null]);
                Storage::forgetDisk($name);
            }
        };

        return [$before, $after];
    }

    /**
     * (D): a second process, signalled when the trash of a 64 MiB file takes its lock, attempts each kind of write —
     * or, with `$holder` 'reconcile', when a forced reconcile of a 64 MiB file trashed on the public disk takes it.
     *
     * @return list<array{attempt: string, outcome: string, waited: float, verified: bool}>
     */
    public function contention(string $directory, string $holder = 'trash'): array
    {
        $rows = [];
        $attempts = $holder === 'trash'
            ? ['a write to the same entry', 'an audited save of another entry', 'a relation insert', 'an entry created', 'a plain read', 'a public file stored', 'a second trash', 'a long read across the COMMIT']
            : ['a write to the same entry', 'an audited save of another entry', 'a plain read', 'a public file stored'];

        foreach ($attempts as $attempt) {
            if ($attempt === 'a long read across the COMMIT' && $this->driver !== 'sqlite') {
                continue;
            }

            [$id, $path, $checksum] = $this->file(64 << 20, trashed: $holder === 'reconcile');
            [$otherId] = $this->file(1 << 10);
            $signal = $directory.'/signal';
            $ready = $directory.'/ready';
            @unlink($signal);
            @unlink($ready);

            $process = new Process(
                [PHP_BINARY, __FILE__, '--rival='.$attempt],
                null,
                ['KITSUNE_BENCH_DIRECTORY' => $directory, 'KITSUNE_BENCH_OTHER' => (string) $otherId, 'KITSUNE_BENCH_SAME' => (string) $id] + getenv(),
                null,
                120,
            );
            $process->start();

            for ($waited = 0; ! is_file($ready) && $waited < 30_000; $waited += 5) {
                usleep(5_000);
            }

            $armed = true;
            DB::listen(function (QueryExecuted $query) use (&$armed, $signal): void {
                if ($armed && preg_match($this->lockPattern, strtolower($query->sql)) === 1) {
                    $armed = false;
                    touch($signal);
                }
            });

            $survived = true;

            try {
                if ($holder === 'trash') {
                    Entry::query()->findOrFail($id)->delete();
                } elseif (Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $id]]) !== 0) {
                    throw new RuntimeException('The reconcile failed.');
                }

                $verified = $this->holds('public', $path, null) && $this->holds(MediaDisks::PRIVATE, $path, $checksum);
            } catch (Throwable) {
                // A refused or failed trash, or reconcile, must leave the file where it was.
                $survived = false;
                $verified = $this->holds('public', $path, $checksum) && ($holder === 'reconcile' || DB::table('entries')->where('id', $id)->value('deleted_at') === null);
            }

            $armed = false;
            $process->wait();
            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $result = json_decode((string) end($lines), true) ?: ['outcome' => 'no result: '.substr(trim($process->getErrorOutput()), 0, 120), 'waited' => 0.0];

            $this->removeFiles();

            $rows[] = [
                'attempt' => $attempt.($survived ? '' : " (the {$holder} itself failed, and was compensated)"),
                'outcome' => (string) $result['outcome'],
                'waited' => (float) $result['waited'],
                'verified' => $verified,
            ];
        }

        return $rows;
    }
}

/**
 * A migration's `up()` as `migrate` runs it: inside a transaction where Laravel's grammar runs schema changes in one —
 * of the engines here, PostgreSQL alone; SQLite, MySQL and MariaDB run it statement by statement — so the locks it takes
 * are held as long as they would be in a deploy.
 */
function withdrawalBenchMigrate(object $migration): void
{
    if (DB::connection()->getSchemaGrammar()->supportsSchemaTransactions() && ($migration->withinTransaction ?? true)) {
        DB::transaction(static fn () => $migration->up());

        return;
    }

    $migration->up();
}

/**
 * (M) the unique-path migration against what a serving release does meanwhile, over a hundred thousand rows.
 *
 * On PostgreSQL, MySQL and MariaDB a second process holds a transaction that wrote a `media_files` row until two
 * seconds after the build statement begins, and a third, 300 ms after the build statement begins, reads `media_files`
 * or writes to it: how long each of the others waited, the build statement's time and `up()`'s. On SQLite a second
 * process makes read-first writes for the whole of `up()`, and counts those told the database is locked.
 *
 * ⚠️ A ROW IS PRINTED ONLY WHEN IT HAPPENED AS ITS HEADING SAYS — review of slice 5b: the holder held its row before the
 * build was armed and says it committed after, the build statement was seen, and the prober reported. Otherwise the row
 * says so, and prints no figure.
 *
 * ⚠️ SIGNALLED AT THE BUILD STATEMENT, NOT AT `up()`. The check before it reads every row, and would outlast a hold
 * begun at `up()`, so the build would find nothing to wait on; `beforeExecuting` runs as the statement is sent.
 *
 * @return list<array{attempt: string, outcome: string, waited: float, statement: float, up: float, verified: bool}>
 */
function withdrawalBenchMigrationContention(string $directory, string $driver): array
{
    $rows = [];
    $migration = require dirname(__DIR__).'/packages/core/database/migrations/0001_01_01_000010_make_media_file_paths_unique.php';
    $attempts = $driver === 'sqlite' ? ['read-first writes during up()'] : ['a plain read of media_files', 'a write to media_files'];
    [$same, $other] = DB::table('media_files')->orderBy('entry_id')->limit(2)->pluck('entry_id')->map(static fn (mixed $id): int => (int) $id)->all();
    $built = null;
    $armed = false;

    DB::connection()->beforeExecuting(static function (string $query) use (&$armed, &$built, $directory, $driver): void {
        if ($armed && preg_match('/^(create unique index|alter table .* add unique)/i', ltrim($query)) === 1) {
            $armed = false;
            $built = hrtime(true);

            if ($driver !== 'sqlite') {
                touch($directory.'/signalh');
                touch($directory.'/signalp');
            }
        }
    });

    foreach ($attempts as $attempt) {
        $migration->down();
        $environment = ['KITSUNE_BENCH_DIRECTORY' => $directory, 'KITSUNE_BENCH_SAME' => (string) $same, 'KITSUNE_BENCH_OTHER' => (string) $other] + getenv();

        foreach (['h', 'p', 'd'] as $tag) {
            @unlink($directory.'/ready'.$tag);
            @unlink($directory.'/signal'.$tag);
        }

        $holder = $driver === 'sqlite' ? null : new Process([PHP_BINARY, __FILE__, '--rival=hold a media_files row'], null, ['KITSUNE_BENCH_TAG' => 'h'] + $environment, null, 120);
        $prober = new Process([PHP_BINARY, __FILE__, '--rival='.$attempt], null, ['KITSUNE_BENCH_TAG' => 'p'] + $environment, null, 120);
        $holder?->start();
        $prober->start();

        for ($waited = 0; (! is_file($directory.'/readyp') || ($holder !== null && ! is_file($directory.'/readyh'))) && $waited < 30_000; $waited += 5) {
            usleep(5_000);
        }

        // Held before the build is armed, or the build has nothing to wait on.
        $held = $holder === null || is_file($directory.'/readyh');

        if ($driver === 'sqlite') {
            touch($directory.'/signalp');
        }

        [$built, $armed] = [null, true];
        $started = hrtime(true);

        try {
            withdrawalBenchMigrate($migration);
        } finally {
            $ended = hrtime(true);
            $armed = false;
            // Whatever happened, the rivals are released: the holder commits, the SQLite prober stops.
            touch($directory.'/signalh');
            touch($directory.'/signalp');
            touch($directory.'/signald');
            $holder?->wait();
            $prober->wait();
        }

        $lines = preg_split('/\R/', trim($prober->getOutput())) ?: [];
        $result = json_decode((string) end($lines), true) ?: ['outcome' => 'no result: '.substr(trim($prober->getErrorOutput()), 0, 120), 'waited' => 0.0];
        $holderLines = $holder === null ? [] : (preg_split('/\R/', trim($holder->getOutput())) ?: []);

        $rows[] = [
            'attempt' => $attempt,
            'outcome' => (string) $result['outcome'],
            'waited' => (float) $result['waited'],
            'statement' => $built === null ? 0.0 : ($ended - $built) / 1e6,
            'up' => ($ended - $started) / 1e6,
            'verified' => withdrawalBenchContentionVerified([
                'held' => $held,
                'holder' => $holder === null ? null : ['exit' => $holder->getExitCode(), 'last' => (string) end($holderLines)],
                'built' => $built !== null,
                'prober' => ['exit' => $prober->getExitCode(), 'outcome' => (string) $result['outcome']],
            ]),
        ];
    }

    return $rows;
}

/**
 * A child's stop reporter, then the application's boot, in that order — see `withdrawalBenchStopReporter()`.
 *
 * @param  Closure(): void  $boot
 */
function withdrawalBenchBoot(?string $child, Closure $boot): void
{
    if ($child !== null) {
        withdrawalBenchStopReporter();
    }

    $boot();
}

/**
 * A child's report of its own stop — memory, above all, at the floor's 128 MB — with the peak it reached, as the last line
 * it prints (review of slice 5c).
 *
 * ⚠️ REGISTERED BEFORE THE APPLICATION BOOTS, AND IT ENDS THE PROCESS. Shutdown functions run in the order they were
 * registered, and Laravel's, registered as it boots, renders the error — and runs out of memory again, abandoning every
 * shutdown function after it, or prints its render below this one. Exiting from here skips them.
 */
function withdrawalBenchStopReporter(): void
{
    $limit = (string) ini_get('memory_limit');

    register_shutdown_function(static function () use ($limit): void {
        $error = error_get_last();

        if ($error === null || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        ini_set('memory_limit', '-1');
        echo PHP_EOL, json_encode([
            'stopped' => $error['message'].' ('.basename($error['file']).':'.$error['line'].')',
            'peak' => memory_get_peak_usage(), 'real' => memory_get_peak_usage(true), 'limit' => $limit,
        ]), PHP_EOL;

        exit(255);
    });
}

/**
 * Remove the run's directory when the process finishes, and after a fatal error, Ctrl-C, Ctrl-\, SIGTERM or SIGHUP; any
 * other signal, SIGKILL among them, leaves it. A fatal error — memory, above all — skips `finally`: the directory goes all
 * the same (review of slice 5c). Laravel's own
 * shutdown handler, registered as the application boots after this, then logs the error to storage_path('logs'), inside
 * the directory, and makes it again (review of slice 5c, twice): so it goes once more, from a function registered while
 * shutting down — which runs after every one registered before it. And a signal PHP has no handler for ends the process
 * with neither `finally` nor a shutdown function run, leaving 100,000 seeded files behind a stopped run: each is turned
 * into an exit, which runs the shutdown functions but not `finally` blocks, so the directory goes from the one registered
 * here (review of slice 5c, three times). Only where pcntl is loaded; SIGKILL cannot be caught.
 */
function withdrawalBenchCleanup(string $directory): void
{
    register_shutdown_function(static function () use ($directory): void {
        ini_set('memory_limit', '-1');
        exec('rm -rf '.escapeshellarg($directory));
        register_shutdown_function(static fn () => exec('rm -rf '.escapeshellarg($directory)));
    });

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);

        foreach ([SIGINT, SIGQUIT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, static function () use ($signal): void {
                exit(128 + $signal);
            });
        }
    }
}

/**
 * Run a command, timed from once the console application is built and the console's own classes are loaded — as a run in
 * the harness's own process finds them, and as 5b's figures were timed. The command's first run still loads its own.
 *
 * @return array{int, float} the exit code, and the command's own time in ms
 */
function withdrawalBenchTimed(string $command, OutputInterface $output): array
{
    Artisan::call('env', [], new NullOutput);
    $started = hrtime(true);
    $exit = Artisan::call($command, [], $output);

    return [$exit, (hrtime(true) - $started) / 1e6];
}

/**
 * The report a child writes: plain text, whatever the environment asks — a FORCE_COLOR the parent passes on wrapped a
 * line in colour codes, and no check that reads the report could match it (review of slice 5c).
 *
 * @param  resource  $handle
 */
function withdrawalBenchReportOutput($handle): StreamOutput
{
    return new StreamOutput($handle, StreamOutput::VERBOSITY_NORMAL, false);
}

/**
 * A read-only listing in a process of its own, as an operator runs it: its report to the file the parent names, then one
 * line of JSON — the exit code, the command's own time, the process's peak memory, allocated and real, the memory limit
 * it ran under, how many copies it opened on the public and served disks, and how many batches of prune's pass over the
 * table it read. listing()'s verify reads the exit code and the limit, for reconcile the copies opened, and for prune the
 * pass; measure() takes the time and both peaks as the case's figures.
 */
function withdrawalBenchChild(string $command): int
{
    // (I')'s served disk, which the parent's configuration does not reach across the process boundary.
    if (getenv('KITSUNE_BENCH_SERVED') === '1') {
        config(['filesystems.disks.bench-served' => ['driver' => 'local', 'root' => storage_path('app/bench-served'), 'url' => 'https://bench-served.bench']]);
    }

    // Every copy reconcile opens, counted on each disk, so a figure that says it opened them is verified by it.
    $opened = ['public' => 0, 'bench-served' => 0];

    foreach (['public', 'bench-served'] as $name) {
        if (config("filesystems.disks.{$name}") === null) {
            continue;
        }

        $root = (string) config("filesystems.disks.{$name}.root");
        $adapter = new class($root, $opened[$name]) extends League\Flysystem\Local\LocalFilesystemAdapter
        {
            public function __construct(string $root, private int &$opened)
            {
                parent::__construct($root);
            }

            public function readStream(string $path)
            {
                $this->opened++;

                return parent::readStream($path);
            }
        };
        Storage::set($name, new LocalFilesystemAdapter(new Filesystem($adapter), $adapter, (array) config("filesystems.disks.{$name}")));
    }

    // Every batch of the pass prune makes over the table, counted, so a figure said to cost it is verified to have run it.
    $passed = 0;
    DB::listen(static function (QueryExecuted $query) use (&$passed): void {
        if (withdrawalBenchPassQuery($query->sql)) {
            $passed++;
        }
    });

    $handle = fopen((string) getenv('KITSUNE_BENCH_REPORT'), 'w+b');
    [$exit, $ms] = withdrawalBenchTimed($command, withdrawalBenchReportOutput($handle));
    fclose($handle);

    echo json_encode([
        'exit' => $exit, 'ms' => $ms, 'peak' => memory_get_peak_usage(), 'real' => memory_get_peak_usage(true),
        'limit' => ini_get('memory_limit'), 'opened' => $opened, 'passed' => $passed,
    ]), PHP_EOL;

    return 0;
}

/** The second process: wait for the signal, attempt one write, and report what happened and how long it waited. */
function withdrawalBenchRival(string $attempt, string $directory): int
{
    app(Context::class)->setOrg(Org::query()->findOrFail(1));
    app(Context::class)->setSite(Site::query()->findOrFail(1));
    $same = (int) getenv('KITSUNE_BENCH_SAME');
    $other = (int) getenv('KITSUNE_BENCH_OTHER');
    // Its own files, when more than one rival runs at once: (M) runs a holder and a prober.
    $tag = (string) getenv('KITSUNE_BENCH_TAG');

    // (M)'s holder: a transaction that wrote a media_files row, committed two seconds after the build statement begins.
    if ($attempt === 'hold a media_files row') {
        DB::beginTransaction();
        DB::table('media_files')->where('entry_id', $same)->update(['disk' => DB::raw('disk')]);
        touch($directory.'/ready'.$tag);

        for ($waited = 0; ! is_file($directory.'/signal'.$tag) && $waited < 60_000; $waited += 1) {
            usleep(1_000);
        }

        usleep(2_000_000);
        DB::commit();
        echo json_encode(['outcome' => 'held until two seconds into the build', 'waited' => 0.0]), PHP_EOL;

        return 0;
    }

    touch($directory.'/ready'.$tag);

    for ($waited = 0; ! is_file($directory.'/signal'.$tag) && $waited < 60_000; $waited += 1) {
        usleep(1_000);
    }

    // (M) on SQLite: read-first writes, one after another, until up() is done.
    if ($attempt === 'read-first writes during up()') {
        [$tried, $locked] = [0, 0];

        for ($spent = 0; ! is_file($directory.'/signald') && $spent < 30_000; $spent += 1) {
            $tried++;

            try {
                DB::transaction(static function () use ($other): void {
                    DB::table('entries')->where('id', $other)->value('title');
                    DB::table('entries')->where('id', $other)->update(['title' => 'Rival']);
                });
            } catch (Throwable $failure) {
                $locked += str_contains($failure->getMessage(), 'database is locked') ? 1 : 0;
            }

            usleep(1_000);
        }

        echo json_encode(['outcome' => sprintf('%d of %d told "database is locked"', $locked, $tried), 'waited' => 0.0]), PHP_EOL;

        return 0;
    }

    // (M) elsewhere: 300 ms into the build statement, so it has queued behind the holder.
    if (in_array($attempt, ['a plain read of media_files', 'a write to media_files'], true)) {
        usleep(300_000);
    }

    $started = hrtime(true);
    $outcome = 'ok';

    try {
        match ($attempt) {
            'a write to the same entry' => DB::table('entries')->where('id', $same)->update(['title' => 'Rival']),
            'an audited save of another entry' => (function () use ($other): void {
                $entry = Entry::query()->findOrFail($other);
                $entry->title = 'Rival';
                $entry->save();
            })(),
            'a relation insert' => DB::table('entry_relations')->insert(['org_id' => 1, 'source_entry_id' => $other, 'target_entry_id' => $same, 'ordering' => 0]),
            'an entry created' => Entry::query()->create(['entry_type_id' => 2, 'title' => 'Rival', 'slug' => 'rival-'.bin2hex(random_bytes(4))]),
            'a plain read' => Entry::query()->count(),
            'a public file stored' => (function (): void {
                $source = tempnam(sys_get_temp_dir(), 'kitsune-bench-');
                file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
                MediaLibrary::store($source, 'rival.png', EntryType::query()->findOrFail(1), 'public');
                unlink($source);
            })(),
            'a second trash' => Entry::query()->findOrFail($other)->delete(),
            'a plain read of media_files' => DB::table('media_files')->count(),
            'a write to media_files' => DB::table('media_files')->where('entry_id', $other)->update(['disk' => DB::raw('disk')]),
            'a long read across the COMMIT' => (function (): void {
                $reader = new PDO('sqlite:'.DB::connection()->getDatabaseName());
                $reader->exec('BEGIN');
                $reader->query('select count(*) from entries')->fetchColumn();
                usleep(2_000_000);
                $reader->exec('COMMIT');
            })(),
        };
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
        $outcome = match (true) {
            str_contains($message, 'Lock wait timeout') || str_contains($message, 'lock timeout') => 'lock timeout',
            str_contains($message, 'Deadlock') || str_contains($message, 'deadlock') => 'deadlock',
            str_contains($message, 'database is locked') && $failure instanceof QueryException => '"database is locked" at the first write',
            str_contains($message, 'database is locked') => '"database is locked" at COMMIT',
            default => 'failed: '.substr($message, 0, 80),
        };
    }

    echo json_encode(['outcome' => $outcome, 'waited' => (hrtime(true) - $started) / 1e6]), PHP_EOL;

    return 0;
}

/*
 * Run, when this file is the script — last, so the classes above are declared by then; required by
 * `MediaWithdrawalBenchHarnessTest`, it only defines them.
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(withdrawalBenchMain($argv));
}
