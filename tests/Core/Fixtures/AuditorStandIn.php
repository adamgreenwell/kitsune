<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\AuditLog;
use Throwable;

/**
 * The auditor, with a failure a test can script — so the write that follows the byte moves can be made to fail there.
 *
 * `Auditor` is final, so this stands beside it rather than extending it: bound under its name, it wraps the real one
 * and passes every call through, except the one it was told to fail.
 */
final class AuditorStandIn
{
    private ?Throwable $throwOnce = null;

    private ?Closure $beforeRecording = null;

    public function __construct(private readonly Auditor $real) {}

    public static function install(): self
    {
        $standIn = new self(app(Auditor::class));
        app()->instance(Auditor::class, $standIn);

        return $standIn;
    }

    /** Throw this from the next record, once. */
    public function throwOnce(Throwable $failure): self
    {
        $this->throwOnce = $failure;

        return $this;
    }

    /** Run this before each record — to register a host's `afterCommit()` inside the write, for instance. */
    public function beforeRecording(Closure $callback): self
    {
        $this->beforeRecording = $callback;

        return $this;
    }

    public function recordOrFail(string $action, ?Model $target = null): AuditLog
    {
        $this->script();

        return $this->real->recordOrFail($action, $target);
    }

    public function record(string $action, ?Model $target = null): ?AuditLog
    {
        $this->script();

        return $this->real->record($action, $target);
    }

    private function script(): void
    {
        if ($this->beforeRecording !== null) {
            ($this->beforeRecording)();
        }

        if ($this->throwOnce !== null) {
            $failure = $this->throwOnce;
            $this->throwOnce = null;

            throw $failure;
        }
    }
}
