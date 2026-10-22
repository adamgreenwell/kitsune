<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Closure;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;

/** A real local disk whose open can fail independently of its existence check. */
final class StaffMediaStreamDisk extends LocalFilesystemAdapter
{
    public int $opens = 0;

    public ?string $failure = null;

    /** @var resource|null */
    public $opened = null;

    /** A test's real stream resource, including a PSR-7 wrapper with unavailable metadata. */
    public ?Closure $factory = null;

    /** Runs after opening, before the controller can inspect or answer with the handle. */
    public ?Closure $afterOpen = null;

    public function readStream($path)
    {
        $this->opens++;

        if ($this->failure === 'throw') {
            throw UnableToReadFile::fromLocation($path);
        }

        if ($this->failure === 'false') {
            return false;
        }

        $this->opened = $this->factory === null ? parent::readStream($path) : ($this->factory)($path);

        if ($this->afterOpen !== null) {
            ($this->afterOpen)($this->opened, $path);
        }

        return $this->opened;
    }
}
