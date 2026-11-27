<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

it('adds and rolls back the ordered picker index around existing entries', function (): void {
    $migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000030_index_entries_for_relation_search.php';
    $migration->down();

    $org = Org::create(['name' => 'North', 'slug' => 'north']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'en', 'slug' => 'en', 'name' => 'English']);
    $context->setSite($site);

    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Course maintenance']);

    $orderedColumns = ['org_id', 'title', 'id', 'deleted_at', 'site_id', 'type_handle', 'translation_group'];
    $migration->up();

    expect(array_column(Schema::getIndexes('entries'), 'columns'))
        ->toContain($orderedColumns)
        ->toContain(['site_id', 'updated_at'])
        ->toContain(['site_id', 'entry_type_id', 'updated_at']);

    $migration->down();

    expect(array_column(Schema::getIndexes('entries'), 'columns'))->not->toContain($orderedColumns)
        ->and(Entry::findOrFail($entry->id)->title)->toBe('Course maintenance');

    $migration->up();
});
