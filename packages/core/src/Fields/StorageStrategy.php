<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * Where a field's value actually lives (ADR-015).
 *
 * Not every field lives in the `values` JSON column, and pretending otherwise
 * is how a CMS ends up unable to answer "what references this image?".
 */
enum StorageStrategy: string
{
    /**
     * A real column on `entries`.
     *
     * A closed set — title, slug, status, published_at — chosen because the
     * platform queries them on every request regardless of entity type. Not
     * user-extensible: adding a fifth is a platform migration, not a setting.
     */
    case Promoted = 'promoted';

    /**
     * Inside the `values` JSON column. The default.
     *
     * Indexable on demand via stored generated columns.
     */
    case Inline = 'inline';

    /**
     * Rows in `entry_relations`.
     *
     * Anything pointing at another entry. Never an ID array in JSON: that
     * cannot answer "what points at me?" without a full scan, and
     * cascade-on-delete becomes application code that will eventually be
     * wrong.
     */
    case Relational = 'relational';
}
