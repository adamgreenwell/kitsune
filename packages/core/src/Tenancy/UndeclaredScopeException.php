<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

use RuntimeException;

final class UndeclaredScopeException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "[{$model}] does not declare a scope. Every Kitsune model must carry exactly one of "
            .'#[SiteScoped], #[OrgScoped], #[OrgScopedThroughPivot] or #[Unscoped]. There is no '
            .'default: a model that forgot to declare one would otherwise be readable across every '
            .'org on the installation. Choose deliberately — #[Unscoped] is a real option, it just '
            .'has to be said. And if the model belongs to many orgs through a pivot rather than an '
            .'org_id column, it is the pivot one: #[OrgScoped] would compare a column that is not '
            .'there.'
        );
    }
}
