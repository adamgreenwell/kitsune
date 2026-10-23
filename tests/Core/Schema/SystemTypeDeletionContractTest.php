<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Contract', 'slug' => 'deletion-contract']);
    $this->rival = Org::create(['name' => 'Rival', 'slug' => 'deletion-rival']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'deletion-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);
});

afterEach(fn () => app(Context::class)->forget());

function deletionContractType(bool $system, ?int $orgId, string $handle = 'contract_type'): EntryType
{
    return EntryType::create([
        'org_id' => $orgId, 'handle' => $handle, 'name' => 'Contract', 'plural_name' => 'Contracts', 'is_system' => $system,
    ]);
}

function deleteContractType(EntryType $type, string $door): mixed
{
    return match ($door) {
        'model' => $type->delete(),
        'quiet' => $type->deleteQuietly(),
        'eventless' => EntryType::withoutEvents(fn () => $type->delete()),
        'destroy' => EntryType::destroy($type->getKey()),
        'force destroy' => EntryType::forceDestroy($type->getKey()),
        'delete or fail' => $type->deleteOrFail(),
        'model force' => $type->forceDelete(),
        'builder' => EntryType::query()->whereKey($type->getKey())->delete(),
        'projected builder' => EntryType::query()->select('id')->whereKey($type->getKey())->delete(),
        'force builder' => EntryType::query()->whereKey($type->getKey())->forceDelete(),
        'projected force' => EntryType::query()->select('id')->whereKey($type->getKey())->forceDelete(),
        'suspended' => ScopeWrites::suspend(fn () => EntryType::query()->whereKey($type->getKey())->delete()),
    };
}

dataset('entry type deletion doors', [
    'model', 'quiet', 'eventless', 'destroy', 'force destroy', 'delete or fail', 'model force', 'builder', 'projected builder', 'force builder', 'projected force', 'suspended',
]);

it('allows programmatic deletion of an unused type regardless of its descriptive flag', function (bool $system, bool $global, string $door): void {
    $type = deletionContractType($system, $global ? null : $this->org->id);

    deleteContractType($type, $door);

    expect(EntryType::query()->whereKey($type->id)->exists())->toBeFalse();
})->with([false, true])->with([false, true])->with('entry type deletion doors');

it('preserves a type and all referenced data through every guarded delete door', function (bool $system, string $reference, string $door): void {
    $type = deletionContractType($system, $this->org->id);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Kept']);

    if ($reference === 'trash') {
        $entry->delete();
    } elseif ($reference === 'former type') {
        $next = deletionContractType(false, $this->org->id, 'next_type');
        $entry->update(['entry_type_id' => $next->id]);
    }

    $revisions = EntryRevision::query()->where('entry_type_id', $type->id)->count();
    expect($revisions)->toBeGreaterThan(0);

    expect(fn () => deleteContractType($type, $door))->toThrow(
        RuntimeException::class,
        $reference === 'former type' ? 'revision' : 'would delete them by cascade',
    );

    expect(EntryType::query()->whereKey($type->id)->exists())->toBeTrue()
        ->and(Entry::withTrashed()->whereKey($entry->id)->exists())->toBeTrue()
        ->and(EntryRevision::query()->where('entry_type_id', $type->id)->count())->toBe($revisions);
})->with([false, true])->with(['live', 'trash', 'former type'])->with('entry type deletion doors');

