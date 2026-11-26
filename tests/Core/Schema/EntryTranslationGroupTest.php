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
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationResolver;
use Kitsune\Core\Relations\EntryRelationWriter;
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

it('restores mixed relations in order after the last translation is deleted', function (): void {
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $pin = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Pin']);
    $member = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);

    $source->syncFieldRelations($storage, [$pin->id]);
    $pinsOnly = $source->revisionHistory()->first();
    $link = EntryRelation::create([
        'org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id,
        'target_group_id' => $group->id, 'ordering' => 1,
    ]);
    $mixed = $source->revisionHistory()->first();
    $expected = [$storage->id => [$pin->id, ['group' => TRANSLATION_GROUP_UUID]]];

    expect($pinsOnly->relation_state)->toBe([$storage->id => [$pin->id]])
        ->and($mixed->relation_state)->toBe($expected);

    $link->update(['ordering' => 0]);
    $member->forceDelete();
    $source->restoreRevision($pinsOnly);
    expect($source->relationState())->toBe([$storage->id => [$pin->id]]);

    $source->restoreRevision($mixed);
    expect($source->relationState())->toBe($expected)
        ->and($source->revisionHistory()->first()->relation_state)->toBe($expected)
        ->and(EntryTranslationGroup::publishedMember($group->id))->toBeNull();
});

it('refuses a group link in a subject identifier relation and counts mixed links against cardinality', function (): void {
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $pin = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Pin']);
    translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => 1,
    ]);
    $field = Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Links']);
    $row = ['org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id];

    expect(fn () => EntryRelation::create($row))->toThrow(RuntimeException::class, 'exactly one')
        ->and(fn () => EntryRelation::create($row + ['target_entry_id' => $pin->id, 'target_group_id' => $group->id]))
        ->toThrow(RuntimeException::class, 'exactly one');

    $source->syncFieldRelations($storage, [$pin->id]);
    expect(fn () => EntryRelation::create($row + ['target_group_id' => $group->id]))
        ->toThrow(RuntimeException::class, 'already has that many');

    $source->syncFieldRelations($storage, []);
    $link = EntryRelation::create($row + ['target_group_id' => $group->id]);
    expect(fn () => $this->type->update(['subject_field_id' => $field->id]))
        ->toThrow(RuntimeException::class, 'requires row pins');

    $link->delete();
    $this->type->update(['subject_field_id' => $field->id]);
    expect(fn () => EntryRelation::create($row + ['target_group_id' => $group->id]))
        ->toThrow(RuntimeException::class, 'requires a row pin');
});

