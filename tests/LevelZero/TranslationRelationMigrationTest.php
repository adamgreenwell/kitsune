<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

it('upgrades populated relation storage and refuses to roll back while a group link is stored', function (): void {
    $migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000020_add_group_targets_to_entry_relations.php';
    $migration->down();

    $org = Org::create(['name' => 'North', 'slug' => 'north']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'en', 'slug' => 'en', 'name' => 'English']);
    $context->setSite($site);

    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $source = Entry::create(['entry_type_id' => $type->id, 'title' => 'Source']);
    $target = Entry::create(['entry_type_id' => $type->id, 'title' => 'Target']);
    $pin = DB::table('entry_relations')->insertGetId([
        'org_id' => $org->id, 'source_entry_id' => $source->id, 'target_entry_id' => $target->id, 'ordering' => 7,
    ]);

    $migration->up();

    $stored = DB::table('entry_relations')->where('id', $pin)->first();
    expect($stored->target_entry_id)->toBe($target->id)
        ->and($stored->target_group_id)->toBeNull()
        ->and($stored->ordering)->toBe(7);

    $uuid = '115d5120-ba2f-4bb0-8888-9262df5870ce';
    $group = EntryTranslationGroup::create(['uuid' => $uuid]);
    $elsewhere = Org::create(['name' => 'South', 'slug' => 'south']);
    $context->setOrg($elsewhere);
    $otherGroup = EntryTranslationGroup::create(['uuid' => $uuid]);

    $link = [
        'org_id' => $org->id, 'source_entry_id' => $source->id, 'ordering' => 8,
    ];
    expect(fn () => DB::table('entry_relations')->insert($link + ['target_group_id' => $otherGroup->id]))
        ->toThrow(QueryException::class);

    $linkId = DB::table('entry_relations')->insertGetId($link + ['target_group_id' => $group->id]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Only row pins can remain')
        ->and(DB::table('entry_relations')->where('id', $linkId)->value('target_group_id'))->toBe($group->id);

    $context->setSite($site);
    $target->forceDelete();
    expect(DB::table('entry_relations')->where('id', $pin)->exists())->toBeFalse()
        ->and(DB::table('entry_relations')->where('id', $linkId)->exists())->toBeTrue();

    DB::table('entry_relations')->where('id', $linkId)->delete();
    $migration->down();

    expect(Schema::hasColumn('entry_relations', 'target_group_id'))->toBeFalse();
});
