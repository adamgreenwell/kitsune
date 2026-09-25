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
        $groups[] = ['figures' => ['(G) prune --force: 1,000 orphans, 1,000 kept copies, about 10,000 rows' => 'whole'], 'case' => $this->prune(), 'runs' => 3];

        foreach ([1, 3] as $extra) {
            [$before, $after] = $this->servedDisks($extra, 0);
            $groups[] = ['figures' => ["(H) trash, 4 MB, with {$extra} more served local disk(s): its hold" => 0], 'case' => $this->trash(4 << 20), 'before' => $before, 'after' => $after];
        }

        foreach ([1, 10, 50] as $delay) {
            [$before, $after] = $this->servedDisks(1, $delay);
            $groups[] = ['figures' => ["(H) trash, 4 MB, one served disk answering each presence check in {$delay} ms (synthetic): its hold" => 0], 'case' => $this->trash(4 << 20), 'before' => $before, 'after' => $after];
        }

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
     * 1,000 orphans to remove, 1,000 kept copies and about 10,000 rows to list — and the kept copies, and the files
     * whose rows name them, verified still there.
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
                        Storage::disk(MediaDisks::PRIVATE)->put($path, 'a kept copy');
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
                    if (! $this->holds('public', $path, $checksum) || Storage::disk(MediaDisks::PRIVATE)->get($path) !== 'a kept copy') {
                        return false;
                    }
                }

                return true;
            },
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
     * (D): a second process, signalled when the trash of a 64 MiB file takes its lock, attempts each kind of write.
     *
     * @return list<array{attempt: string, outcome: string, waited: float, verified: bool}>
     */
    public function contention(string $directory): array
    {
        $rows = [];

        foreach (['a write to the same entry', 'an audited save of another entry', 'a relation insert', 'an entry created', 'a plain read', 'a public file stored', 'a second trash', 'a long read across the COMMIT'] as $attempt) {
            if ($attempt === 'a long read across the COMMIT' && $this->driver !== 'sqlite') {
                continue;
            }

            [$id, $path, $checksum] = $this->file(64 << 20);
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
                Entry::query()->findOrFail($id)->delete();
                $verified = $this->holds('public', $path, null) && $this->holds(MediaDisks::PRIVATE, $path, $checksum);
            } catch (Throwable) {
                // A refused or failed trash must leave the file where it was.
                $survived = false;
                $verified = $this->holds('public', $path, $checksum) && DB::table('entries')->where('id', $id)->value('deleted_at') === null;
            }

            $armed = false;
            $process->wait();
            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $result = json_decode((string) end($lines), true) ?: ['outcome' => 'no result: '.substr(trim($process->getErrorOutput()), 0, 120), 'waited' => 0.0];

            $this->removeFiles();

            $rows[] = [
                'attempt' => $attempt.($survived ? '' : ' (the trash itself failed, and was compensated)'),
                'outcome' => (string) $result['outcome'],
                'waited' => (float) $result['waited'],
                'verified' => $verified,
            ];
        }

        return $rows;
    }
}

/** The second process: wait for the signal, attempt one write, and report what happened and how long it waited. */
function withdrawalBenchRival(string $attempt, string $directory): int
{
    app(Context::class)->setOrg(Org::query()->findOrFail(1));
    app(Context::class)->setSite(Site::query()->findOrFail(1));
    $same = (int) getenv('KITSUNE_BENCH_SAME');
    $other = (int) getenv('KITSUNE_BENCH_OTHER');

    touch($directory.'/ready');

    for ($waited = 0; ! is_file($directory.'/signal') && $waited < 60_000; $waited += 1) {
        usleep(1_000);
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
