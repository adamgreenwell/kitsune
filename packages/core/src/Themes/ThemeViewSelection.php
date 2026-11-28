<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

/**
 * An installation's publication choices for the current render.
 *
 * @internal Candidate for the versioned Blade contract.
 *
 * @phpstan-type Selection array{fields: list<string>, hostData: array<string, mixed>}
 */
interface ThemeViewSelection
{
    /**
     * @return Selection
     */
    public function forRender(int $orgId, int $siteId, ?string $entryType): array;
}
