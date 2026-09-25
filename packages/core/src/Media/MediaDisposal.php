<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use LogicException;
use stdClass;
use Throwable;

/**
 * Remove the bytes when the entry that owned them is force-deleted — ADR-041, ordered by ADR-042 decision 5.
 *
 * @internal
 *
 * ⚠️ THIS CANNOT BE A MODEL EVENT, AND THE MIGRATION SAYS WHY. `media_files.entry_id` cascades, so a
 * force-deleted entry takes the row with it *inside the database*, where nothing can reach a disk — and a
 * `deleting` hook on `MediaFile` never fires for a cascade at all. Worse, a hook on `Entry` would miss
 * `Entry::query()->forceDelete()`, which dispatches nothing: the shape `RefusesCascadingDeletes` exists for,
 * which this project has now found eight times. So disposal is asked of the BUILDER, where the instance path
 * and the bulk path both arrive.
 *
 * ⚠️ ROWS FIRST, THEN BYTES — the mirror of how `MediaLibrary` stores them: if the delete failed after the files were
 * gone, a surviving entry would point at nothing. ADR-042 decision 5 orders both halves around the commit. The
 * force-delete withdraws every copy the web could serve before it commits, keeping a verified one on the private
 * disk; this removes what is left, only once nothing on the connection is left to commit, and for each file it asks
 * again with the entry locked — a delete that did not commit, or a path a row has claimed since the erasure, keeps its
 * bytes. Paths are unique (Adam, decision 8, 2026-09-25), so while the erased row existed no other could name its path;
 * the check guards a row written after the erasure committed, naming the path it freed.
 *
 * ⚠️ EVERY DISK THAT COULD HOLD THE PATH, not only the one the row named: the private copy withdrawal kept, a stray
 * copy on a served disk, a partial copy beside any of them.
 *
 * ⚠️ FAILING TO DELETE A FILE DOES NOT FAIL THE DELETE. A read-only mount, an object store that is briefly
 * unreachable, a file an operator already removed by hand — none of those should keep a force-delete from
 * completing, because the row is the record and the operator asked for it to go. What they must not do is pass
 * silently, so each one is logged with the disk and path, and `kitsune:media-prune` is the repair.
 */
final class MediaDisposal
{
    /**
     * Remove every copy of each force-deleted entry's file, reporting rather than throwing.
     *
     * @param  list<array{entry_id: int, disk: string, path: string}>  $files  as the force-delete locked them
     * @param  bool  $committed  false when called from the force-delete's failure path, whose commit may or may not
     *                           have landed: rows still there are then expected, not reported
     * @return int how many files were removed from every disk
     *
     * @throws LogicException inside an open transaction
     */
    public static function remove(Connection $connection, array $files, bool $committed = true): int
    {
        if (! MediaCustody::isOutermost($connection)) {
            throw new LogicException(
                'Refusing to dispose of media files inside an open transaction: the force-delete it follows could still '
                .'roll back (ADR-042 decision 5). Run it through MediaCustody::whenOutermost().'
            );
        }

        $removed = 0;

        foreach ($files as $file) {
            try {
                $removed += MediaCustody::locked($connection, $file['entry_id'], static function (?stdClass $entry, ?stdClass $row) use ($connection, $file, $committed): int {
                    if ($entry !== null || $row !== null) {
                        if ($committed) {
                            Log::warning(sprintf(
                                'Kitsune kept the bytes of entry %d: its force-delete was reported as committed, but its '
                                .'rows are still there (ADR-042 decision 5).',
                                $file['entry_id'],
                            ));
                        }

                        return 0;
                    }

                    if ($connection->table('media_files')->where('path', $file['path'])->exists()) {
                        Log::warning(sprintf(
                            'Kitsune kept [%s], the file of force-deleted entry %d: another media_files row names the same '
                            .'path (ADR-042 decision 5).',
                            $file['path'],
                            $file['entry_id'],
                        ));

                        return 0;
                    }

                    return self::removeEverywhere($file) ? 1 : 0;
                });
            } catch (Throwable $e) {
                self::couldNotRun($file, $e, $committed);
            }
        }

        return $removed;
    }

