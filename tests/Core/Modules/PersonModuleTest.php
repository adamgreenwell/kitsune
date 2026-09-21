<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Modules\ModuleVerifier;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Person\PersonServiceProvider;

/**
 * The roadmap's Phase 3 proof: one entity type, end to end, as a normal module.
 *
 * ⚠️ NOTHING HERE IS A FIXTURE. `kitsune/person` is a real package under `packages/`, installed through the
 * root `composer.json`'s own `packages/*` path repository — the same way `kitsune/core` is. Every call below
 * goes through the production lifecycle, and `InstalledVersions` answers for real.
 */
const PERSON = 'kitsune/person';

beforeEach(function (): void {
    ModuleKernel::flush();
});

function personType(): ?EntryType
{
    return EntryType::query()->where('handle', 'person')->whereNull('org_id')->first();
}

it('is discoverable as a module, and its manifest passes the grammar', function (): void {
    $read = ModuleDiscovery::read(PERSON);

    expect($read)->toBeInstanceOf(ModuleManifest::class)
        ->and($read->provider)->toBe(PersonServiceProvider::class)
        /* It ships no models, so it declares no scopes — and that is a claim the verifier can check. */
        ->and($read->scoping)->toBe([]);
});

it('passes verification, shipping no models to contradict its declaration', function (): void {
    $manifest = ModuleDiscovery::read(PERSON);
    $path = ModuleDiscovery::installPath(PERSON);

    $result = ModuleVerifier::verify($manifest, (string) $path);

    expect($result->passed())->toBeTrue($result->refusal ?? '')
        ->and($result->models)->toBe([])
        /* And the sweep really looked: an empty `examined` would be refused, not passed. */
        ->and($result->examined)->not->toBeEmpty();
});

it('creates a global person entry type on install', function (): void {
    expect(personType())->toBeNull();

    ModuleLifecycle::install(app(), PERSON);

    $type = personType();

    expect($type)->not->toBeNull()
        ->and($type->org_id)->toBeNull()
        ->and($type->plural_name)->toBe('People');

    /* Two fields, and their global handles are prefixed so they do not claim `name` and `email` install-wide. */
    $handles = Field::query()->where('entry_type_id', $type->id)
        ->get()
        ->map(fn (Field $field): string => (string) FieldStorage::query()->whereKey($field->field_storage_id)->value('handle'))
        ->sort()
        ->values()
        ->all();

    expect($handles)->toBe(['person_email', 'person_name']);

    /* Personal data is classified as such — ADR-020 fails closed on an unclassified field. */
    expect(FieldStorage::query()->whereIn('handle', $handles)->pluck('pii_class')->unique()->all())
        ->toBe(['personal']);
});

/** The type is global, which is what makes every org see it without any org owning it. */
it('offers the type to every org, owned by none', function (): void {
    ModuleLifecycle::install(app(), PERSON);

    $visible = EntryType::visibleFor(null, null)
        ->map(fn (EntryType $type): string => (string) $type->handle)
        ->all();

    expect($visible)->toContain('person');
});

it('is enabled and registered through the kernel like any module', function (): void {
    ModuleLifecycle::install(app(), PERSON);
    ModuleLifecycle::enable(app(), PERSON);

    expect(Module::isEnabled(PERSON))->toBeTrue();

    ModuleKernel::boot(app());

    /* The provider registered without throwing, which is the door the base class guards. */
    expect(app()->getProviders(PersonServiceProvider::class))->not->toBeEmpty();
});

it('removes its type, fields and storage on uninstall', function (): void {
    ModuleLifecycle::install(app(), PERSON);

    $typeId = personType()?->id;

    ModuleLifecycle::uninstall(app(), PERSON);

    expect(personType())->toBeNull()
        ->and(Field::query()->where('entry_type_id', $typeId)->count())->toBe(0)
        ->and(FieldStorage::query()->whereIn('handle', ['person_name', 'person_email'])->count())->toBe(0)
        ->and(Module::query()->where('handle', PERSON)->exists())->toBeFalse();
});

/**
 * ⚠️ THE REFUSAL, AND THE COUNT BEHIND IT IS THE PART THAT MATTERS. `Entry` is `#[SiteScoped]`, so a count
 * taken from the console — where no site is in context — returns zero whatever is in the table. A module that
 * counted through the scope would cheerfully delete the type out from under every org's people. This asserts
 * from the other side: the entry exists in a site nothing has put in context, and the refusal still fires.
 */
it('refuses to uninstall while a person exists in any site', function (): void {
    ModuleLifecycle::install(app(), PERSON);

    $type = personType();

    $org = Org::create(['name' => 'Golfdom Media', 'slug' => 'golfdom-media']);
    app(Context::class)->setOrg($org);

    $site = Site::create([
        'org_id' => $org->id, 'handle' => 'golfdom-us', 'slug' => 'golfdom-us', 'name' => 'Golfdom US',
    ]);
    app(Context::class)->setSite($site);

    Entry::create([
        'entry_type_id' => $type->id,
        'type_handle' => 'person',
        'title' => 'Ada Lovelace',
        'slug' => 'ada-lovelace',
        'status' => 'draft',
        'values' => [],
    ]);

    /*
     * ⚠️ THE CONTEXT IS DROPPED BEFORE THE REFUSAL IS ASKED FOR, which is the whole assertion. Uninstall runs
     * from the console with no site in context, so a count taken through `Entry`'s site scope would return
     * zero while the row sits there — and the module would delete the type out from under it.
     */
    app(Context::class)->forget();

    expect(fn () => ModuleLifecycle::uninstall(app(), PERSON))
        ->toThrow(RuntimeException::class, 'still holds 1 person');

    /* Nothing was destroyed on the way to the refusal. */
    expect(personType())->not->toBeNull()
        ->and(Module::query()->where('handle', PERSON)->exists())->toBeTrue();
});
