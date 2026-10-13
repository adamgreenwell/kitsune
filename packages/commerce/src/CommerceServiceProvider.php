<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce;

use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleServiceProvider;

/**
 * Commerce enters through the module kernel, and stays optional (ADR-040).
 *
 * @internal First-party only; no extension contract before v1.2.
 *
 * This foundation ships no business models, tables, content types, routes or payment client. Its empty
 * scoping declaration is checked against that footprint, not a promise that future models need no scope.
 * Sellables and their site listings arrive in the next slice, with their actual scope declarations.
 */
final class CommerceServiceProvider extends ModuleServiceProvider
{
    public function migrationPath(): string
    {
        return __DIR__.'/../database/migrations';
    }

    public function uninstall(): void
    {
        CommerceCustody::refuseKeptRows((new Module)->getConnection());
    }
}
