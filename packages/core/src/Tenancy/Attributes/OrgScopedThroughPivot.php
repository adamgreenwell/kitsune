<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Attributes;

use Attribute;

/**
 * Org-scoped through a pivot rather than an `org_id` column.
 *
 * ADR-021 lists users among the org-scoped models and is right to, but a user
 * belongs to MANY orgs — `architecture.md` §3 models that through `org_user`.
 * `OrgScope` compares `org_id = current` and simply does not apply, so
 * declaring `#[OrgScoped]` would have been a lie the kernel could not keep.
 *
 * A separate attribute rather than a nullable option on `#[OrgScoped]`,
 * because the two enforce genuinely different things: one reads a column, the
 * other tests membership. Conflating them would make a reviewer check which
 * behaviour a given model got.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OrgScopedThroughPivot
{
    /**
     * @param  string  $table  the pivot, e.g. `org_user`
     * @param  string  $foreignKey  the column on the pivot naming this model
     */
    public function __construct(
        public string $table,
        public string $foreignKey,
        public string $orgKey = 'org_id',
    ) {}
}
