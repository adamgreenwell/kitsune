<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Content\SiteDraftFields;
use Kitsune\Core\Content\SiteDraftWriter;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->context = app(Context::class);
    $org = Org::create(['slug' => 'draft-fields', 'name' => 'Draft fields']);
    $foreign = Org::create(['slug' => 'foreign-fields', 'name' => 'Foreign fields']);
    foreach (['main' => $org, 'sibling' => $org, 'foreignSite' => $foreign] as $name => $owner) {
        $this->context->setOrg($owner);
        $this->{$name} = Site::create(['name' => $name, 'handle' => strtolower($name), 'slug' => strtolower($name)]);
    }
    $this->context->setSite($this->main);
    $this->type = EntryType::create(['org_id' => $org->id, 'handle' => 'lesson', 'name' => 'Lesson', 'plural_name' => 'Lessons']);
    foreach ([['summary', 'text', ['maxLength' => 20], true, 1], ['related', 'relation', ['targetTypes' => ['lesson']], false, 2]] as [$handle, $type, $settings, $required, $cardinality]) {
        $storage = FieldStorage::create(['handle' => $handle, 'type' => $type, 'settings' => $settings, 'pii_class' => 'none', 'cardinality' => $cardinality]);
        $this->type->fields()->create(['field_storage_id' => $storage->id, 'label' => $handle, 'is_required' => $required]);
    }

    $this->actor = TestUser::create(['email' => 'candidate@kitsune.test']);
    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    DB::table('org_user')->insert(['org_id' => $org->id, 'user_id' => $this->actor->getKey()]);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $this->actor->getKey()]);
    $this->fields = app(SiteDraftFields::class);
});

afterEach(fn () => $this->context->forget());

it('describes declared fields for a reader and validates inline changes for an authorized draft author', function (): void {
    $this->role->grant('entry.lesson.view');
    $expected = new FieldDeclaration('summary', 'text', 'Summary', 'none', settings: ['maxLength' => 20], isRequired: true);
    $schema = $this->fields->describe($this->main->id, 'lesson', $this->actor, [$expected]);
    expect($schema['matches'])->toBeTrue()
        ->and($schema['fields'])->toBe([
            'summary' => ['type' => 'text', 'cardinality' => 1, 'required' => true, 'inline' => true],
            'related' => ['type' => 'relation', 'cardinality' => 2, 'required' => false, 'inline' => false],
        ])
        ->and(fn () => $this->fields->validate($this->main->id, 'lesson', $this->actor, $schema['fingerprint'], ['summary' => 'Hello']))
        ->toThrow(DomainException::class, 'unavailable');

    $this->role->grant('entry.lesson.create');
    $validate = fn (array $values): array => $this->fields->validate($this->main->id, 'lesson', $this->actor, $schema['fingerprint'], $values);
    expect($validate(['summary' => 'Hello']))->toBe(['values' => ['summary' => 'Hello'], 'errors' => []])
        ->and($validate([])['errors'])->toHaveKey('values.summary')
        ->and($validate(['summary' => str_repeat('a', 21)])['errors'])->toHaveKey('values.summary')
        ->and($validate(['summary' => 'Hello', 'related' => [1]])['errors'])->toHaveKey('values.related')
        ->and($validate(['summary' => 'Hello', 'unexpected' => true])['errors'])->toHaveKey('values')
        ->and(Entry::count())->toBe(0);
});

it('saves content and ordered links in one revision and rolls back a refused relation', function (): void {
    $writer = app(SiteDraftWriter::class);
    foreach (['view', 'create', 'update'] as $action) {
        $this->role->grant('entry.lesson.'.$action);
    }
    $first = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'First', 'status' => 'draft']);
    $second = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Second', 'status' => 'draft']);
    $this->context->setSite($this->sibling);
    $sibling = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Sibling', 'status' => 'draft']);
    $this->context->setSite($this->main);
    $fieldId = FieldStorage::query()->where('handle', 'related')->sole()->id;
    $schema = $this->fields->describe($this->main->id, 'lesson', $this->actor);
    $save = fn (array $changes, ?int $id = null, ?int $revision = null): array => $writer->save(
        $this->main->id, 'lesson', $this->actor, $schema['fingerprint'], $changes, $id, $revision,
    );
    expect(fn () => $save(['title' => 'New', 'values' => ['summary' => 'Initial']]))
        ->toThrow(DomainException::class, 'active staff actor');
    $this->actingAs($this->actor);

    $create = $save([
        'title' => 'New', 'slug' => 'new', 'values' => ['summary' => 'Initial', 'related' => [$second->id, $first->id]],
    ]);
    expect($create['errors'])->toBe([])->and($create['revision'])->toBeInt();
    $entry = Entry::query()->findOrFail($create['id']);
    $ordered = [$fieldId => [$second->id, $first->id]];
    expect($entry->status)->toBe('draft')
        ->and($entry->relationState())->toBe($ordered)
        ->and($entry->revisions()->sole()->relation_state)->toBe($ordered);

    $failed = $save(['title' => 'Must roll back', 'values' => ['related' => [$sibling->id]]], $entry->id, $create['revision']);
    expect($failed['errors'])->toHaveKey('values.related')
        ->and($entry->fresh()->title)->toBe('New')
        ->and($entry->relationState())->toBe($ordered)
        ->and($entry->revisions()->count())->toBe(1);

    $update = $save(['title' => 'Updated', 'values' => ['related' => [$first->id, $second->id]]], $entry->id, $create['revision']);
    expect($update['errors'])->toBe([])
        ->and($entry->fresh()->values)->toBe(['summary' => 'Initial'])
        ->and($entry->revisions()->count())->toBe(2)
        ->and($entry->revisions()->findOrFail($update['revision'])->relation_state)->toBe([$fieldId => [$first->id, $second->id]])
        ->and(fn () => $save(['title' => 'Stale'], $entry->id, $create['revision']))->toThrow(DomainException::class, 'revision changed')
        ->and($save(['status' => 'published'], $entry->id, $update['revision'])['errors'])->toHaveKey('draft')
        ->and($entry->fresh()->status)->toBe('draft');
});

