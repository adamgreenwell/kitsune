<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Throwable;

/**
 * The skeleton's reader with host code that fails — a lazy `readerEmail()` whose own query is refused, a base query that
 * throws before any SQL, or a plain bug — with whatever the test sets, and otherwise the skeleton's behaviour.
 */
#[OrgScoped]
class ThrowingAddressReader extends Reader
{
    /** What `readerEmail()` throws; the test that sets it puts it back. */
    public static ?Throwable $failure = null;

    /** What building the model's query throws; the test that sets it puts it back. */
    public static ?Throwable $queryFailure = null;

    /** @return Builder<static> */
    public function newQuery(): Builder
    {
        if (self::$queryFailure !== null) {
            throw self::$queryFailure;
        }

        return parent::newQuery();
    }

    public function readerEmail(): string
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        return parent::readerEmail();
    }
}
