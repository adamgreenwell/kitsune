<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Models\MediaFile;
use Throwable;

/**
 * Remove the bytes when the entry that owned them is force-deleted — ADR-041.
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
 * ⚠️ ROWS FIRST, THEN BYTES — the mirror of how `MediaLibrary` stores them, and the same argument. On the way
 * in, bytes are written before rows because an unreferenced file costs disk while a row pointing at nothing is
 * a broken asset. On the way out, rows go before bytes for exactly that reason: if the delete fails after the
 * files are gone, the surviving entry points at nothing. Both orders choose the orphaned file.
 *
 * ⚠️ FAILING TO DELETE A FILE DOES NOT FAIL THE DELETE. A read-only mount, an object store that is briefly
 * unreachable, a file an operator already removed by hand — none of those should keep a force-delete from
 * completing, because the row is the record and the operator asked for it to go. What they must not do is pass
 * silently, so each one is logged with the disk and path, and `kitsune:media-prune` is the repair.
 */
final class MediaDisposal
{
    /**
     * What the entries matched by this query have on disk, read BEFORE they are deleted.
     *
     * @param  list<int>  $entryIds
     * @return list<array{disk: string, path: string}>
     */
    public static function filesFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /*
         * Past the scopes: `MediaFile` is `#[Unscoped]` and reached through its entry, and the entries whose
         * ids these are have already been through the scoped query that selected them. Asking again here would
         * narrow by a context the caller may not be in — a console force-deleting on an operator's behalf.
         */
        return MediaFile::query()
            ->whereIn('entry_id', $entryIds)
            ->get(['disk', 'path'])
            ->map(static fn (MediaFile $file): array => [
                'disk' => (string) $file->disk,
                'path' => (string) $file->path,
            ])
            ->all();
    }

    /**
     * Remove the bytes, reporting rather than throwing.
     *
     * @param  list<array{disk: string, path: string}>  $files
     * @return int how many were removed
     */
    public static function remove(array $files): int
    {
        $removed = 0;

        foreach ($files as $file) {
            try {
                /*
                 * `delete()` returns true for a path that was already absent, which is the right answer here:
                 * the operator asked for the bytes to be gone and they are. Only a disk that refuses is
                 * interesting.
                 */
                if (Storage::disk($file['disk'])->delete($file['path'])) {
                    $removed++;

                    continue;
                }

                self::report($file, 'the disk reported the delete as unsuccessful');
            } catch (Throwable $e) {
                self::report($file, $e->getMessage());
            }
        }

        return $removed;
    }

    /**
     * @param  array{disk: string, path: string}  $file
     */
    private static function report(array $file, string $why): void
    {
        Log::warning(sprintf(
            'Kitsune could not remove a media file whose entry was force-deleted: [%s:%s] — %s. The row is '
            .'gone and the bytes are not, which is an orphan rather than a leak. `kitsune:media-prune` finds '
            .'files no row claims.',
            $file['disk'],
            $file['path'],
            $why,
        ));
    }
}