it('redacts a stored group reference and keeps a restored revision from recreating it', function (): void {
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Links']);
    EntryRelation::create([
        'org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id,
        'target_group_id' => $group->id,
    ]);
    $revision = $source->revisionHistory()->first();

    expect($source->redactField('links'))->toBe(2)
        ->and($revision->fresh()->relation_state)->toBe([$storage->id => null]);

    $source->restoreRevision($revision);
    expect($source->relationState())->toBe([])
        ->and(EntryTranslationGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

it('validates a target edit against the stored link when another editor already changed its kind', function (): void {
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $pin = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Pin']);
    $different = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Different pin']);
    translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $source->syncFieldRelations($storage, [$pin->id]);
    $link = EntryRelation::query()->sole();
    $stale = $link->fresh();
    $link->update(['target_entry_id' => null, 'target_group_id' => $group->id]);

    expect(fn () => $stale->update(['target_entry_id' => $different->id]))->toThrow(RuntimeException::class, 'exactly one')
        ->and($source->relationState())->toBe([$storage->id => [['group' => TRANSLATION_GROUP_UUID]]]);
});

it('resolves mixed targets in public order using current Site, field restrictions and caller access', function (): void {
    $source = Entry::create([
        'entry_type_id' => $this->type->id, 'site_id' => null, 'status' => 'published', 'title' => 'Shared source',
    ]);
    $fixed = Entry::create([
        'entry_type_id' => $this->type->id, 'site_id' => null, 'status' => 'published', 'title' => 'Shared pin',
    ]);
    $english = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $group = EntryTranslationGroup::query()->sole();
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none',
        'cardinality' => -1, 'settings' => ['targetTypes' => ['article']],
    ]);
    Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Links']);
    $source->syncFieldRelations($storage, [$english->id, $fixed->id]);
    EntryRelation::create([
        'org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id,
        'target_group_id' => $group->id, 'ordering' => 2,
    ]);

    $resolver = new EntryRelationResolver;
    $allow = fn (Entry $entry): bool => true;
    $public = fn (): array => array_map(fn (array $link): array => [$link['entry']->id, $link['group']],
        $resolver->resolve($source, $storage, true, $allow));

    expect($public())->toBe([[$english->id, null], [$fixed->id, null], [$english->id, TRANSLATION_GROUP_UUID]]);

    $second = Entry::create(['entry_type_id' => $this->type->id, 'status' => 'published', 'title' => 'Second source']);
    $second->syncFieldRelations($storage, [$fixed->id]);
    DB::enableQueryLog();
    try {
        $resolver->resolveMany([$source], [$storage], true, $allow);
        $singleReadQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $page = $resolver->resolveMany([$source, $second], [$storage], true, $allow);
        expect($singleReadQueries)->toBeGreaterThan(0)
            ->and(count(DB::getQueryLog()))->toBe($singleReadQueries)
            ->and($page[$second->id][$storage->id][0]['entry']->id)->toBe($fixed->id);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $this->context->setSite($this->fr);
    expect($public())->toBe([[$fixed->id, null]]);

    $french = translationMember($this->type, TRANSLATION_GROUP_UUID, 'draft');
    expect($public())->toBe([[$fixed->id, null]])
        ->and(array_column($resolver->resolve($source, $storage, false, $allow), 'group'))->toBe([null, TRANSLATION_GROUP_UUID]);

    $french->update(['status' => 'published']);
    expect($public())->toBe([[$fixed->id, null], [$french->id, TRANSLATION_GROUP_UUID]])
        ->and(array_column($resolver->resolve($source, $storage, true, fn (Entry $entry): bool => $entry->id !== $french->id), 'group'))
        ->toBe([null])
        ->and($resolver->resolve($source, $storage, true, fn (Entry $entry): bool => $entry->id !== $source->id))->toBe([]);

    $otherType = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'category', 'name' => 'Category', 'plural_name' => 'Categories',
    ]);
    $french->update(['entry_type_id' => $otherType->id]);
    expect($public())->toBe([[$fixed->id, null]]);

    $french->delete();
    expect($public())->toBe([[$fixed->id, null]]);

    $this->context->forget()->setOrg($this->org);
    expect($public())->toBe([]);
});

it('authors a group by default, permits a row pin and preserves retained links in one revision', function (): void {
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $otherSource = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Other source']);
    $member = translationMember($this->type, TRANSLATION_GROUP_UUID);
    $plain = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Ungrouped']);
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Links']);

    $writer = new EntryRelationWriter;
    $allow = fn (Entry $entry): bool => true;

    $writer->replace($source, $storage, [['entry' => $member->id], ['entry' => $plain->id], ['entry' => $member->id, 'pin' => true]], $allow, $allow);

    expect($source->revisions()->count())->toBe(2)
        ->and($source->relationState())->toBe([$storage->id => [['group' => TRANSLATION_GROUP_UUID], $plain->id, $member->id]])
        ->and($source->linkedIdsForField($storage))->toBe([$plain->id, $member->id]);

    $groupLink = EntryRelation::query()->whereNotNull('target_group_id')->sole()->id;
    $member->forceDelete();
    expect(fn () => $writer->replace($otherSource, $storage, [['keep' => $groupLink]], $allow, $allow))
        ->toThrow(ValidationException::class, 'retained relation');

    $writer->replace($source, $storage, [['entry' => $plain->id], ['keep' => $groupLink]], $allow, $allow);
    expect($source->relationState())->toBe([$storage->id => [$plain->id, ['group' => TRANSLATION_GROUP_UUID]]])
        ->and($source->revisions()->count())->toBe(3);

    $deny = fn (Entry $entry): bool => false;
    expect(fn () => $writer->replace($source, $storage, [['entry' => $plain->id, 'follow' => true]], $allow, $allow))
        ->toThrow(ValidationException::class, 'can no longer be followed')
        ->and(fn () => $writer->replace($source, $storage, [], $deny, $allow))->toThrow(ValidationException::class, 'cannot be edited')
        ->and(fn () => $writer->replace($source, $storage, [['entry' => $plain->id]], $allow, $deny))
        ->toThrow(ValidationException::class, 'selected entries is unavailable')
        ->and($source->relationState())->toBe([$storage->id => [$plain->id, ['group' => TRANSLATION_GROUP_UUID]]]);
});
