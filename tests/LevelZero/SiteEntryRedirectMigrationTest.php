<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Tenancy\Context;

it('adds and removes redirect schema without losing existing entries or retained redirects', function (): void {
    $migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000021_create_site_entry_redirects.php';
    $migration->down();

    $org = Org::create(['name' => 'North', 'slug' => 'north']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'en', 'slug' => 'en', 'name' => 'English']);
    $context->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Kept entry', 'slug' => 'current']);

    $migration->up();
    $redirect = SiteEntryRedirect::create([
        'source_type' => 'article', 'source_slug' => 'previous', 'source_key' => SiteEntryRedirect::sourceKey('article', 'previous'),
        'target_entry_id' => $entry->id,
    ]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove or preserve')
        ->and($redirect->fresh()->target_entry_id)->toBe($entry->id)
        ->and($entry->fresh()->title)->toBe('Kept entry');

    $redirect->delete();
    $migration->down();
    expect(Schema::hasTable('site_entry_redirects'))->toBeFalse()
        ->and(Schema::hasIndex('entries', 'entries_org_identity'))->toBeFalse()
        ->and(DB::table('entries')->where('id', $entry->id)->value('slug'))->toBe('current');
});
