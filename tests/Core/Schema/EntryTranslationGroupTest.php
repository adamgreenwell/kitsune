<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

const TRANSLATION_GROUP_UUID = '115d5120-ba2f-4bb0-8888-9262df5870ce';
const DELETED_TRANSLATION_GROUP_UUID = '256d200e-4c46-4812-9874-878f297ddd32';

beforeEach(function (): void {
    $this->context = app(Context::class);
    $this->org = Org::create(['name' => 'North', 'slug' => 'north']);
    $this->context->setOrg($this->org);
    $this->en = Site::create(['handle' => 'en', 'slug' => 'en', 'name' => 'English']);
    $this->fr = Site::create(['handle' => 'fr', 'slug' => 'fr', 'name' => 'French']);
    $this->context->setSite($this->en);
    $this->type = EntryType::create([
        'org_id' => $this->org->id,
        'handle' => 'article',
        'name' => 'Article',
        'plural_name' => 'Articles',
    ]);
});

function translationMember(EntryType $type, string $uuid, string $status = 'published'): Entry
{
    return Entry::create([
        'entry_type_id' => $type->id,
        'translation_group' => $uuid,
        'status' => $status,
        'title' => 'Translation',
    ]);
}

it('backfills one identity per Org and UUID, including deleted-only groups', function (): void {
    // MySQL and MariaDB commit DDL, so use isolated tables on a separate connection.
    $configuration = DB::connection()->getConfig();
    $configuration['prefix'] = 'tg_'.bin2hex(random_bytes(4)).'_';
    $configuration['prefix_indexes'] = true;
    config(['database.connections.translation_backfill' => $configuration]);
    $db = DB::connection('translation_backfill');
    $schema = $db->getSchemaBuilder();

    $migration = require __DIR__.'/../../../packages/core/database/migrations/0001_01_01_000019_create_entry_translation_groups.php';

    try {
        $schema->create('orgs', fn (Blueprint $table) => $table->id());
        $schema->create('entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id');
            $table->uuid('translation_group')->nullable();
            $table->softDeletes();
        });
        $db->table('orgs')->insert([['id' => 1], ['id' => 2]]);
        $deleted = now()->format('Y-m-d H:i:s');
        $historical = [
            ['id' => 1, 'org_id' => 1, 'translation_group' => TRANSLATION_GROUP_UUID, 'deleted_at' => null],
            ['id' => 2, 'org_id' => 1, 'translation_group' => TRANSLATION_GROUP_UUID, 'deleted_at' => null],
            ['id' => 3, 'org_id' => 1, 'translation_group' => DELETED_TRANSLATION_GROUP_UUID, 'deleted_at' => $deleted],
            ['id' => 4, 'org_id' => 2, 'translation_group' => TRANSLATION_GROUP_UUID, 'deleted_at' => null],
            ['id' => 5, 'org_id' => 2, 'translation_group' => null, 'deleted_at' => null],
        ];
        $db->table('entries')->insert($historical);

        DB::usingConnection('translation_backfill', fn () => $migration->up());

        $identities = $db->table('entry_translation_groups')->orderBy('org_id')->orderBy('uuid')->get(['org_id', 'uuid']);
        expect($identities->map(fn (object $row) => [(int) $row->org_id, $row->uuid])->all())
            ->toBe([[1, TRANSLATION_GROUP_UUID], [1, DELETED_TRANSLATION_GROUP_UUID], [2, TRANSLATION_GROUP_UUID]])
            ->and($db->table('entries')->orderBy('id')->get()->map(fn (object $row) => [
                'id' => (int) $row->id,
                'org_id' => (int) $row->org_id,
                'translation_group' => $row->translation_group,
                'deleted_at' => $row->deleted_at,
            ])->all())->toBe($historical);

        DB::usingConnection('translation_backfill', fn () => $migration->down());

        expect($schema->hasTable('entry_translation_groups'))->toBeFalse()
            ->and($db->table('entries')->count())->toBe(5);
    } finally {
        $schema->dropIfExists('entry_translation_groups');
        $schema->dropIfExists('entries');
        $schema->dropIfExists('orgs');
        DB::purge('translation_backfill');
    }
});

