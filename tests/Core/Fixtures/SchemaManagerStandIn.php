<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\SchemaManager;
use Throwable;

/**
 * The schema manager, with a failure a test can script — so an apply can be made to stop after its rows committed.
 *
 * `SchemaManager` is final, so this stands beside it rather than extending it, as `AuditorStandIn` does: bound under
 * its name, it wraps the real one and passes every sync through, except the one it was told to fail.
 */
final class SchemaManagerStandIn
{
    private ?Throwable $throwOnce = null;

    public int $synced = 0;

    public function __construct(private readonly SchemaManager $real) {}

    public static function install(): self
    {
        $standIn = new self(app(SchemaManager::class));
        app()->instance(SchemaManager::class, $standIn);

        return $standIn;
    }

    /** Throw this from the next sync, once. */
    public function throwOnce(Throwable $failure): self
    {
        $this->throwOnce = $failure;

        return $this;
    }

    public function sync(FieldStorage $storage): void
    {
        if ($this->throwOnce !== null) {
            $failure = $this->throwOnce;
            $this->throwOnce = null;

            throw $failure;
        }

        $this->real->sync($storage);
        $this->synced++;
    }
}
