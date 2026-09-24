<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\MediaFile;

/**
 * Find media files on disk that no row claims, and optionally remove them — ADR-041.
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
 * ⚠️ IT ASKS THE DATABASE, NEVER THE FILENAME. A file is an orphan because no `media_files` row names its
 * (disk, path) pair — not because it looks old, sits in an unexpected folder, or has a name the command does
 * not recognise. Anything cleverer is a heuristic, and a heuristic that deletes is a bug waiting for an
 * operator whose layout differs.
 */
final class MediaPruneCommand extends Command
{
    protected $signature = 'kitsune:media-prune {--force : actually delete the orphans rather than listing them}';

    protected $description = 'Find media files on disk that no media_files row claims (ADR-041)';

    public function handle(): int
    {
        /*
         * ⚠️ THE CONFIGURED DISKS AND EVERY DISK A ROW NAMES. ADR-042 moved private media off `local` and left the
         * rows already naming it valid — delivered and disposed of from `local` — so a refused disposal of one of
         * those leaves its orphan there, and `MediaDisposal` sends the operator here to find it. Asking the table
         * which disks it uses is the command's own rule: a disk nothing names and nothing is configured to use is
         * not this command's to sweep, because its `media/` folder may be the host's.
         */
        $disks = array_values(array_unique([
            MediaLibrary::diskFor('public'),
            MediaLibrary::diskFor('private'),
            ...MediaFile::query()->distinct()->pluck('disk')->map(static fn (mixed $disk): string => (string) $disk)->all(),
        ]));

        /*
         * Every path the table knows about, keyed "disk:path". Read once: an installation's media table is
         * smaller than its media directory by definition, and asking per file would be a query per file.
         */
        $claimed = MediaFile::query()
            ->get(['disk', 'path'])
            ->map(static fn (MediaFile $file): string => $file->disk.':'.$file->path)
            ->flip();

        $orphans = [];

        foreach ($disks as $disk) {
            foreach (Storage::disk($disk)->allFiles('media') as $path) {
                if (! $claimed->has($disk.':'.$path)) {
                    $orphans[] = ['disk' => $disk, 'path' => $path];
                }
            }
        }

        if ($orphans === []) {
            $this->info('No orphaned media files. Every file under media/ is claimed by a row.');

            return self::SUCCESS;
        }

        $this->table(
            ['Disk', 'Path'],
            array_map(static fn (array $o): array => [$o['disk'], $o['path']], $orphans),
        );

        if (! $this->option('force')) {
            $this->warn(sprintf(
                '%d orphaned file%s listed and nothing removed. Re-run with --force to delete them; this '
                .'command is read-only by default because media has no revision history and no undo.',
                count($orphans),
                count($orphans) === 1 ? '' : 's',
            ));

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($orphans as $orphan) {
            if (Storage::disk($orphan['disk'])->delete($orphan['path'])) {
                $removed++;
            }
        }

        $this->info(sprintf('Removed %d of %d orphaned file%s.', $removed, count($orphans), count($orphans) === 1 ? '' : 's'));

        /* A disk that refused is reported by the count disagreeing, rather than by silence. */
        return $removed === count($orphans) ? self::SUCCESS : self::FAILURE;
    }
}