it('resolves only the published member on the current Site and retains an empty group', function (): void {
    $group = EntryTranslationGroup::create(['uuid' => TRANSLATION_GROUP_UUID]);
    $en = translationMember($this->type, $group->uuid);

    expect(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($en->id);

    $this->context->setSite($this->fr);
    expect(EntryTranslationGroup::publishedMember($group->id))->toBeNull();

    $fr = translationMember($this->type, $group->uuid, 'draft');
    expect(EntryTranslationGroup::publishedMember($group->id))->toBeNull();

    $fr->update(['status' => 'published']);
    expect(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($fr->id);

    $fr->delete();
    expect(EntryTranslationGroup::publishedMember($group->id))->toBeNull();
    $fr->forceDelete();
    $this->context->setSite($this->en);
    $en->forceDelete();

    expect(EntryTranslationGroup::find($group->id)?->uuid)->toBe($group->uuid)
        ->and(EntryTranslationGroup::publishedMember($group->id))->toBeNull();

    $replacement = translationMember($this->type, $group->uuid);
    expect(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($replacement->id);
});

it('requires both an Org scope and a Site even when another Org uses the same group UUID', function (): void {
    $group = EntryTranslationGroup::create(['uuid' => TRANSLATION_GROUP_UUID]);
    translationMember($this->type, $group->uuid);

    $other = Org::create(['name' => 'South', 'slug' => 'south']);
    $this->context->setOrg($other);
    $site = Site::create(['handle' => 'es', 'slug' => 'es', 'name' => 'Spanish']);
    $this->context->setSite($site);
    $type = EntryType::create([
        'org_id' => $other->id,
        'handle' => 'article',
        'name' => 'Article',
        'plural_name' => 'Articles',
    ]);
    $otherGroup = EntryTranslationGroup::create(['uuid' => $group->uuid]);
    $member = translationMember($type, $otherGroup->uuid);

    expect(EntryTranslationGroup::find($group->id))->toBeNull()
        ->and(EntryTranslationGroup::publishedMember($group->id))->toBeNull()
        ->and(EntryTranslationGroup::publishedMember($otherGroup->id)?->id)->toBe($member->id);

    $this->context->forget()->setOrg($other);
    expect(EntryTranslationGroup::find($otherGroup->id)?->id)->toBe($otherGroup->id)
        ->and(EntryTranslationGroup::publishedMember($otherGroup->id))->toBeNull();

    $this->context->forget();
    expect(EntryTranslationGroup::find($otherGroup->id))->toBeNull()
        ->and(EntryTranslationGroup::publishedMember($otherGroup->id))->toBeNull();
});

it('allows only one identity for the UUID within an Org', function (): void {
    EntryTranslationGroup::create(['uuid' => TRANSLATION_GROUP_UUID]);

    expect(fn () => DB::transaction(fn () => EntryTranslationGroup::create(['uuid' => TRANSLATION_GROUP_UUID])))
        ->toThrow(QueryException::class);
});

it('registers a new group on create and reuses it when another Site joins through a quiet save', function (): void {
    $en = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->where('uuid', TRANSLATION_GROUP_UUID)->sole();

    expect(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($en->id);

    $this->context->setSite($this->fr);
    $fr = Entry::create(['entry_type_id' => $this->type->id, 'status' => 'published', 'title' => 'French translation']);
    $fr->translation_group = TRANSLATION_GROUP_UUID;
    $fr->saveQuietly();

    expect(EntryTranslationGroup::query()->count())->toBe(1)
        ->and(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($fr->id);
});

it('keeps the prior membership if a save tries to set an invalid group or move its member to Org-shared', function (): void {
    $member = translationMember($this->type, TRANSLATION_GROUP_UUID);

    expect(fn () => $member->update(['translation_group' => 'invalid']))->toThrow(RuntimeException::class, 'valid group UUID')
        ->and($member->fresh()->translation_group)->toBe(TRANSLATION_GROUP_UUID)
        ->and(fn () => $member->fresh()->update(['site_id' => null]))->toThrow(RuntimeException::class, 'Site member')
        ->and($member->fresh()->site_id)->toBe($this->en->id)
        ->and(EntryTranslationGroup::query()->count())->toBe(1);
});

it('holds a group identity fixed after creation', function (): void {
    $member = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();

    expect(fn () => $group->update(['uuid' => DELETED_TRANSLATION_GROUP_UUID]))->toThrow(RuntimeException::class, 'fixed')
        ->and(EntryTranslationGroup::publishedMember($group->id)?->id)->toBe($member->id);
});

it('preserves an empty group through ordinary deletes and lets deletion of the owning Org remove it', function (): void {
    $member = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $member->forceDelete();

    expect(fn () => $group->deleteQuietly())->toThrow(RuntimeException::class, 'only be removed with its Org')
        ->and(fn () => EntryTranslationGroup::query()->whereKey($group->id)->delete())
        ->toThrow(RuntimeException::class, 'only be removed with its Org');

    $this->context->forget();
    expect(fn () => EntryTranslationGroup::query()->forceDelete())->toThrow(RuntimeException::class, 'only be removed with its Org');

    $this->org->forceDelete();
    expect(DB::table('entry_translation_groups')->where('id', $group->id)->exists())->toBeFalse();
});
