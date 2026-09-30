<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;
use Throwable;

/**
 * A step of a media file's custody that could not be done, and why — ADR-042 decision 5.
 *
 * @internal
 *
 * The reason is a word a caller can branch on: `coinciding` (two disks are one place), `copy`, `verify` (the copy's
 * bytes do not match), `rename`, `delete`, `unreadable` (the file is there and cannot be read), `unknown` (whether it is
 * there cannot be told), `matches` (a copy the keeper read as absent is there, and is the one that matches), `aliased`
 * (every disk reads the name as another path), `refused` (every disk refuses the name), `misnamed` (a row's path is not written as the disks read it), `read-through-unclaimed` (an orphan or partial copy on a
 * read-through disk), `unheld` (the disk holds no file under the name a listing gave), `read-through` (custody neither reads nor removes a copy
 * through a read-through disk), `root` (whether a local disk holds anything cannot be told: its root cannot be looked at,
 * and its path is `/`). The message names the disk and the path the application stores, never a
 * server path.
 */
final class MediaCustodyFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $disk,
        public readonly string $path,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Refusing to go on with [%s] on the [%s] disk: %s. Nothing that counts as a copy of it was removed.',
            $path,
            $disk,
            match ($reason) {
                'coinciding' => 'the source and the destination are the same file, reached through two disks',
                'copy' => 'the copy could not be written',
                'verify' => 'the copy that was written does not match the file it was copied from',
                'rename' => 'the verified copy could not be moved into place',
                'delete' => 'it could not be deleted',
                'aliased' => 'every disk reads its name as another path, which deleting it would delete — remove it by hand',
                'refused' => 'every disk refuses its name — remove it by hand',
                'misnamed' => 'its row\'s path is not written as the disks read it — correct media_files.path to the path its file '
                    .'is under, as the disks read it, unless another row names that path: a file under the row\'s literal name — '
                    .'a local name holding a backslash, a key with a doubled slash, a control or format character — no disk reads '
                    .'or removes, so it is moved by hand to such a path first',
                'read-through' => 'it is a read-through disk, which custody neither reads nor removes a copy through — a read '
                    .'copies the file into its primary, and a delete removes it from both halves, only one of which was read. '
                    .'It may be the only copy, or the only one that matches the checksum: compare it with the copy where the '
                    .'row belongs by hand before removing it through the disk each half is',
                'read-through-unclaimed' => 'it is on a read-through disk, whose delete removes the path from both halves when only '
                    .'the listed one was asked, and no row keeps it — remove it by hand through the half that holds it',
                'unheld' => 'the disk holds no file under the name it was listed by — gone since the listing, a local file whose '
                    .'name holds a backslash, which the listing gives as a slash, or a link the listing left out, on the file or '
                    .'on a directory above it: remove it by hand if it is still there',
                'unreadable' => 'it exists and cannot be read',
                'root' => 'whether the disk holds anything cannot be told, because its root cannot be looked at for a reason other '
                    .'than its not being there — see that the user running this may search every directory above it (ADR-042 '
                    .'decision 25)',
                'matches' => 'it matches the recorded checksum and the copy kept does not — it read as absent a moment before',
                default => 'whether it exists cannot be told',
            },
        ), 0, $previous);
    }
}
