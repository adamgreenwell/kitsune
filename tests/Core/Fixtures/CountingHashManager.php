<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;

/**
 * The application's hasher, counting how often a hash is checked and made — so "exactly one hash check on every sign-in
 * path" is a number a test can read rather than a timing it has to guess.
 */
final class CountingHashManager extends HashManager
{
    public int $checks = 0;

    public int $makes = 0;

    /** Installed as `hash`, so the facade, the `hashed` cast and core all reach it. */
    public static function install(): self
    {
        $counting = new self(app());
        app()->instance('hash', $counting);
        Hash::clearResolvedInstance('hash');

        return $counting;
    }

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = [])
    {
        $this->checks++;

        return parent::check($value, $hashedValue, $options);
    }

    public function make(#[\SensitiveParameter] $value, array $options = [])
    {
        $this->makes++;

        return parent::make($value, $options);
    }
}