    /**
     * Delete the path, and a partial copy beside it, on every disk that could hold one.
     *
     * @param  array{entry_id: int, disk: string, path: string}  $file
     * @return bool whether every delete succeeded
     */
    private static function removeEverywhere(array $file): bool
    {
        $config = app('config');
        $private = [MediaDisks::configured($config, 'private'), MediaDisks::PRIVATE];
        $served = MediaDisks::servedDisks($config);
        $disks = array_values(array_unique([$file['disk'], MediaDisks::configured($config, 'public'), ...$private, ...$served]));
        $clean = true;

        foreach ($disks as $disk) {
            // A served local disk nothing configures or names, whose root does not exist, holds nothing, and is not built.
            if ($disk !== $file['disk'] && in_array($disk, $served, true) && ! MediaDisks::mayHold($config, $disk)) {
                continue;
            }

            try {
                $paths = MediaBytes::local($disk) ? [$file['path'], MediaBytes::partial($file['path'])] : [$file['path']];
            } catch (Throwable $e) {
                self::report($disk, $file['path'], $e->getMessage(), in_array($disk, $private, true), in_array($disk, $served, true));
                $clean = false;

                continue;
            }

            foreach ($paths as $path) {
                try {
                    MediaBytes::delete($disk, $path);
                } catch (Throwable $e) {
                    self::report($disk, $path, $e->getMessage(), in_array($disk, $private, true), in_array($disk, $served, true));
                    $clean = false;
                }
            }
        }

        return $clean;
    }

    /**
     * Say what a disposal that could not run left, and where — ADR-042 decision 5.
     *
     * ⚠️ NOT WHERE THE ROW POINTED, BUT WHERE THE BYTES MAY BE — review of slice 5b. This once reported the disk the row
     * named as though its copy were still there, and said of every disk, the public one and core's private one
     * included, that Kitsune does not serve it. What is left may be on any disk disposal asks: prune sweeps the
     * configured ones and core's private disk, and the disk the row named only while a row names it; a served disk
     * nothing names is not swept, so a copy there is removed by hand.
     *
     * ⚠️ AND ONLY WHEN THE ERASURE REPORTED ITS COMMIT. From the force-delete's failure path it may not have committed —
     * a withdrawal refused, a COMMIT that failed — and then the entry and its file may be exactly where they were.
     *
     * @param  array{entry_id: int, disk: string, path: string}  $file
     */
    private static function couldNotRun(array $file, Throwable $e, bool $committed): void
    {
        if (! $committed) {
            Log::warning(sprintf(
                'Kitsune could not dispose of [%s], the file of entry %d, after its force-delete reported failure: '
                .'disposal could not run — %s. The force-delete may not have committed: if the entry is still there, '
                .'kitsune:media-reconcile --entry=%d says where its file is; if it is gone, kitsune:media-prune lists what '
                .'is left on the disks it sweeps (ADR-042 decision 5).',
                $file['path'],
                $file['entry_id'],
                $e->getMessage(),
                $file['entry_id'],
            ));

            return;
        }

        try {
            $config = app('config');
            $swept = [MediaDisks::configured($config, 'public'), MediaDisks::configured($config, 'private'), MediaDisks::PRIVATE];
            $elsewhere = ! in_array($file['disk'], [...$swept, ...MediaDisks::servedDisks($config)], true);
        } catch (Throwable) {
            $elsewhere = true;
        }

        Log::warning(sprintf(
            'Kitsune could not dispose of [%s], the file of force-deleted entry %d, whose row named [%s]: disposal could not '
            .'run — %s. `kitsune:media-prune --force` removes what is left on the configured media disks and core\'s '
            .'private disk%s; a copy on any other disk the web serves stays until it is removed by hand (ADR-042 '
            .'decision 5).',
            $file['path'],
            $file['entry_id'],
            $file['disk'],
            $e->getMessage(),
            $elsewhere ? sprintf(', and on [%s] while any row names it — after that, remove it by hand', $file['disk']) : '',
        ));
    }

    private static function report(string $disk, string $path, string $why, bool $private = false, bool $served = false): void
    {
        Log::warning(sprintf(
            'Kitsune could not remove a media file whose entry was force-deleted: [%s:%s] — %s. %s',
            $disk,
            $path,
            $why,
            match (true) {
                $served => 'It is still on the web: withdrawal removed every served copy before the delete committed, '
                    .'so this one appeared after it. Remove it by hand.',
                $private => 'It is a copy on a disk nothing serves; `kitsune:media-prune` removes it.',
                default => sprintf('It is a copy on [%s], which Kitsune does not serve; `kitsune:media-prune` removes it while any row names [%s]; after that, remove it by hand.', $disk, $disk),
            },
        ));
    }
}
