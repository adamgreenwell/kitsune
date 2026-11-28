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
    foreach ([['summary', 'text', ['maxLength' => 20], true], ['related', 'relation', ['targetTypes' => ['lesson']], false]] as [$handle, $type, $settings, $required]) {
        $storage = FieldStorage::create(['handle' => $handle, 'type' => $type, 'settings' => $settings, 'pii_class' => 'none', 'cardinality' => 1]);
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
            'related' => ['type' => 'relation', 'cardinality' => 1, 'required' => false, 'inline' => false],
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
