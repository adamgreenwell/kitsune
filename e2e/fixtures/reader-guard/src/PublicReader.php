<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\ReaderGuard;

use Illuminate\Foundation\Auth\User;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A reader as a host declares one — ADR-037: its own guard and table, `#[OrgScoped]` on its own `org_id`, integer keys.
 * The twin of `tests/Core/Fixtures/TestReader.php`.
 *
 * ⚠️ "PUBLIC READER", NEVER "READER" ALONE: the browser suite's `reader@kitsune.test` is a staff copy-editor.
 */
#[OrgScoped]
final class PublicReader extends User
{
    use EnforcesScope;

    protected $table = 'e2e_public_readers';

    protected $guarded = [];
}
