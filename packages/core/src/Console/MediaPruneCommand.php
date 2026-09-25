<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\MediaFile;
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
 * when the listing shows the row naming the disk its state says and that disk holding the path. Under the lock it is
 * asked again: the row as it is there, every copy hashed before the first delete, one that differs removed with both
 * hashes logged (Adam, decisions 2 and 2b), one that alone matches the checksum or cannot be read never. The listing
 * hashes nothing; the rest are `kitsune:media-reconcile`'s, which moves a file to where its row says.
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

    public function handle(): int
    {
        $config = app('config');
        $public = MediaDisks::configured($config, 'public');
        $private = MediaDisks::configured($config, 'private');
        $connection = (new MediaFile)->getConnection();

        /*
         * Every row, read once and past every scope: `media_files` is `#[Unscoped]`, and a console asking on an
         * operator's behalf has no org to narrow by. An installation's media table is smaller than its media
         * directory by definition, and asking per file would be a query per file.
         */
        $rows = $connection->table('media_files')
            ->leftJoin('entries', 'entries.id', '=', 'media_files.entry_id')
            ->get(['media_files.entry_id', 'media_files.disk', 'media_files.path', 'media_files.visibility', 'entries.deleted_at'])
            ->all();

        $claimed = [];
        $byPath = [];

        foreach ($rows as $row) {
            $claimed[$row->disk.':'.$row->path] = true;
            // One row per path: `media_files_path_unique` (Adam, decision 8, 2026-09-25).
            $byPath[(string) $row->path] = $row;
        }

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
        $kitsune = array_values(array_unique([$public, $private, ...array_map(static fn (stdClass $row): string => (string) $row->disk, $rows)]));

        if (! in_array(MediaDisks::PRIVATE, $kitsune, true) && MediaDisks::mayHold($config, MediaDisks::PRIVATE)) {
            $kitsune[] = MediaDisks::PRIVATE;
        }

        $served = [];

        /*
         * ⚠️ A SERVED DISK THAT IS A MEDIA DISK UNDER ANOTHER NAME IS NOT SCANNED — review of slice 5b. Its listing is
         * that disk's, so every file there would read as an extra copy of itself, and removing one would remove the
         * file. Asked only of a disk that can hold anything: asking builds a local disk, which would create its root.
         */
        foreach (array_diff(MediaDisks::servedDisks($config), $kitsune) as $disk) {
            if (! MediaDisks::mayHold($config, $disk)) {
                continue;
            }

            $asPublic = MediaDisks::onePlace($config, $disk, $public);
            $asPrivate = MediaDisks::onePlace($config, $disk, $private);

            if ($asPublic === true) {
                continue;
            }

            if ($asPublic !== false || $asPrivate !== false) {
                $this->warn(sprintf(
                    'Not scanning [%s]: it is, or cannot be told apart from, [%s] — one bucket through two endpoints, or '
                    .'one place — so a file there could be the file itself. kitsune:media-reconcile --force refuses while '
                    .'the private disk is one of them.',
                    $disk,
                    $asPublic !== false ? $public : $private,
                ));

                continue;
            }

            $served[] = $disk;
        }

        $orphans = [];
        $partials = [];
        $extras = [];
        $own = [];
        $failed = 0;

        foreach ([...$kitsune, ...$served] as $disk) {
            $ours = in_array($disk, $kitsune, true);

            try {
                $paths = Storage::disk($disk)->allFiles('media');
            } catch (Throwable $failure) {
                $this->error(sprintf('Could not list [%s]: %s', $disk, $failure->getMessage()));
                $failed++;

                continue;
            }

            foreach ($paths as $path) {
                if (isset($claimed[$disk.':'.$path])) {
                    // The row's own disk lists its path.
                    $own[$path] = true;

                    continue;
                }

                if (str_ends_with($path, MediaBytes::PARTIAL)) {
                    $row = $byPath[substr($path, 0, -strlen(MediaBytes::PARTIAL))] ?? null;

                    if ($row !== null) {
                        $partials[] = ['disk' => $disk, 'path' => $path, 'row' => $row];
                    } elseif ($ours) {
                        $orphans[] = ['disk' => $disk, 'path' => $path];
                    }

                    continue;
                }

                if (isset($byPath[$path])) {
                    $extras[] = ['disk' => $disk, 'path' => $path, 'row' => $byPath[$path]];
                } elseif ($ours) {
                    $orphans[] = ['disk' => $disk, 'path' => $path];
                }
            }
        }

        // Removable when the row names the disk its state says, and that disk listed the path: the lock asks again.
        foreach ($extras as $i => $extra) {
            $extras[$i]['removable'] = (string) $extra['row']->disk === MediaCustody::target($extra['row'], $extra['row'])
                && isset($own[$extra['path']]);
        }

        $removable = array_values(array_filter($extras, static fn (array $extra): bool => $extra['removable']));

        $this->report($orphans, $partials, $extras, $rows, $public, $config);

        if ($orphans === [] && $partials === [] && $removable === []) {
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn(sprintf(
                '%d orphaned file%s, %d leftover partial cop%s and %d removable extra cop%s listed and nothing removed. '
                .'Re-run with --force to delete them; this command is read-only by default because media has no revision '
                .'history and no undo.',
                count($orphans),
                count($orphans) === 1 ? '' : 's',
                count($partials),
                count($partials) === 1 ? 'y' : 'ies',
                count($removable),
                count($removable) === 1 ? 'y' : 'ies',
            ));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
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

        foreach ($orphans as $orphan) {
            try {
                MediaCustody::removeOrphan($connection, $orphan['disk'], $orphan['path']) === MediaCustody::REMOVED
                    ? $removed++
                    : $reclaimed++;
            } catch (Throwable $failure) {
                $this->error(sprintf('Could not remove [%s:%s]: %s', $orphan['disk'], $orphan['path'], $failure->getMessage()));
                $failed++;
            }
        }

        foreach ($partials as $partial) {
            try {
                MediaCustody::removeTemp($connection, (int) $partial['row']->entry_id, $partial['disk'], $partial['path']);
                $removed++;
            } catch (Throwable $failure) {
                $this->error(sprintf('Could not remove [%s:%s]: %s', $partial['disk'], $partial['path'], $failure->getMessage()));
                $failed++;
            }
        }

        $extraRemoved = 0;
        $extraGone = 0;
        $extraKept = 0;

        foreach ($removable as $extra) {
            $entryId = (int) $extra['row']->entry_id;

            try {
                $outcome = MediaCustody::removeExtra($connection, $entryId, $extra['disk']);
            } catch (Throwable $failure) {
                $this->error(sprintf('Could not remove [%s:%s]: %s', $extra['disk'], $extra['path'], $failure->getMessage()));
                $failed++;

                continue;
            }

            match ($outcome) {
                MediaCustody::SETTLED => $extraRemoved++,
                MediaCustody::UNSETTLED => (function () use ($extra, $entryId, &$extraKept): void {
                    $extraKept++;
                    $this->line(sprintf(
                        'Kept [%s:%s], entry %d — under the lock its row did not name the disk its state says, or that disk '
                        .'did not hold the copy kept; the log says which, and kitsune:media-reconcile --entry=%d shows where '
                        .'the file is.',
                        $extra['disk'],
                        $extra['path'],
                        $entryId,
                        $entryId,
                    ));
                })(),
                default => $extraGone++,
            };
        }

        $total = count($orphans) + count($partials);

        $this->info(sprintf(
            'Removed %d of %d orphaned or leftover file%s and %d of %d extra cop%s.%s%s%s',
            $removed,
            $total,
            $total === 1 ? '' : 's',
            $extraRemoved,
            count($removable),
            count($removable) === 1 ? 'y' : 'ies',
            $reclaimed === 0 ? '' : sprintf(' %d kept: a row claimed %s since the listing.', $reclaimed, $reclaimed === 1 ? 'its path' : 'their paths'),
            $extraGone === 0 ? '' : sprintf(' %d extra cop%s gone since the listing.', $extraGone, $extraGone === 1 ? 'y was' : 'ies were'),
            $extraKept === 0 ? '' : sprintf(' %d kept under the lock — kitsune:media-reconcile says why.', $extraKept),
        ));

        /* A disk that refused is reported by the count disagreeing, rather than by silence. */
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every list, each saying why it is there and what settles it.
     *
     * @param  list<array{disk: string, path: string}>  $orphans
     * @param  list<array{disk: string, path: string, row: stdClass}>  $partials
     * @param  list<array{disk: string, path: string, row: stdClass, removable: bool}>  $extras
     * @param  list<stdClass>  $rows
     */
    private function report(array $orphans, array $partials, array $extras, array $rows, string $public, Repository $config): void
    {
        if ($orphans === []) {
            $this->info('No orphaned media files.');
        } else {
            $this->table(['Disk', 'Path'], array_map(static fn (array $o): array => [$o['disk'], $o['path']], $orphans));
        }

        if ($partials !== []) {
            $this->line('Leftover partial copies — custody was writing each beside the path its row names, and did not finish:');
            $this->table(
                ['Disk', 'Path', 'Entry'],
                array_map(static fn (array $p): array => [$p['disk'], $p['path'], (int) $p['row']->entry_id], $partials),
            );
        }

        if ($extras !== []) {
            $this->line('Extra copies — at a path a row names, on a disk it does not. With --force each is removed under its row\'s lock once that row names the disk it belongs on and that disk holds the copy kept — one that differs with a warning naming both hashes; the rest are kitsune:media-reconcile\'s:');
            $this->table(
                ['Disk', 'Path', 'Entry', 'State', 'Row names', 'Belongs on', 'With --force'],
                array_map(static fn (array $k): array => [
                    $k['disk'],
                    $k['path'],
                    (int) $k['row']->entry_id,
                    $k['row']->deleted_at === null ? 'live' : 'trashed',
                    (string) $k['row']->disk,
                    MediaCustody::target($k['row'], $k['row']),
                    match (true) {
                        $k['removable'] => 'removed, once asked again under the lock',
                        (string) $k['row']->disk !== MediaCustody::target($k['row'], $k['row']) => 'kept: kitsune:media-reconcile moves its row first',
                        default => 'kept: the disk its row names does not hold the file',
                    },
                ], $extras),
            );
        }

        $awaiting = array_values(array_filter($rows, static fn (stdClass $row): bool => $row->deleted_at === null
            && $row->visibility === 'public'
            && $row->disk !== $public));

        if ($awaiting !== []) {
            $this->line('Awaiting publication — live and public, on a disk that is not the public one, so not reachable at its public URL. kitsune:media-reconcile --force publishes it:');
            $this->table(['Entry', 'Disk', 'Path'], array_map(static fn (stdClass $row): array => [(int) $row->entry_id, (string) $row->disk, (string) $row->path], $awaiting));
        }

        $servedDisks = MediaDisks::servedDisks($config);
        $exposed = array_values(array_filter($rows, static fn (stdClass $row): bool => $row->deleted_at !== null
            && in_array($row->disk, $servedDisks, true)));

        if ($exposed !== []) {
            $this->line('Trashed on a served disk — trashed, and still on a disk the web serves; files trashed before ADR-042 decision 5 are among them. kitsune:media-reconcile --force withdraws it:');
            $this->table(['Entry', 'Disk', 'Path'], array_map(static fn (stdClass $row): array => [(int) $row->entry_id, (string) $row->disk, (string) $row->path], $exposed));
        }
    }
}
