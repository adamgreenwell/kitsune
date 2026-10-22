<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;

/** A real local disk whose open can fail independently of its existence check. */
final class StaffMediaStreamDisk extends LocalFilesystemAdapter
{
    public int $opens = 0;

    public ?string $failure = null;

    /** @var resource|null */
    public $opened = null;

    public function readStream($path)
    {
        $this->opens++;

        if ($this->failure === 'throw') {
            throw UnableToReadFile::fromLocation($path);
        }

        if ($this->failure === 'false') {
            return false;
        }

        return $this->opened = parent::readStream($path);
    }
}
