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
 * there cannot be told). The message names the disk and the path the application stores, never a server path.
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
                'unreadable' => 'it exists and cannot be read',
                default => 'whether it exists cannot be told',
            },
        ), 0, $previous);
    }
}
