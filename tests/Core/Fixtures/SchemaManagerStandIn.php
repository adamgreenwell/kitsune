<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Closure;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\SchemaManager;
use Throwable;

/**
 * The schema manager, with a failure a test can script — so an apply can be made to stop after its rows committed.
 *
 * `SchemaManager` is final, so this stands beside it rather than extending it, as `AuditorStandIn` does: bound under
 * its name, it wraps the real one and passes every sync and drop through, except the one it was told to fail.
 *
 * ⚠️ OR RECORDS THE SYNC AND RUNS NO DDL, for `tests/Core`. A generated column is DDL, which commits implicitly on
 * MySQL and MariaDB — there, the `RefreshDatabase` wrapper itself, so the test's rows and the column outlive it and
 * fail the next test. The level-0 suite, which has no wrapper, is where the real sync is asked for.
 */
final class SchemaManagerStandIn
{
    private ?Throwable $throwOnce = null;

    public int $synced = 0;

    /** @var list<string> the storage handles synced, in order */
    public array $syncedHandles = [];

    /** @var list<string> the storage handles whose column a reverse asked to drop, in order */
    public array $droppedHandles = [];

    /** Run before each sync records — where a test stages what happens after a commit and before the finish. */
    public ?Closure $onSync = null;

    /** Run before each drop records. */
    public ?Closure $onDrop = null;

    private bool $passThrough = true;

    public function __construct(private readonly SchemaManager $real) {}

    public static function install(): self
    {
        $standIn = new self(app(SchemaManager::class));
        app()->instance(SchemaManager::class, $standIn);

        return $standIn;
    }

    /** Record each sync and run none of it. */
    public function recordOnly(): self
    {
        $this->passThrough = false;

        return $this;
    }

    /** Throw this from the next sync or drop, once. */
    public function throwOnce(Throwable $failure): self
    {
        $this->throwOnce = $failure;

        return $this;
    }

    public function sync(FieldStorage $storage): void
    {
        if ($this->onSync !== null) {
            ($this->onSync)($storage);
        }

        if ($this->throwOnce !== null) {
            $failure = $this->throwOnce;
            $this->throwOnce = null;

            throw $failure;
        }

        if ($this->passThrough) {
            $this->real->sync($storage);
        }

        $this->synced++;
        $this->syncedHandles[] = (string) $storage->handle;
    }

    public function dropIndex(FieldStorage $storage): void
    {
        if ($this->onDrop !== null) {
            ($this->onDrop)($storage);
        }

        if ($this->throwOnce !== null) {
            $failure = $this->throwOnce;
            $this->throwOnce = null;

            throw $failure;
        }

        if ($this->passThrough) {
            $this->real->dropIndex($storage);
        }

        $this->droppedHandles[] = (string) $storage->handle;
    }
}
