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
 * may be the only good copy, so each is listed as kept and none is ever deleted here. A partial copy custody was
 * writing beside a path (`MediaBytes::PARTIAL`) is decided by the path it was written for: the table decides every
 * delete, and the suffix only chooses which row's lock to take. Nothing is hashed.
 *
 * ⚠️ EVERY DELETE IS RECHECKED UNDER CUSTODY'S LOCK. The listing is read without one, so a row written since — an
 * upload, a restore — could claim a file listed as an orphan; `MediaCustody::removeOrphan()` asks again with the lock
 * held, and keeps the file if a row now names its path.
 */
final class MediaPruneCommand extends Command
{
    protected $signature = 'kitsune:media-prune {--force : actually delete the orphans and leftover partial copies rather than listing them}';

    protected $description = 'Find media files on disk that no media_files row claims (ADR-041, ADR-042)';

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
            $byPath[(string) $row->path] ??= $row;
        }

        /*
         * ⚠️ THE CONFIGURED DISKS AND EVERY DISK A ROW NAMES — K. ADR-042 moved private media off `local` and left the
         * rows already naming it valid, so a refused disposal of one of those leaves its orphan there. Asking the table
         * which disks it uses is the command's own rule: a disk nothing names and nothing is configured to use is not
         * this command's to sweep, because its `media/` folder may be the host's.
         *
         * ⚠️ AND THE DISKS THE WEB SERVES — V — FOR KEPT COPIES ONLY. A copy custody could not remove from a disk that
         * serves it is the one an operator most needs to see; a file there that no row's path names is the host's,
         * and is not listed. A local one whose root does not exist holds nothing, and is not built: building it would
         * create the directory.
         */
        $kitsune = array_values(array_unique([$public, $private, ...array_map(static fn (stdClass $row): string => (string) $row->disk, $rows)]));
        $served = array_values(array_filter(
            array_diff(MediaDisks::servedDisks($config), $kitsune),
            static function (string $disk) use ($config): bool {
                $resolved = MediaDisks::resolved($config, $disk);

                return $resolved['root'] === null || is_dir($resolved['root']);
            },
        ));

        $orphans = [];
        $partials = [];
        $kept = [];
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
                    $kept[] = ['disk' => $disk, 'path' => $path, 'row' => $byPath[$path]];
                } elseif ($ours) {
                    $orphans[] = ['disk' => $disk, 'path' => $path];
                }
            }
        }

        $this->report($orphans, $partials, $kept, $rows, $public, $config);

        if ($orphans === [] && $partials === []) {
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn(sprintf(
                '%d orphaned file%s and %d leftover partial cop%s listed and nothing removed. Re-run with --force to '
                .'delete them; this command is read-only by default because media has no revision history and no undo.',
                count($orphans),
                count($orphans) === 1 ? '' : 's',
                count($partials),
                count($partials) === 1 ? 'y' : 'ies',
            ));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        try {
            MediaDisks::refuseUnsafeMediaDisks($config);
        } catch (RuntimeException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
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

        $total = count($orphans) + count($partials);

        $this->info(sprintf(
            'Removed %d of %d orphaned or leftover file%s.%s',
            $removed,
            $total,
            $total === 1 ? '' : 's',
            $reclaimed === 0 ? '' : sprintf(' %d kept: a row claimed %s since the listing.', $reclaimed, $reclaimed === 1 ? 'its path' : 'their paths'),
        ));

        /* A disk that refused is reported by the count disagreeing, rather than by silence. */
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every list, each saying why it is there and what settles it.
     *
     * @param  list<array{disk: string, path: string}>  $orphans
     * @param  list<array{disk: string, path: string, row: stdClass}>  $partials
     * @param  list<array{disk: string, path: string, row: stdClass}>  $kept
     * @param  list<stdClass>  $rows
     */
    private function report(array $orphans, array $partials, array $kept, array $rows, string $public, Repository $config): void
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

        if ($kept !== []) {
            $this->line('Kept copies — at a path a row names, on a disk it does not. Each may be the only good copy, so none is removed here; deleting and restoring the entry, or re-trashing it, settles it:');
            $this->table(
                ['Disk', 'Path', 'Entry', 'State', 'Row names', 'Belongs on'],
                array_map(static fn (array $k): array => [
                    $k['disk'],
                    $k['path'],
                    (int) $k['row']->entry_id,
                    $k['row']->deleted_at === null ? 'live' : 'trashed',
                    (string) $k['row']->disk,
                    self::target($k['row'], $config),
                ], $kept),
            );
        }

        $awaiting = array_values(array_filter($rows, static fn (stdClass $row): bool => $row->deleted_at === null
            && $row->visibility === 'public'
            && $row->disk !== $public));

        if ($awaiting !== []) {
            $this->line('Awaiting publication — live and public, on a disk that is not the public one, so not reachable at its public URL. Deleting and restoring the entry publishes it:');
            $this->table(['Entry', 'Disk', 'Path'], array_map(static fn (stdClass $row): array => [(int) $row->entry_id, (string) $row->disk, (string) $row->path], $awaiting));
        }

        $servedDisks = MediaDisks::servedDisks($config);
        $exposed = array_values(array_filter($rows, static fn (stdClass $row): bool => $row->deleted_at !== null
            && in_array($row->disk, $servedDisks, true)));

        if ($exposed !== []) {
            $this->line('Trashed on a served disk — trashed, and still on a disk the web serves; files trashed before ADR-042 decision 5 are among them. Restoring the entry and trashing it again withdraws it:');
            $this->table(['Entry', 'Disk', 'Path'], array_map(static fn (stdClass $row): array => [(int) $row->entry_id, (string) $row->disk, (string) $row->path], $exposed));
        }
    }

    private static function target(stdClass $row, Repository $config): string
    {
        return MediaDisks::configured($config, $row->deleted_at === null && $row->visibility === 'public' ? 'public' : 'private');
    }
}
