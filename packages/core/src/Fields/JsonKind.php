<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * The JSON types a projection accepts, named once rather than per driver.
 *
 * Each engine spells them differently — PostgreSQL's `jsonb_typeof` says
 * `number`, MySQL's `JSON_TYPE` distinguishes INTEGER from DOUBLE from
 * DECIMAL, and SQLite's `json_type` reports booleans as `true` and `false`.
 * The driver translates; nothing above it should have to know.
 */
enum JsonKind
{
    case Number;
    case Boolean;
    case Text;
}