it('authorizes admin deletion by owner authority and org ownership rather than the flag', function (bool $system, bool $owner, string $ownership): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $user = TestUser::create(['email' => 'contract@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->id, 'user_id' => $user->id]);
    if ($owner) {
        $role = Role::create(['handle' => 'contract_owner', 'name' => 'Owner', 'is_owner' => true]);
        $role->assignTo($user->id);
    }
    Auth::guard('web')->setUser($user);
    $orgId = match ($ownership) {
        'own' => $this->org->id,
        'rival' => $this->rival->id,
        'global' => null,
    };
    $type = deletionContractType($system, $orgId);

    expect(EntryTypeResource::canDelete($type))->toBe($owner && $ownership === 'own')
        ->and(EntryTypeResource::canDeleteAny())->toBe($owner);
})->with([false, true])->with([false, true])->with(['own', 'rival', 'global']);

it('keeps every row in a mixed admin selection when any row refuses', function (bool $system, string $refusal): void {
    $empty = deletionContractType($system, $this->org->id, 'empty_type');
    $other = deletionContractType($system, match ($refusal) {
        'global' => null,
        'rival' => $this->rival->id,
        default => $this->org->id,
    }, 'other_type');
    if ($refusal === 'entries') {
        Entry::create(['entry_type_id' => $other->id, 'title' => 'Kept']);
    }

    expect(fn () => EntryTypeResource::deleteSelected(collect([$empty, $other])))
        ->toThrow(RuntimeException::class);

    expect(EntryType::query()->whereKey([$empty->id, $other->id])->count())->toBe(2);
})->with([false, true])->with(['global', 'rival', 'entries']);

it('allows an authorized selection of unused org types with either flag', function (bool $system): void {
    $type = deletionContractType($system, $this->org->id);

    // The action's authority is tested above; this helper enforces ownership and atomicity.
    EntryTypeResource::deleteSelected(collect([$type]));

    expect(EntryType::query()->whereKey($type->id)->exists())->toBeFalse();
})->with([false, true]);

it('counts a global types references in another org including trash', function (bool $system, bool $trashed, string $door): void {
    $type = deletionContractType($system, null);
    app(Context::class)->setOrg($this->rival);
    $site = Site::create(['handle' => 'rival', 'slug' => 'deletion-rival-site', 'name' => 'Rival']);
    app(Context::class)->setSite($site);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Another orgs data']);
    if ($trashed) {
        $entry->delete();
    }
    app(Context::class)->setSite($this->site);

    expect(fn () => deleteContractType($type, $door))
        ->toThrow(RuntimeException::class, 'would delete them by cascade');

    expect(DB::table('entries')->where('id', $entry->id)->exists())->toBeTrue()
        ->and(EntryType::query()->whereKey($type->id)->exists())->toBeTrue();
})->with([false, true])->with([false, true])->with('entry type deletion doors');

it('refuses a truncate regardless of the descriptive flag', function (bool $system): void {
    $type = deletionContractType($system, $this->org->id);

    expect(fn () => EntryType::query()->truncate())->toThrow(RuntimeException::class, 'truncate() cannot be used');

    expect(EntryType::query()->whereKey($type->id)->exists())->toBeTrue();
})->with([false, true]);

it('makes no PHP cascade guarantee for direct SQL', function (bool $system): void {
    $type = deletionContractType($system, $this->org->id);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Direct SQL is outside the guard']);

    DB::table('entry_types')->where('id', $type->id)->delete();

    expect(DB::table('entries')->where('id', $entry->id)->exists())->toBeFalse()
        ->and(DB::table('entry_revisions')->where('entry_id', $entry->id)->exists())->toBeFalse();
})->with([false, true]);

it('retains schema on org soft deletion and cascades it only on permanent org teardown', function (bool $system): void {
    $type = deletionContractType($system, $this->org->id);
    $global = deletionContractType($system, null, 'shared_type');
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Removed with org']);
    expect($entry->revisions()->count())->toBeGreaterThan(0);

    $this->org->delete();
    expect(EntryType::query()->whereKey($type->id)->exists())->toBeTrue();
    $this->org->forceDelete();

    expect(EntryType::query()->whereKey($type->id)->exists())->toBeFalse()
        ->and(DB::table('entries')->where('id', $entry->id)->exists())->toBeFalse()
        ->and(DB::table('entry_revisions')->where('entry_id', $entry->id)->exists())->toBeFalse()
        ->and(EntryType::query()->whereKey($global->id)->exists())->toBeTrue();
})->with([false, true]);
