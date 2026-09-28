<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Closure;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\ReadThroughFilesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\ReadThroughCycle;
use Kitsune\Core\Models\MediaFile;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Find media files on disk that no row claims, and optionally remove them — ADR-041, and ADR-042 decision 5.
 *
 * ⚠️ THE REPAIR PATH FOR TWO BEST-EFFORT GAPS THIS SYSTEM HAS ON PURPOSE. `MediaLibrary` writes bytes before
 * rows, so a crash between them leaves a file nothing references; `MediaDisposal` removes bytes after rows and
 * reports rather than throws, so a disk that refuses leaves the same residue. Both were chosen over the
 * alternative — an entry pointing at a file that does not exist — and both are recoverable only because the
 * table can say what it knows about.
 *
 * ⚠️ READ-ONLY WITHOUT `--force`, which is `kitsune:schema-sync`'s posture and for a sharper reason: this
 * command deletes files. A default that removed them would make a typo in a disk name destructive, and the
 * thing being reconciled is the one thing in Kitsune with no revision history and no undo.
 *
 * ⚠️ IT ASKS THE DATABASE, NEVER THE FILENAME. ~~A file is an orphan because no `media_files` row names its
 * (disk, path) pair.~~ Amended for ADR-042 decision 5: a file is an orphan because no row names its PATH, on any
 * disk. Custody leaves verified copies of a file at its own path on disks its row does not name — the private copy
 * a restore keeps, a publication that could not commit, a copy a move-off could not remove — and every one of them
 * may be the only good copy, so each is listed ~~as kept and none is ever deleted here~~ as an extra copy. A partial
 * copy custody was writing beside a path (`MediaBytes::PARTIAL`) is decided by the path it was written for: the table
 * decides every delete, and the suffix only chooses which row's lock to take. ~~Nothing is hashed.~~
 *
 * ⚠️ AN EXTRA COPY GOES ONLY UNDER ITS ROW'S LOCK, ONCE ITS OWN DISK HOLDS THE COPY KEPT — ADR-042 decision 5, slice 5b.
 * With `--force`, a copy at a path a row names, on a disk it does not, is handed to `MediaCustody::removeExtra()`
 * when the listing shows the row naming the disk its state says and that disk's own listing holds the path — listed a
 * second time for the paths prune holds (review of slice 5c) — unless the copy is on a disk whose media directory nests
 * with it or on a `read-through` disk, or its row's path is one the disks read as another, or refuse: those are kept for
 * a hand, and their lines say so (review of slice 5c). Under the lock it is asked again: the row as it is there, every
 * copy hashed before the first delete, one that differs removed with both hashes logged (Adam, decisions 2 and 2b), one
 * that alone matches the checksum or cannot be read never. The listing hashes nothing; every other copy is kept, and its
 * line names what settles it: `kitsune:media-reconcile`, which moves a file to where its row says, a person, or —
 * for a disk that could not be listed — prune run again once it can be.
 *
 * ⚠️ EVERY DELETE IS RECHECKED UNDER CUSTODY'S LOCK. The listing is read without one, so a row committed since could
 * claim a file listed as an orphan; `MediaCustody::removeOrphan()` asks again with the lock held, and keeps the file if
 * a row now names its path. ⚠️ It sees committed rows only: `store()` writes an upload's bytes before its row, so a run
 * landing between the two — or, off SQLite, before the row commits — removes a file an upload is about to claim. That
 * limit is older than custody, and recorded in ADR-042.
 */
final class MediaPruneCommand extends Command
{
    protected $signature = 'kitsune:media-prune {--force : actually delete the orphans, the leftover partial copies and the extra copies of settled files, rather than listing them}';

    protected $description = 'Find media files no media_files row needs, and copies beside a row\'s own (ADR-041, ADR-042)';

    /** Files listed, and rows read, at a time (Adam, decision 11, 2026-09-26). */
    public const BATCH = 500;

    /**
     * An extra copy's integer (`extra()`): its entry, then the disk its row names — one of up to 256 — then what is known of
     * it. The entry has the 50 bits left: an id to about 10^15.
     */
    private const ENTRY_SHIFT = 13;

    private const DISK_SHIFT = 5;

    /** The largest entry id an extra copy's integer holds. */
    public const LARGEST_ENTRY = PHP_INT_MAX >> self::ENTRY_SHIFT;

    private const TRASHED = 1;

    private const BELONGS_PUBLIC = 2;

    private const OWNED = 4;

    private const NESTS = 8;

    private const REMOVABLE = 16;

    /** @var list<string> the disks rows of listed extra copies name, which their integers point into */
    private array $diskNames = [];

    /** @var array<string, int> */
    private array $diskIndex = [];

    /** @var array<string, bool> whether each disk listed is a read-through one, asked once */
    private array $readThrough = [];

    /** @var list<string> custody's warnings for the extra copy being removed, printed after it */
    private array $warnings = [];

    /**
     * Whether a forced run is collecting them now, and whether this instance has registered its listener — one per
     * instance, because Artisan reuses a command in a process (as `kitsune:media-reconcile` does).
     */
    private bool $collecting = false;

    private bool $listening = false;

