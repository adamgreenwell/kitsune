<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Tests\LevelZeroTestCase;
use Kitsune\Core\Tests\TestCase;
use Kitsune\Core\Tests\UlidHostTestCase;

uses(TestCase::class)->in(__DIR__.'/Core');

// A host whose users carry ULIDs, run in the same process; the base test case rebuilds the schema between them (#91).
uses(UlidHostTestCase::class)->in(__DIR__.'/UlidHost');

// Transactions as production runs them, with no wrapper to hide level 0 (ADR-042 decision 5); the default leg only.
uses(LevelZeroTestCase::class)->in(__DIR__.'/LevelZero');