it('requires the reviewed field schema at the draft write boundary', function (): void {
    foreach (['view', 'create'] as $action) {
        $this->role->grant('entry.lesson.'.$action);
    }
    $this->actingAs($this->actor);
    $schema = $this->fields->describe($this->main->id, 'lesson', $this->actor);
    $this->type->fields()->where('label', 'summary')->firstOrFail()->update(['label' => 'Revised summary']);

    expect(fn () => app(SiteDraftWriter::class)->save(
        $this->main->id, 'lesson', $this->actor, $schema['fingerprint'], ['title' => 'Old approval', 'values' => ['summary' => 'Initial']],
    ))->toThrow(DomainException::class, 'schema changed')
        ->and(Entry::count())->toBe(0);
});

it('uses the declared field when a type promotes its slug', function (): void {
    foreach (['view', 'create'] as $action) {
        $this->role->grant('entry.lesson.'.$action);
    }
    $this->actingAs($this->actor);
    $storage = FieldStorage::create(['handle' => 'permalink', 'type' => 'slug', 'pii_class' => 'none', 'cardinality' => 1]);
    $this->type->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Permalink']);
    $schema = $this->fields->describe($this->main->id, 'lesson', $this->actor);
    $save = fn (array $values): array => app(SiteDraftWriter::class)->save(
        $this->main->id, 'lesson', $this->actor, $schema['fingerprint'], ['title' => 'Lesson'] + $values,
    );

    expect($save(['slug' => 'direct', 'values' => ['summary' => 'Hello']])['errors'])->toHaveKey('slug');
    $written = $save(['values' => ['permalink' => 'declared', 'summary' => 'Hello']]);
    expect($written['errors'])->toBe([])
        ->and(Entry::query()->findOrFail($written['id'])->slug)->toBe('declared');
});

it('requires the current Site, enabled type, current schema and an exact draft on update', function (): void {
    expect(fn () => $this->fields->describe($this->main->id, 'lesson', $this->actor))->toThrow(DomainException::class, 'unavailable');
    foreach (['view', 'create', 'update'] as $action) {
        $this->role->grant('entry.lesson.'.$action);
    }
    $schema = $this->fields->describe($this->main->id, 'lesson', $this->actor);
    $mainDraft = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Main draft', 'status' => 'draft']);
    $this->context->setSite($this->sibling);
    $siblingDraft = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Sibling draft', 'status' => 'draft']);
    expect(fn () => $this->fields->describe($this->main->id, 'lesson', $this->actor))->toThrow(DomainException::class, 'unavailable')
        ->and($this->fields->describe($this->sibling->id, 'lesson', $this->actor)['fingerprint'])->not->toBe($schema['fingerprint']);
    $this->context->setSite($this->foreignSite);
    expect(fn () => $this->fields->describe($this->foreignSite->id, 'lesson', $this->actor))->toThrow(DomainException::class, 'unavailable');
    $this->context->setSite($this->main);

    $validate = fn (?int $entryId): array => $this->fields->validate($this->main->id, 'lesson', $this->actor, $schema['fingerprint'], [], $entryId);
    expect($validate($mainDraft->id))->toBe(['values' => [], 'errors' => []])
        ->and(fn () => $validate($siblingDraft->id))->toThrow(DomainException::class, 'unavailable');

    $this->type->fields()->where('label', 'summary')->firstOrFail()->update(['is_required' => false]);
    expect(fn () => $validate($mainDraft->id))->toThrow(DomainException::class, 'schema changed');

    EntryTypeAvailability::create([
        'entry_type_id' => $this->type->id, 'scope_type' => 'site', 'scope_id' => $this->main->id, 'is_enabled' => false,
    ]);
    expect(fn () => $this->fields->describe($this->main->id, 'lesson', $this->actor))->toThrow(DomainException::class, 'unavailable');
});