    public function handle(): int
    {
        $config = app('config');
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');
        $connection = (new MediaFile)->getConnection();
        $this->diskNames = [];
        $this->diskIndex = [];
        $this->readThrough = [];

        // Read-only on a table the unique-path migration refused — the one an operator reads while fixing the rows the
        // refusal named — every line that names a forced command names one that refuses there: said first (review of 5c).
        if (! $this->option('force') && ! MediaCustody::pathsAreUnique($connection)) {
            $this->warn('media_files.path is not unique on this database: kitsune:media-prune --force and kitsune:media-reconcile --force refuse until the migration 0001_01_01_000010_make_media_file_paths_unique has run, and every line below that names either assumes it has.');
        }

        /*
         * ⚠️ IN BATCHES, HOLDING NO ROW IT DOES NOT LIST — Adam, decision 11, 2026-09-26. ~~Every row, read once and past
         * every scope: `media_files` is `#[Unscoped]`, and a console asking on an operator's behalf has no org to narrow
         * by. An installation's media table is smaller than its media directory by definition, and asking per file would
         * be a query per file.~~ Over 100,000 rows that held more than PHP's default 128 MB at ADR-027's floor. The table
         * is still read past every scope, for the same reason, but in batches: once for the disks rows name, then once per
         * batch of listed files for the rows naming their paths — a query per 500 files, not per file.
         */
        $rowDisks = $this->rowDisks($connection);

        /*
         * ⚠️ THE CONFIGURED DISKS AND EVERY DISK A ROW NAMES — K. ADR-042 moved private media off `local` and left the
         * rows already naming it valid, so a refused disposal of one of those leaves its orphan there. Asking the table
         * which disks it uses is the command's own rule: a disk nothing names and nothing is configured to use is not
         * this command's to sweep, because its `media/` folder may be the host's.
         *
         * ⚠️ AND CORE'S OWN PRIVATE DISK ALWAYS — Codex, #153. With `kitsune.media.disks.private` pointed at a host disk,
         * `kitsune-private` stops being configured, and a copy disposal could not remove from it — disposal always asks
         * it — would never be listed once no row named it. Its `media/` is Kitsune's by definition. Unconfigured, local
         * and without a root, it holds nothing and is not built, as a served disk below is not.
         *
         * ⚠️ AND THE DISKS THE WEB SERVES — V — FOR KEPT COPIES ONLY. A copy custody could not remove from a disk that
         * serves it is the one an operator most needs to see; a file there that no row's path names is the host's,
         * and is not listed. A local one whose root does not exist holds nothing, and is not built: building it would
         * create the directory.
         */
        $kitsune = array_values(array_unique([$public, $private]));

        if (! in_array(MediaDisks::PRIVATE, $kitsune, true) && MediaDisks::mayHold($config, MediaDisks::PRIVATE)) {
            $kitsune[] = MediaDisks::PRIVATE;
        }

        /*
         * ⚠️ A DISK THAT IS ANOTHER SCANNED DISK UNDER ANOTHER NAME IS NOT SCANNED — review of slice 5b, twice. Its
         * listing is that disk's, so every file there would be listed twice — as an extra copy of itself, beside a disk
         * a row names or another served disk — and removing one would remove the file. So each disk a row names, then
         * each served disk, is compared with the disks already queued, the configured ones first: one place — one
         * directory; a nested one is another, and is scanned — and it is left to the disk it aliases, whose listing holds
         * its files at the same paths; cannot be told apart, and it is not scanned either,
         * and says so; an alias of the private disk, which the web must not serve, says so too. Asked only of disks that
         * are configured and can hold anything: asking builds a local disk, which would create its root. A row's disk
         * that cannot be asked is scanned as before, and its listing reports what is wrong with it. The disks rows name
         * are queued in the order their first rows were written (review of slice 5c).
         */
        $named = array_values(array_diff($rowDisks, $kitsune));
        $servedDisks = MediaDisks::servedDisks($config);
        $servedOnly = array_values(array_diff($servedDisks, $kitsune, $named));
        $served = [];

        /*
         * ⚠️ NOTHING IS LISTED WHILE A DISK NESTS INSIDE ONE PRUNE LISTS ORPHANS ON — review of slice 5b. That disk lists
         * the inner one's files under longer paths no row names, as its own orphans, and removing one would remove a file
         * of the inner disk: one a row names, or the host's. Every configured disk is asked, not only those prune scans:
         * a host's disk nothing names or serves nests as surely. A disk prune scans only for extra copies — served, and
         * named by no row — lists no orphans, so nesting inside it harms nothing, and each is scanned. `onePlace()`
         * counts nesting as one place, which is right for refusing a move and wrong for reading a listing.
         */
        $nesting = $this->nesting($config, [...$kitsune, ...$named], [...$kitsune, ...$named, ...$servedOnly], array_map(
            'strval',
            array_keys((array) $config->get('filesystems.disks', [])),
        ));

        if ($nesting !== null) {
            $this->error(sprintf(
                'Refusing to list: the media directory of [%s] is inside [%s]\'s, so a file of [%s] would be listed as an '
                .'orphan of [%s], and removing it would remove the file (ADR-042 decision 5). Point them at media '
                .'directories that do not nest. Nothing was listed or removed.',
                $nesting[0],
                $nesting[1],
                $nesting[0],
                $nesting[1],
            ));

            return self::FAILURE;
        }

        foreach ([...$named, ...$servedOnly] as $disk) {
            $isNamed = in_array($disk, $named, true);
            $askable = is_array($config->get("filesystems.disks.{$disk}")) && MediaDisks::mayHold($config, $disk);

            if (! $askable) {
                // A served disk that cannot hold anything is not built; a row's disk is listed, and says why it cannot be.
                if ($isNamed) {
                    $kitsune[] = $disk;
                }

                continue;
            }

            $other = $this->aliasOf($config, $disk, [...$kitsune, ...$served], $private);

            if ($other === false) {
                continue;
            }

            if ($other !== null) {
                $this->warn(sprintf(
                    'Not scanning [%s]: it is, or cannot be told apart from, [%s] — one place, one bucket or host through two '
                    .'endpoints, or a read-through disk reaching it through a half — so a file there could be the file itself.%s',
                    $disk,
                    $other,
                    // Reconcile refuses a private disk the web serves; an unserved alias it repoints like any row.
                    $other === $private && in_array($disk, $servedDisks, true)
                        ? ' kitsune:media-reconcile --force refuses while the private disk is one of them.'
                        : '',
                ));

                continue;
            }

            if ($isNamed) {
                $kitsune[] = $disk;
            } else {
                $served[] = $disk;
            }
        }

        /*
         * What prune lists, by disk in the order the disks are scanned and by path within each, as `allFiles()` sorted
         * them: orphans as paths, partial copies as path => entry, and extra copies as path => their row in one integer
         * (`extra()`) — an array and a row per extra copy held about 0.9 KB (review of slice 5c).
         */
        $orphans = [];
        $partials = [];
        $extras = [];
        $unlisted = [];
        $failed = 0;

        foreach ([...$kitsune, ...$served] as $disk) {
            $found = ['orphans' => [], 'partials' => [], 'extras' => []];
            $failure = null;

            foreach ($this->listing($disk, $failure) as $paths) {
                $this->classify($connection, $disk, in_array($disk, $kitsune, true), $public, $paths, $found);
            }

            // ⚠️ A LISTING THAT FAILS PART-WAY CONTRIBUTES NOTHING, as `allFiles()`, which returned all or threw, did.
            if ($failure !== null) {
                $this->error(sprintf('Could not list [%s]: %s', $disk, $failure));
                $unlisted[] = $disk;
                $failed++;

                continue;
            }

            sort($found['orphans'], SORT_STRING);
            ksort($found['partials'], SORT_STRING);
            ksort($found['extras'], SORT_STRING);

            if ($found['orphans'] !== []) {
                $orphans[$disk] = $found['orphans'];
            }

            if ($found['partials'] !== []) {
                $partials[$disk] = $found['partials'];
            }

            if ($found['extras'] !== []) {
                $extras[$disk] = $found['extras'];
            }
        }

        // The last disk's tables are still shared with it: the flags written below would copy them, and keep both.
        unset($found);

        /*
         * Removable when the row names the disk its state says, and that disk's listing holds the path: the lock asks again.
         *
         * ⚠️ READ FROM THE TARGET'S OWN LISTING, IN A SECOND PASS — review of slice 5c, twice. Remembering every path each
         * disk listed as its own row's would hold one entry per settled file, the table again. Asking the target instead
         * (`fileExists`) answered what a listing does not — through a symlink, a volume that folds case, and a
         * `read-through` disk's fallback, which is the very copy being removed; custody, which hashes the target through
         * that fallback, then removed the only copy. So each target an extra copy's row names is listed once more, and
         * only the paths prune holds are looked for. A target that cannot be listed then fails the run, and each extra copy
         * whose row names it is kept as one on a disk that could not be listed; its own orphans and partial copies, which
         * its first listing held in full, are still listed and, with --force, removed — each asked again under the lock.
         */
        $wanted = [];

        foreach (array_keys($extras) as $disk) {
            foreach ($extras[$disk] as $path => $extra) {
                [, $rowDisk, $target] = $this->unpack($extra, $public, $private);

                if ($rowDisk === $target && ! in_array($target, $unlisted, true)) {
                    $wanted[$target][$path] = false;
                }
            }
        }

        foreach (array_keys($wanted) as $target) {
            // A disk named with digits alone is an integer key: every name leaves these maps a string (review of slice 5c).
            $target = (string) $target;
            $failure = null;

            foreach ($this->listing($target, $failure) as $paths) {
                foreach ($paths as $path) {
                    if (isset($wanted[$target][$path])) {
                        $wanted[$target][$path] = true;
                    }
                }
            }

            if ($failure !== null) {
                $this->error(sprintf('Could not list [%s] again, to ask whether it holds the extra copies whose rows name it: %s', $target, $failure));
                $unlisted[] = $target;
                $failed++;
            }
        }

        // By key: a foreach by value keeps each table alive while the first write copies it — as `$found`, unreleased, did
        // the last disk's (review of slice 5c, twice).
        $nests = [];

        foreach (array_keys($extras) as $disk) {
            $disk = (string) $disk;

            foreach (array_keys($extras[$disk]) as $path) {
                $extra = $extras[$disk][$path];
                [, $rowDisk, $target] = $this->unpack($extra, $public, $private);
                $nests[$disk.':'.$target] ??= $this->nestsWith($config, $disk, $target);
                $known = $nests[$disk.':'.$target] ? self::NESTS : 0;

                // A row path the disks read as another, or refuse — the listed name is the row's own, matched byte for byte —
                // is one custody refuses before any disk is asked: kept, and never offered as removable (review of 5c).
                if ($rowDisk === $target && ! in_array($target, $unlisted, true) && ($wanted[$target][$path] ?? false) && ! self::misnamed((string) $path)) {
                    // Custody removes no copy through a read-through disk (`MediaBytes::delete()`): kept, as a nest is.
                    $known |= self::OWNED | ($known & self::NESTS || $this->readsThrough($disk) ? 0 : self::REMOVABLE);
                }

                $extras[$disk][$path] = $extra | $known;
            }
        }

        unset($wanted);

        $orphanCount = array_sum(array_map('count', $orphans));
        $partialCount = array_sum(array_map('count', $partials));
        $removableCount = 0;

        foreach ($extras as $copies) {
            foreach ($copies as $extra) {
                $removableCount += $extra & self::REMOVABLE ? 1 : 0;
            }
        }

        $this->report($connection, $orphans, $partials, $extras, $public, $private, $config, $unlisted);

        if ($orphanCount === 0 && $partialCount === 0 && $removableCount === 0) {
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn(sprintf(
                '%d orphaned file%s, %d leftover partial cop%s and %d removable extra cop%s listed and nothing removed. '
                .'Re-run with --force to delete them; this command is read-only by default because media has no revision '
                .'history and no undo.',
                $orphanCount,
                $orphanCount === 1 ? '' : 's',
                $partialCount,
                $partialCount === 1 ? 'y' : 'ies',
                $removableCount,
                $removableCount === 1 ? 'y' : 'ies',
            ));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        // A file freed by work not yet committed reads as an orphan here, and would stay deleted if it rolled back.
        if (! MediaCustody::isOutermost($connection)) {
            $this->error(
                'Refusing to prune inside an open transaction: a file freed by work that has not committed reads as an '
                .'orphan, and would stay deleted if that work rolled back (ADR-042 decision 5). Nothing was removed.'
            );

            return self::FAILURE;
        }

        try {
            MediaDisks::refuseUnsafeMediaDisks($config);
        } catch (RuntimeException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        if (! MediaCustody::pathsAreUnique($connection)) {
            $this->error(
                'Refusing: media_files.path is not unique on this database, and custody removes a copy only on the '
                .'understanding that its path is one row\'s (ADR-042 decision 5; Adam, decision 8, 2026-09-25). Run the '
                .'migration 0001_01_01_000010_make_media_file_paths_unique (php artisan migrate) and try again. Nothing '
                .'was removed.'
            );

            return self::FAILURE;
        }

        if ($connection->getDriverName() === 'sqlite') {
            $this->warn(
                'On SQLite each removal holds the database\'s write lock while the copies are read, and a save or upload '
                .'that reads before it writes fails during each hold: run it when nobody is editing (ADR-042, *Measured — '
                .'decision 5, slice 5b*).'
            );
        }

        $removed = 0;
        $reclaimed = 0;

        foreach ($orphans as $disk => $paths) {
            $disk = (string) $disk;

            foreach ($paths as $path) {
                try {
                    MediaCustody::removeOrphan($connection, $disk, $path) === MediaCustody::REMOVED
                        ? $removed++
                        : $reclaimed++;
                } catch (Throwable $failure) {
                    $this->error(sprintf('Could not remove [%s:%s]: %s', $disk, $path, $failure->getMessage()));
                    $failed++;
                }
            }
        }

        foreach ($partials as $disk => $copies) {
            $disk = (string) $disk;

            foreach ($copies as $path => $entryId) {
                try {
                    MediaCustody::removeTemp($connection, $entryId, $disk, $path);
                    $removed++;
                } catch (Throwable $failure) {
                    $this->error(sprintf('Could not remove [%s:%s]: %s', $disk, $path, $failure->getMessage()));
                    $failed++;
                }
            }
        }

        $extraRemoved = 0;
        $noLongerExtra = 0;
        $extraErased = 0;
        $extraKept = 0;

        if ($removableCount !== 0 && ! $this->listening) {
            $this->listening = true;

            Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
                if ($this->collecting && $logged->level === 'warning' && str_starts_with($logged->message, 'Media custody')) {
                    $this->warnings[] = $logged->message;
                }
            });
        }

        foreach ($extras as $disk => $copies) {
            $disk = (string) $disk;

            foreach ($copies as $path => $extra) {
                if (! ($extra & self::REMOVABLE)) {
                    continue;
                }

                [$entryId] = $this->unpack($extra, $public, $private);
                $this->warnings = [];
                $this->collecting = true;

                try {
                    // One there only through a link the listing left out, or under another name, is not the copy it
                    // listed: removing it would remove what the link reaches (review of slice 5c, twice).
                    if (MediaBytes::present($disk, $path) && ! MediaBytes::held($disk, $path)) {
                        throw new MediaCustodyFailure('unheld', $disk, $path);
                    }

                    $outcome = MediaCustody::removeExtra($connection, $entryId, $disk);

                    // Nothing there under the name it was listed by — a local name whose backslashes the listing gave as
                    // `/`, or one gone since — is not a copy that went: it fails, as an orphan's does (review of 5c).
                    if ($outcome === MediaCustody::UNCHANGED && ! MediaBytes::held($disk, $path)) {
                        throw new MediaCustodyFailure('unheld', $disk, $path);
                    }
                } catch (Throwable $failure) {
                    $outcome = $failure;
                } finally {
                    $this->collecting = false;
                }

                match (true) {
                    $outcome instanceof Throwable => (function () use ($disk, $path, $outcome, &$failed): void {
                        $this->error(sprintf('Could not remove [%s:%s]: %s', $disk, $path, $outcome->getMessage()));
                        $failed++;
                    })(),
                    $outcome === MediaCustody::SETTLED => $extraRemoved++,
                    $outcome === MediaCustody::GONE => $extraErased++,
                    $outcome === MediaCustody::UNSETTLED => (function () use ($disk, $path, $entryId, &$extraKept): void {
                        $extraKept++;
                        $this->line(sprintf(
                            'Kept [%s:%s], entry %d — %s',
                            $disk,
                            $path,
                            $entryId,
                            $this->warnings === []
                                ? sprintf('under the lock its row no longer named the disk its state says: kitsune:media-reconcile '
                                    .'--entry=%d --force settles the row first.', $entryId)
                                : 'custody says why, and what settles it:',
                        ));
                    })(),
                    default => $noLongerExtra++,
                };

                // What custody logged for this copy — a differing copy's two hashes, or why it was kept — as reconcile prints it.
                foreach ($this->warnings as $warning) {
                    $this->line('    '.$warning);
                }

                $this->warnings = [];
            }
        }

        $total = $orphanCount + $partialCount;

        $this->info(sprintf(
            'Removed %d of %d orphaned or leftover file%s and %d of %d extra cop%s.%s%s%s%s',
            $removed,
            $total,
            $total === 1 ? '' : 's',
            $extraRemoved,
            $removableCount,
            $removableCount === 1 ? 'y' : 'ies',
            // Asked under the lock as the database compares paths, and as a local disk reaches them: a row committed
            // since the listing; on MySQL and MariaDB one the collation compares equal — among them a path differing
            // only in case or accents, in trailing spaces under a PAD SPACE collation, in characters a UCA collation
            // gives no weight or in compatibility forms (² for 2, ß for ss), and under utf8mb4_unicode_ci and
            // utf8mb4_general_ci in which character above U+FFFF it holds; on PostgreSQL one naming what a listed name
            // holds before a NUL, since the driver cuts a bound value short there; and on any engine one the volume
            // reaches as this very file, under another case or Unicode normalization (`MediaBytes::spellingsOf()`). A
            // case variant is its own file on a volume that folds case; the rest are others, kept on every run and
            // removed by hand (ADR-042, *What it leaves*).
            $reclaimed === 0 ? '' : sprintf(' %d kept: under the lock a row claimed %s — one committed since the listing, or one the database, or the disk, takes for the same path.', $reclaimed, $reclaimed === 1 ? 'its path' : 'their paths'),
            $noLongerExtra === 0 ? '' : sprintf(
                ' %d no longer an extra copy under the lock — now the copy %s row names.',
                $noLongerExtra,
                $noLongerExtra === 1 ? 'its' : 'their',
            ),
            $extraErased === 0 ? '' : sprintf(
                ' %d whose entry was erased since the listing: its disposal removes %s or logs why it could not — but '
                .'not from a disk only other rows name, where the next run lists %s as an orphan.',
                $extraErased,
                $extraErased === 1 ? 'the copy' : 'the copies',
                $extraErased === 1 ? 'it' : 'each'
            ),
            $extraKept === 0 ? '' : sprintf(' %d kept under the lock, each with its reason above.', $extraKept),
        ));

        /* A disk that refused is reported by the count disagreeing, rather than by silence. */
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The disks rows name, each once and exactly as written — in the order their first rows were written — read in
     * batches of `id` and `disk` alone.
     *
     * ⚠️ COMPARED IN PHP, NOT BY `SELECT DISTINCT` — review of slice 5c. MySQL and MariaDB compare `disk` under the column's
     * collation — without regard to case or accents under every default, and to trailing spaces under a PAD SPACE one —
     * and would fold two names a disk lookup keeps apart.
     *
     * @return list<string>
     */
    private function rowDisks(Connection $connection): array
    {
        $disks = [];

        $connection->table('media_files')->select(['id', 'disk'])->chunkById(self::BATCH, function (Collection $rows) use (&$disks): void {
            foreach ($rows as $row) {
                $disks[(string) $row->disk] = true;
            }
        }, 'id');

        return array_map('strval', array_keys($disks));
    }

    /**
     * A disk's files under `media/`, a batch at a time, as the disk lists them — never the whole listing at once.
     *
     * ⚠️ ONLY A FAILURE TO LIST IS CAUGHT HERE, and said through `$failure`: the batch is classified outside, so a query
     * that fails there is the database's failure, not the disk's (review of slice 5c). Building the disk is listing it.
     *
     * @return Generator<int, list<string>>
     */
    private function listing(string $disk, ?string &$failure): Generator
    {
        $batch = [];

        try {
            // Never built: Laravel would recurse through its halves until memory ran out (review of slice 5c).
            if (MediaDisks::cycles(config(), $disk)) {
                throw new ReadThroughCycle(sprintf('Refusing to build the [%s] disk: its read-through disks form a cycle.', $disk));
            }

            /** @var iterable<StorageAttributes> $items */
            $items = Storage::disk($disk)->listContents('media', true);

            foreach ($items as $item) {
                if (! $item->isFile()) {
                    continue;
                }

                $batch[] = $item->path();

                if (count($batch) === self::BATCH) {
                    yield $batch;
                    $batch = [];
                }
            }
        } catch (Throwable $listing) {
            $failure = $listing->getMessage();

            return;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Classify a batch of one disk's files by the rows naming their paths, read for this batch alone.
     *
     * ⚠️ A FILE IS CLAIMED ONLY BY A ROW NAMING EXACTLY ITS PATH, ON THIS DISK, as when every row was read at once: the
     * rows the query returns are matched in PHP, byte for byte, so MySQL's and MariaDB's collation — `X.png` for `x.png`,
     * `cafe.png` for `café.png`, a trailing space ignored under a PAD SPACE one — never claims a file for a row. Every row
     * returned claims its own disk's copy, so on a table the unique-path migration refused, where two rows name one path,
     * neither row's own file is listed as the other's extra copy (review of slice 5c). And on SQLite, which compares
     * within a storage class — a value stored as a BLOB never equals one bound as text — each name is asked as both
     * (`MediaCustody::whereStored()`): a row written past `MediaFile` with its path as a BLOB claimed nothing, and
     * `--force` removed its only file as an orphan (review of slice 5c).
     *
     * ⚠️ NO NAME THE ENGINE CANNOT HOLD IS SENT — review of slice 5c. A name that is not UTF-8 is legal on a disk, and
     * PostgreSQL refuses it in a string (22021) — the whole batch, and the run with it; MySQL and MariaDB, strict and
     * utf8mb4, hold none either. A NUL an object store's key may hold: PostgreSQL's driver cuts a bound value short at
     * it, and though MySQL and MariaDB can store one, no row names one, since `MediaFile` refuses a path Flysystem would
     * not read back as written. So on every engine but SQLite such a name is matched to no row without asking; SQLite
     * compares bytes within a storage class, and is asked. `--force` still asks, per file, under the lock (`MediaCustody::removeOrphan()`) — and
     * cannot remove one on any engine: Flysystem refuses a name that is not UTF-8, or holds a character of Unicode's class
     * C, before a disk sees it.
     *
     * @param  list<string>  $paths
     * @param  array{orphans: list<string>, partials: array<string, int>, extras: array<string, int>}  $found
     */
    private function classify(Connection $connection, string $disk, bool $ours, string $public, array $paths, array &$found): void
    {
        $bytes = $connection->getDriverName() === 'sqlite';
        $keys = [];

        foreach ($paths as $path) {
            if (! $bytes && (! mb_check_encoding($path, 'UTF-8') || str_contains($path, "\0"))) {
                continue;
            }

            $keys[$path] = true;

            if (str_ends_with($path, MediaBytes::PARTIAL)) {
                $keys[substr($path, 0, -strlen(MediaBytes::PARTIAL))] = true;
            }
        }

        $claimed = [];
        $byPath = [];

        if ($keys !== []) {
            $rows = MediaCustody::whereStored(
                $connection->table('media_files')->leftJoin('entries', 'entries.id', '=', 'media_files.entry_id'),
                'media_files.path',
                array_map('strval', array_keys($keys)),
            )
                ->orderBy('media_files.id')
                ->get(['media_files.entry_id', 'media_files.disk', 'media_files.path', 'media_files.visibility', 'entries.deleted_at']);

            foreach ($rows as $row) {
                $claimed[(string) $row->disk."\0".(string) $row->path] = true;
                // One row per path once `media_files_path_unique` exists (Adam, decision 8); the last one otherwise.
                $byPath[(string) $row->path] = $row;
            }
        }

        foreach ($paths as $path) {
            if (isset($claimed[$disk."\0".$path])) {
                // The row's own disk lists its path.
                continue;
            }

            if (str_ends_with($path, MediaBytes::PARTIAL)) {
                $row = $byPath[substr($path, 0, -strlen(MediaBytes::PARTIAL))] ?? null;

                if ($row !== null) {
                    $found['partials'][$path] = (int) $row->entry_id;
                } elseif ($ours) {
                    $found['orphans'][] = $path;
                }

                continue;
            }

            if (isset($byPath[$path])) {
                $found['extras'][$path] = $this->extra($byPath[$path], $public);
            } elseif ($ours) {
                $found['orphans'][] = $path;
            }
        }
    }

    /**
     * An extra copy's row, in one integer: its entry, the disk it names — an index into the names seen this run — whether
     * it is trashed, and whether its file belongs on the public disk. What is learnt of the copy later is or-ed in.
     *
     * @throws RuntimeException for an entry id beyond about ±10^15, or a 257th disk among the rows of extra copies — either
     *                          would be read back as another entry's, or another disk's
     */
    private function extra(stdClass $row, string $public): int
    {
        $disk = (string) $row->disk;
        $entryId = (int) $row->entry_id;

        if (! isset($this->diskIndex[$disk])) {
            $this->diskIndex[$disk] = count($this->diskNames);
            $this->diskNames[] = $disk;
        }

        if (($entryId << self::ENTRY_SHIFT) >> self::ENTRY_SHIFT !== $entryId) {
            throw new RuntimeException(sprintf('Refusing to list entry %d\'s extra copy: its id is beyond what prune can hold beside it (about ±10^15).', $entryId));
        }

        if ($this->diskIndex[$disk] > 0xFF) {
            throw new RuntimeException(sprintf('Refusing to list entry %d\'s extra copy: rows of extra copies name more than 256 disks, beyond what prune can hold.', $entryId));
        }

        return $entryId << self::ENTRY_SHIFT
            | $this->diskIndex[$disk] << self::DISK_SHIFT
            | ($row->deleted_at === null ? 0 : self::TRASHED)
            | (MediaCustody::target($row, $row) === $public ? self::BELONGS_PUBLIC : 0);
    }

    /**
     * An extra copy's entry, the disk its row names, and the disk it belongs on.
     *
     * @return array{int, string, string}
     */
    private function unpack(int $extra, string $public, string $private): array
    {
        return [
            $extra >> self::ENTRY_SHIFT,
            $this->diskNames[($extra >> self::DISK_SHIFT) & 0xFF],
            $extra & self::BELONGS_PUBLIC ? $public : $private,
        ];
    }

    /**
     * A disk whose media directory lies inside one prune lists orphans on, and that one — [inner, outer] — or null.
     * Asked only of disks that are configured and can hold anything. The disks prune scans are built, as the scan
     * builds them, and one that cannot be is read from its configuration (review of slice 5c); every other configured
     * disk — a host's — is read from its configuration alone, since building one may need a package the install does
     * not have (review of slice 5b). A disk that cannot be read either way is left out.
     *
     * @param  list<string>  $listing  the disks prune lists orphans on
     * @param  list<string>  $scanned  the disks prune scans, which it builds anyway
     * @param  list<string>  $configured  every configured disk
     * @return array{0: string, 1: string}|null
     */
    private function nesting(Repository $config, array $listing, array $scanned, array $configured): ?array
    {
        $askable = static fn (array $names): array => array_values(array_filter(
            array_unique($names),
            static function (string $disk) use ($config): bool {
                try {
                    return is_array($config->get("filesystems.disks.{$disk}")) && MediaDisks::mayHold($config, $disk);
                } catch (Throwable) {
                    return false;
                }
            },
        ));

        foreach ($askable($listing) as $outer) {
            foreach ($askable([...$scanned, ...$configured]) as $inner) {
                // A disk prune scans that cannot be built is read from its configuration, as a host's is (review of 5c).
                foreach (in_array($inner, $scanned, true) ? [true, false] : [false] as $build) {
                    try {
                        if (MediaDisks::within($config, $inner, $outer, build: $build)) {
                            return [$inner, $outer];
                        }

                        break;
                    } catch (Throwable) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether a disk prune listed is a `read-through` one, whose delete removes a file from both its halves, only one of
     * which custody reads — so it removes no copy through it (review of slice 5c). Listed, it is built already.
     */
    private function readsThrough(string $disk): bool
    {
        return $this->readThrough[$disk] ??= Storage::disk($disk) instanceof ReadThroughFilesystem;
    }

    /**
     * Whether a disk's media directory nests with the target's — custody refuses to remove a copy there, taking the two
     * for one place, so it is not offered as removable (review of slice 5b). A disk that cannot be asked does not nest.
     */
    private function nestsWith(Repository $config, string $disk, string $target): bool
    {
        try {
            return MediaDisks::mayHold($config, $disk) && MediaDisks::mayHold($config, $target) && MediaDisks::nested($config, $disk, $target);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a disk is one already queued under another name: null when it is none of them; false when it is one of
     * them, not the private disk, and is left to it silently; the other disk's name when it cannot be told apart from
     * one, or is the private disk — which is said.
     *
     * @param  list<string>  $queued
     */
    private function aliasOf(Repository $config, string $disk, array $queued, string $private): string|false|null
    {
        $places = [];

        foreach ($queued as $other) {
            try {
                if (! is_array($config->get("filesystems.disks.{$other}")) || ! MediaDisks::mayHold($config, $other)) {
                    continue;
                }

                // A disk nested inside another, or around it, is another directory: its files are its own to list.
                if (! MediaDisks::nested($config, $disk, $other)) {
                    $places[$other] = MediaDisks::onePlace($config, $disk, $other);
                }
            } catch (Throwable) {
                // A pair that cannot be compared — a read-through disk, on either side, whose half cannot be built or is
                // not configured — is left out, and the other queued disks still decide; a disk that cannot be compared
                // with any of them is scanned, and its listing says what is wrong with it (review of slice 5c).
                continue;
            }
        }

        // Keys come back as integers for a disk named with digits alone, and are compared with names (review of 5c).
        $same = array_search(true, $places, true);
        $same = $same === false ? false : (string) $same;
        $unsure = array_search(null, $places, true);
        $unsure = $unsure === false ? false : (string) $unsure;

        return match (true) {
            $same !== false && $same !== $private => false,
            $same !== false => $same,
            $unsure !== false => $unsure,
            default => null,
        };
    }

    /**
     * Whether a listed extra copy's name — its row's path, matched byte for byte — is one the disks read as another, or
     * refuse: a string's check, no disk asked (review of slice 5c).
     */
    private static function misnamed(string $path): bool
    {
        try {
            MediaBytes::refuseUnnamable('', $path);

            return false;
        } catch (MediaCustodyFailure) {
            return true;
        }
    }

    /**
     * Every list, each saying why it is there and what settles it — a line an entry, under its heading.
     *
     * ⚠️ LINES, NOT TABLES — review of slice 5c. A table builds every row, and measures every cell, before it prints one:
     * measured, some 0.3 KB a row printed to a terminal and up to 0.7 KB into the buffer `Artisan::call()` writes to, on
     * top of the 0.13 KB each extra copy is held in. A hundred thousand of them — every file, once a public disk has
     * moved — were 30 to 70 MB more as a table, beside a bootstrap of about 40 MB. A line printed to a terminal or a
     * stream holds nothing once printed; into `Artisan::call()`'s buffer it keeps its text, some 0.18 KB for an extra
     * copy, until the caller reads the output.
     *
     * @param  array<string, list<string>>  $orphans
     * @param  array<string, array<string, int>>  $partials
     * @param  array<string, array<string, int>>  $extras
     * @param  list<string>  $unlisted  the disks a listing failed on: the first, after which what they hold was not asked,
     *                                  or the second, after which whether they hold an extra copy's path was not
     */
    private function report(Connection $connection, array $orphans, array $partials, array $extras, string $public, string $private, Repository $config, array $unlisted): void
    {
        if ($orphans === []) {
            $this->info('No orphaned media files.');
        } else {
            $this->line('Orphaned media files — no row names their paths, on any disk:');

            foreach ($orphans as $disk => $paths) {
                foreach ($paths as $path) {
                    $this->line(sprintf('  [%s]  %s', $disk, $path));
                }
            }
        }

        if ($partials !== []) {
            $this->line('Leftover partial copies — custody was writing each beside the path its row names, and did not finish:');

            foreach ($partials as $disk => $copies) {
                foreach ($copies as $path => $entryId) {
                    $this->line(self::rowLine((object) ['entry_id' => $entryId, 'disk' => $disk, 'path' => $path]));
                }
            }
        }

        $servedDisks = MediaDisks::servedDisks($config);
        $asked = [];

        if ($extras !== []) {
            $this->line('Extra copies — at a path a row names, on a disk it does not. With --force a copy whose line says it goes is removed under its row\'s lock — one that differs from the copy kept with a warning naming both hashes; every other copy is kept, and its line says what settles it:');

            foreach ($extras as $disk => $copies) {
                // A disk named with digits alone is an integer key: every name leaves these maps a string (review of 5c).
                $disk = (string) $disk;

                foreach ($copies as $path => $extra) {
                    [$entryId, $named, $target] = $this->unpack($extra, $public, $private);
                    $this->line(sprintf(
                        '%s — %s, its row names [%s], it belongs on [%s]: %s',
                        self::rowLine((object) ['entry_id' => $entryId, 'disk' => $disk, 'path' => $path]),
                        $extra & self::TRASHED ? 'trashed' : 'live',
                        $named,
                        $target,
                        match (true) {
                            // First, whatever disk the row names: settle refuses the row before any disk is asked (5c).
                            self::misnamed((string) $path) => 'kept: its row\'s path is not written as the disks read it, and this copy is under that literal name, which no disk reads or removes — move it by hand on the disk the row names to a path the disks read as itself, one no other row names, checked against the recorded checksum, then correct media_files.path to that path; kitsune:media-reconcile lists the row as misnamed',
                            ($extra & self::REMOVABLE) !== 0 => 'removed, once asked again under the lock',
                            // Reconcile moves a row only once the disk it names can be asked. First: a read-through cycle
                            // is configured as read-through, and the arms below would claim it, and advise taking a copy
                            // off through halves that are never built, of a disk that holds nothing (review of slice 5c).
                            $named !== $target && in_array($named, $unlisted, true) => sprintf(
                                'kept: [%s], which its row names, could not be listed — kitsune:media-reconcile --force moves its row only once that disk can be asked',
                                $named,
                            ),
                            /*
                             * A disk that reaches the one the file belongs on, or cannot be told apart from it — a
                             * read-through disk over the public disk, above all — is one reconcile moves no row off, and
                             * removes no copy through: taking a copy off it by hand could take the file where it belongs
                             * (review of slice 5c).
                             */
                            MediaCustody::overlapsTarget($config, $target, $named, $path) => sprintf(
                                'kept: its row names [%1$s], which reaches [%2$s]\'s files or cannot be told apart from it — kitsune:media-reconcile --force moves no row off it, and removes no copy through it: copy the file to [%2$s] by hand if it is not there, checking it against the recorded checksum, then correct media_files.disk to [%2$s], or point [%1$s] at a place that does not overlap it',
                                $named,
                                $target,
                            ),
                            /*
                             * Reconcile's move-off removes the copy the row's own disk holds, and custody removes none
                             * through a read-through disk: while it holds one, every forced reconcile fails on the row.
                             * Asked of the configuration, and — for a driver of the host's own, which only the instance can
                             * say reads through — of the instance, which only such a driver builds; and prune did not ask
                             * whether it holds the file — its listing is its primary's — so the line says what to do if it
                             * does (review of slice 5c).
                             */
                            $named !== $target && ! in_array($named, [$private, MediaDisks::PRIVATE], true) && (MediaDisks::readsThrough($config, $named) || MediaDisks::builtReadThrough($config, $named)) => sprintf(
                                'kept: its row names [%1$s], a read-through disk, which custody neither reads nor removes a copy through — while [%1$s] holds the file, kitsune:media-reconcile --force cannot move its row: copy the file to [%2$s] by hand if it is not there, checking it against the recorded checksum, then take [%1$s]\'s copy off through the disk each half is',
                                $named,
                                $target,
                            ),
                            /*
                             * Off the web, settle takes the file off every served disk it asks, and custody removes no copy
                             * through a read-through disk: every forced reconcile of the row fails on this copy until a
                             * person takes it off — once the file is where it belongs. Not while the disk the row names
                             * could not be listed: that is said first, below (review of slice 5c).
                             */
                            $target !== $public && ! in_array($named, $unlisted, true) && $this->readsThrough($disk) && in_array($disk, $servedDisks, true) => sprintf(
                                'kept: [%1$s] is a read-through disk the web serves, which custody neither reads nor removes a copy through, and the file belongs off the web — every kitsune:media-reconcile --force of its row fails on this copy until it is gone: copy the file to [%2$s] by hand if it is not there, checking it against the recorded checksum, then compare this copy with it and take it off through the disk each half is',
                                $disk,
                                $target,
                            ),
                            // Reconcile cannot move its row from this copy, and the target was not asked about the path:
                            // nothing is said of whether it holds the file (review of slice 5c).
                            $named !== $target && $this->readsThrough($disk) => sprintf(
                                'kept: [%1$s] is a read-through disk, which custody neither reads nor removes a copy through — kitsune:media-reconcile --force moves its row only from a readable copy on another disk it asks; otherwise copy it to [%2$s] by hand',
                                $disk,
                                $target,
                            ),
                            $named !== $target => 'kept: kitsune:media-reconcile moves its row first',
                            // Nobody knows whether it holds the file, so nothing is said of that (review of slice 5c).
                            in_array($named, $unlisted, true) => sprintf('kept: [%s] could not be listed — run kitsune:media-prune again once it can be', $named),
                            // Prune read neither copy, and this one may be the only one that matches: never advise removing it.
                            $this->readsThrough($disk) => sprintf(
                                ($extra & self::OWNED) !== 0
                                    ? 'kept: [%1$s] is a read-through disk, which custody removes no copy through — compare it with [%2$s]\'s copy by hand: it may be the only one that matches'
                                    : 'kept: [%1$s] is a read-through disk, which custody neither reads nor removes a copy through, and [%2$s] does not list the file — kitsune:media-reconcile --force settles it only from a readable copy on another disk it asks; otherwise copy it over by hand',
                                $disk,
                                $target,
                            ),
                            // Custody takes the two for one place, so neither it nor reconcile will touch this copy — which may be
                            // the only one, or the only one that matches: never advise removing it (review of slice 5b).
                            ($extra & self::NESTS) !== 0 => sprintf(
                                ($extra & self::OWNED) !== 0
                                    ? 'kept: its media directory nests with [%1$s]\'s — compare it with [%1$s]\'s copy by hand'
                                    : 'kept: its media directory nests with [%1$s]\'s, which does not list the file — copy it over by hand',
                                $target,
                            ),
                            /*
                             * Reconcile settles the row from the copies on the disks custody asks; one on a disk it does not
                             * ask — one prune scans only because another row names it — it never sees, and lists the row as
                             * missing while no disk it asks holds the file. Where which disks it asks cannot be told — a
                             * configured media disk that is not configured — the line says so, and the report goes on
                             * (review of slice 5c, twice).
                             */
                            default => match ($asked[$target] ??= self::askedFor($config, $target)) {
                                false => sprintf('kept: the disk its row names does not hold the file, and whether custody asks [%s] cannot be told: a configured media disk cannot be read — run kitsune:media-prune again once it can be', $disk),
                                default => in_array($disk, $asked[$target], true)
                                    ? sprintf('kept: the disk its row names does not hold the file — kitsune:media-reconcile --force settles it from the copies on the disks it asks, [%s] among them', $disk)
                                    : sprintf('kept: the disk its row names does not hold the file, and custody does not ask [%1$s] — kitsune:media-reconcile --force settles it only from a copy on a disk it asks; otherwise copy this one to [%2$s] by hand, checking it against the recorded checksum', $disk, $target),
                            },
                        },
                    ));
                }
            }
        }

        $this->listRows(
            $connection,
            'Awaiting publication — live and public, on a disk that is not the public one, so not reachable at its public URL. kitsune:media-reconcile --force publishes it:',
            static fn (Builder $rows): Builder => MediaCustody::whereStored($rows->whereNull('entries.deleted_at'), 'media_files.visibility', ['public']),
            static fn (stdClass $row): bool => $row->deleted_at === null && $row->visibility === 'public' && (string) $row->disk !== $public,
        );

        if ($servedDisks !== []) {
            $this->listRows(
                $connection,
                'Trashed on a served disk — trashed, and still on a disk the web serves; files trashed before ADR-042 decision 5 are among them. kitsune:media-reconcile --force withdraws it:',
                static fn (Builder $rows): Builder => MediaCustody::whereStored($rows->whereNotNull('entries.deleted_at'), 'media_files.disk', $servedDisks),
                static fn (stdClass $row): bool => $row->deleted_at !== null && in_array((string) $row->disk, $servedDisks, true),
            );
        }
    }

    /**
     * Rows a list names, a line each as they are read — in batches, and in the order they were written.
     *
     * ⚠️ READ AS THEY PRINT, NOT HELD — review of slice 5c. After the public disk moves, every public row is awaiting
     * publication. The query narrows to rows that may belong; PHP decides exactly, since MySQL and MariaDB compare `disk`
     * and `visibility` under the column's collation: without regard to case or accents under every default, and to
     * trailing spaces under a PAD SPACE one. On SQLite the narrowing asks for each value as text and as a BLOB, which
     * never equal each other there (`MediaCustody::whereStored()`, review of slice 5c).
     *
     * @param  Closure(Builder): Builder  $narrow
     * @param  Closure(stdClass): bool  $keep
     */
    private function listRows(Connection $connection, string $heading, Closure $narrow, Closure $keep): void
    {
        $said = false;

        $narrow($connection->table('media_files')
            ->leftJoin('entries', 'entries.id', '=', 'media_files.entry_id')
            ->select(['media_files.id', 'media_files.entry_id', 'media_files.disk', 'media_files.path', 'media_files.visibility', 'entries.deleted_at']))
            ->chunkById(self::BATCH, function (Collection $rows) use ($heading, $keep, &$said): void {
                foreach ($rows as $row) {
                    if (! $keep($row)) {
                        continue;
                    }

                    if (! $said) {
                        $this->line($heading);
                        $said = true;
                    }

                    $this->line(self::rowLine($row));
                }
            }, 'media_files.id', 'id');
    }

    /**
     * An entry's line in the lists, as the listing prints it and the benchmark harness reads it back.
     *
     * ⚠️ `\sprintf`, QUALIFIED, ON PURPOSE — review of slice 5c. Resolved at compile time, it builds a string of the exact
     * size; unqualified in this namespace it keeps the 240-byte buffer it starts with, a 320-byte block for a 70-byte
     * line, and the benchmark holds a hundred thousand of these: some 22 MB more at the floor's 128 MB.
     */
    /**
     * The disks custody asks for a file that belongs on the target, or false where they cannot be told: `asked()` reads
     * every served disk's configuration, and a configured media disk that is not configured stops it.
     *
     * @return list<string>|false
     */
    private static function askedFor(Repository $config, string $target): array|false
    {
        try {
            return MediaCustody::asked($config, $target, $target);
        } catch (Throwable) {
            return false;
        }
    }

    public static function rowLine(stdClass $row): string
    {
        return \sprintf('  entry %d  [%s]  %s', (int) $row->entry_id, (string) $row->disk, (string) $row->path);
    }
}
