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
 * it finishes, however it finishes.
 *
 * ⚠️ WHAT IS TIMED IS WHAT IS BUILT: custody as it runs — the copy written beside the path, read back and renamed; no
 * fsync; presence asked before any hash. A hold runs from the statement that takes the lock — the entry read `FOR
 * UPDATE` on PostgreSQL, MySQL and MariaDB, the first write on SQLite — to the outermost commit.
 *
 * ⚠️ A FIGURE IS PRINTED ONLY WHEN EVERY RUN VERIFIED: after each operation both disks hold what they should, by
 * SHA-256, and the lock statement was seen. A run that moved nothing, or moved the wrong thing, prints no number.
 *
 * One warm-up, then seven runs, each on fresh random bytes; the median and the maximum. Every figure is warm-cache.
 * No threshold is proposed here: the numbers are for deciding on (ADR-042 decision 5).
 *
 * ⚠️ AT ADR-027'S FLOOR, GIVE IT MORE THAN PHP'S DEFAULT 128 MB — `php -d memory_limit=512M` — or it stops in (G'):
 * prune reads every media row at once, and over 100,000 rows that exhausts the default. The figure it then prints is
 * the peak prune reached; the stop is itself the finding (slice 5b).
 *
 * ⚠️ SLICE 5B'S GROUPS (I, J, J', K, G', M) RUN AFTER EVERY 5A GROUP, EACH ON ITS OWN SEED. Each clears every media row,
 * entry and file before it seeds and after it finishes, so what earlier groups left — byte-less rows among them — never
 * reaches a figure; and each verifies by counting what the command it runs reported, so a contaminated run prints none.
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
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Process;

/** The one database name this harness runs against. */
const WITHDRAWAL_BENCH_DATABASE = 'kitsune_bench_withdrawal';

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
 * @param  array<string, int>  $expected  label => rows
 */
function withdrawalBenchCounts(string $output, array $expected): bool
{
    $counted = [];

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^\|\s*([a-z][a-z ]*[a-z])\s*\|\s*(\d+)\s*\|/', $line, $match) === 1 && $match[1] !== 'Label') {
            $counted[$match[1]] = (int) $match[2];
        }
    }

    ksort($counted);
    ksort($expected);

    return $counted === $expected;
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

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--rival=')) {
            $rival = substr($argument, 8);
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

    try {
        $root = dirname(__DIR__).'/skeleton';
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        // Before the application boots, so every disk rooted in storage_path() — core's included — is rooted here.
        $app->useStoragePath($directory.'/storage');
        $app->make(Kernel::class)->bootstrap();

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
    $say("# {$driver} {$version} — ".php_uname('s').' '.php_uname('m').', PHP '.PHP_VERSION.', warm cache');

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

    $say('');
    $say('## Holds (ms): median | max, seven runs after one warm-up');
    $say('| case | median | max |');
    $say('|---|---|---|');

    foreach ($bench->groups() as $group) {
        foreach ($bench->measure($group) as $label => $runs) {
            $say(withdrawalBenchFigure($label, $runs) ?? "| {$label} | not verified — no figure | |");
        }
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

    /** @var list<array{0: int, 1: string, 2: string}> the residue rows the last seed wrote: entry, path and checksum */
    private array $seeded = [];

    private string $lockPattern;

    public function __construct(private readonly string $driver)
    {
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
     * ⚠️ THE OPERATION ALONE IS TIMED — review. Each case is three steps: setup, which seeds the file; the operation;
     * and a verification, which reads what the disks hold by hash. Only the operation is inside the clock and the memory
     * reading, and each run's files are removed once verified, so a run's disk use never piles onto the next.
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
        $groups[] = ['figures' => ['(I) reconcile, read-only: 100,000 rows, 1,000 findings' => 'whole', '(I) reconcile, read-only: peak memory, MB' => 'memory'], 'case' => $this->listing('kitsune:media-reconcile', ['exposed' => 1_000]), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 1 << 10, 'exposed'));
        // Its memory too: prune reads every row at once, where reconcile reads them in chunks (found at the floor, slice 5b).
        $groups[] = ['figures' => ['(G\') prune, read-only: 100,000 rows' => 'whole', '(G\') prune, read-only: peak memory, MB' => 'memory'], 'case' => $this->listing('kitsune:media-prune', []), 'before' => $before, 'after' => $after];

        foreach (['4 MB' => 4 << 20, '64 MiB' => 64 << 20] as $size => $bytes) {
            foreach (['R1: live public, only on the private disk', 'R3: awaiting publication', 'R4: a differing private copy', 'R6: trashed on the public disk', 'R7: private, on a legacy disk'] as $kind) {
                [$before, $after] = $this->isolated(static fn (): null => null, legacy: true);
                $groups[] = ['figures' => [
                    "(J) reconcile --force, {$kind}, {$size}: settle's hold" => 0,
                    "(J) reconcile --force, {$kind}, {$size}: the cleanup's hold" => 1,
                ], 'case' => $this->residue(substr($kind, 0, 2), $bytes), 'before' => $before, 'after' => $after];
            }
        }

        [$before, $after] = $this->isolated(fn (): mixed => $this->seedRows(99_000, 1_000, 200 << 10, 'exposed'));
        $groups[] = ['figures' => [
            '(J\') reconcile --force: 100,000 rows, 1,000 trashed on the public disk, 200 KB' => 'whole',
            '(J\') reconcile --force: the share of the run holding a lock, %' => 'held',
            '(J\') reconcile --force: peak memory, MB' => 'memory',
        ], 'case' => $this->atScale(), 'before' => $before, 'after' => $after];

        [$before, $after] = $this->isolated(static fn (): null => null);
        $groups[] = ['figures' => ['(K) prune\'s removal of an extra copy, 64 MiB: its hold' => 0], 'case' => $this->extraCopy(64 << 20), 'before' => $before, 'after' => $after];

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

                try {
                    $run($state);
                } catch (Throwable) {
                    $failed = true;
                }

                $ended = hrtime(true);
                $this->timing = false;
                $peak = memory_get_peak_usage();
                $verified = ! $failed && $verify($state);
                $this->removeFiles();

                if ($i === 0) {
                    continue;
                }

                foreach ($group['figures'] as $label => $figure) {
                    // The transactions that took a lock, in order: the others — a read, a no-op — are not holds.
                    $holds = array_values(array_filter($this->holds, static fn (array $hold): bool => $hold['start'] !== null));
                    $hold = is_int($figure) ? ($holds[$figure] ?? null) : null;
                    $value = match (true) {
                        $figure === 'whole' => ($ended - $started) / 1e6,
                        $figure === 'held' => withdrawalBenchHeld($this->holds, ($ended - $started) / 1e6),
                        $figure === 'memory' => $peak / (1 << 20),
                        $hold !== null && $hold['end'] !== null => ($hold['end'] - $hold['start']) / 1e6,
                        default => null,
                    };

                    $results[$label][] = ['ms' => $value ?? 0.0, 'verified' => $verified && $value !== null];
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
        $clear = static function (): void {
            DB::table('media_files')->delete();
            DB::table('entry_relations')->delete();
            DB::table('entries')->delete();

            foreach (['public', MediaDisks::PRIVATE, 'legacy'] as $disk) {
                if ($disk !== 'legacy' || config('filesystems.disks.legacy') !== null) {
                    Storage::disk($disk)->deleteDirectory('media');
                }
            }
        };

        return [
            function () use ($clear, $seed, $legacy): void {
                if ($legacy) {
                    config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => storage_path('app/legacy')]]);
                }

                $clear();
                $seed();
            },
            static function () use ($clear, $legacy): void {
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
     *
     * @return list<array{0: int, 1: string, 2: string}> the residue rows: entry, path and checksum
     */
    private function seedRows(int $ok, int $residue, int $residueBytes, string $kind): array
    {
        $root = rtrim(Storage::disk('public')->path(''), '/');
        $small = random_bytes(1 << 10);
        $large = $residueBytes > 0 ? random_bytes($residueBytes) : '';
        $made = [];
        $rows = [];
        $residues = [];

        for ($n = 0; $n < $ok + $residue; $n++) {
            $isResidue = $n >= $ok;
            $directory = sprintf('media/1/2027/%02d', $n % 100);
            $path = sprintf('%s/%s-%d.bin', $directory, $isResidue ? $kind : 'settled', $n);

            if (! isset($made[$directory])) {
                @mkdir($root.'/'.$directory, 0700, true);
                $made[$directory] = true;
            }

            file_put_contents($root.'/'.$path, $isResidue ? $large : $small);
            $rows[] = ['n' => $n, 'path' => $path, 'residue' => $isResidue];
        }

        $smallSum = hash('sha256', $small);
        $largeSum = $large === '' ? $smallSum : hash('sha256', $large);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('entries')->insert(array_map(static fn (array $row): array => [
                'site_id' => 1, 'org_id' => 1, 'entry_type_id' => 1, 'type_handle' => 'image', 'status' => 'published',
                'slug' => 'seed-'.$row['n'], 'title' => 'Bench', 'deleted_at' => $row['residue'] ? now() : null,
            ], $chunk));

            $ids = DB::table('entries')->whereIn('slug', array_map(static fn (array $row): string => 'seed-'.$row['n'], $chunk))->pluck('id', 'slug');

            DB::table('media_files')->insert(array_map(static fn (array $row): array => [
                'entry_id' => (int) $ids['seed-'.$row['n']], 'disk' => 'public', 'path' => $row['path'],
                'mime' => 'application/octet-stream', 'size_bytes' => $row['residue'] ? strlen($large) : strlen($small),
                'checksum' => $row['residue'] ? $largeSum : $smallSum, 'visibility' => 'public', 'created_at' => now(),
            ], $chunk));

            foreach ($chunk as $row) {
                if ($row['residue']) {
                    $residues[] = [(int) $ids['seed-'.$row['n']], $row['path'], $largeSum];
                }
            }
        }

        $this->seeded = $residues;

        return $residues;
    }

    /**
     * A read-only command over the seed, verified by the counts it printed.
     *
     * @param  array<string, int>  $counts  label => rows, for reconcile; prune is verified against the seed itself
     * @return array{setup: Closure(): array<string, mixed>, run: Closure(array<string, mixed>): void, verify: Closure(array<string, mixed>): bool}
     */
    private function listing(string $command, array $counts): array
    {
        return [
            'setup' => static fn (): array => [],
            'run' => static function (array &$state) use ($command): void {
                $state['exit'] = Artisan::call($command);
                $state['output'] = Artisan::output();
            },
            'verify' => function (array $state) use ($command, $counts): bool {
                if ($command === 'kitsune:media-reconcile') {
                    return $state['exit'] === 1 && withdrawalBenchCounts($state['output'], $counts);
                }

                // Prune: nothing orphaned, nothing extra, and every seeded residue listed as trashed on a served disk.
                return $state['exit'] === 0
                    && str_contains($state['output'], 'No orphaned media files.')
                    && ! str_contains($state['output'], 'Extra copies')
                    && preg_match_all('/^\| \d+ +\| public +\| media\/1\/2027\//m', $state['output']) === count($this->seeded);
            },
        ];
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

                foreach ($this->seeded as [, $path]) {
                    if (is_file($private.'/'.$path)) {
                        @mkdir(dirname($public.'/'.$path), 0700, true);
                        rename($private.'/'.$path, $public.'/'.$path);
                    }
                }

                foreach (array_chunk(array_column($this->seeded, 0), 500) as $ids) {
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

                foreach ($this->seeded as [, $path, $checksum]) {
                    if (! $this->holds(MediaDisks::PRIVATE, $path, $checksum) || ! $this->holds('public', $path, null)) {
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
