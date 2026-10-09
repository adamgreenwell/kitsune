<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Timebox;

/**
 * Laravel's `Timebox`, recording each call's budget, whether it may return early, and how many reader lookups ran inside
 * it — and sleeping not at all, so a test that requests a link a hundred times does not wait twenty seconds.
 *
 * ⚠️ `call()` IS RECORDED, NOT `usleep()`: the sleep sees only the remainder, never the budget asked for. And a lookup of
 * the `readers` table outside every call is counted too, so work moved out of the box is seen (review).
 */
final class RecordingTimebox extends Timebox
{
    /** @var list<array{microseconds: int, early: bool, lookups: int}> */
    public array $calls = [];

    /** Reader lookups that ran outside every call, since `install()`. */
    public int $lookupsOutside = 0;

    private ?int $inside = null;

    public static function install(): self
    {
        app()->instance(Timebox::class, $timebox = new self);

        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use ($timebox): void {
            if (preg_match('/\bfrom\s+["`]?readers["`]?\s/i', $query->sql) !== 1) {
                return;
            }

            $timebox->inside !== null ? $timebox->inside++ : $timebox->lookupsOutside++;
        });

        return $timebox;
    }

    public function call(callable $callback, int $microseconds)
    {
        return parent::call(function (Timebox $box) use ($callback, $microseconds) {
            $this->inside = 0;

            try {
                return $callback($box);
            } finally {
                $this->calls[] = ['microseconds' => $microseconds, 'early' => $this->earlyReturn, 'lookups' => $this->inside];
                $this->inside = null;
            }
        }, $microseconds);
    }

    protected function usleep(int $microseconds): void {}
}
