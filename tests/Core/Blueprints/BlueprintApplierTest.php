<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;

/*
 * The apply flow — ADR-039.
 *
 * ⚠️ THE RECEIPT IS AN INTENT RECORD, WRITTEN BEFORE THE WORK AND OUTSIDE ITS TRANSACTION. That is the
 * opposite of `Module`'s, and the reason is that apply cannot be one transaction: a blueprint that indexes a
 * field issues DDL, which commits implicitly on MySQL and MariaDB. So a crash part-way is reachable, and the
 * receipt is what makes a half-applied blueprint findable rather than invisible.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    $this->other = Org::create(['slug' => 'rival', 'name' => 'Rival']);

    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

it('creates the declared type and its field, and records a receipt', function (): void {
    $result = BlueprintApplier::apply(new FixtureBlueprint);

    $type = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->first();

    expect($type)->not->toBeNull()
        ->and($type->name)->toBe('Dispatch');

    $storage = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch_body')->first();

    expect($storage)->not->toBeNull()
        ->and($storage->type)->toBe('textarea')
        ->and($storage->pii_class)->toBe('none')
        ->and(Field::query()->where('entry_type_id', $type->getKey())->count())->toBe(1);

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt)->not->toBeNull()
        ->and($receipt->version)->toBe('1.0.0')
        ->and($receipt->applied_at)->not->toBeNull()
        ->and($result['created'])->toContain('entry type dispatch');
});

/** ADR-039: a blueprint is applied INTO an org, and every row it writes belongs to that org. */
it('writes nothing global, and nothing into another org', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(EntryType::query()->whereNull('org_id')->count())->toBe(0)
        ->and(FieldStorage::query()->whereNull('org_id')->count())->toBe(0)
        ->and(EntryType::query()->where('org_id', $this->other->getKey())->count())->toBe(0);
});

/**
 * ⚠️ REFUSED RATHER THAN DEFAULTED. With no org in context the rows would be written global — the one thing
 * ADR-039 says a blueprint may never do — so the absence is a refusal and not a fallback.
 */
it('refuses to apply with no org in context', function (): void {
    app(Context::class)->forget();

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'no organisation is in context');

    expect(DB::table('blueprints')->count())->toBe(0)
        ->and(DB::table('entry_types')->count())->toBe(0);
});

/** The receipt is what makes re-apply idempotent, not the collision policy. */
it('is a no-op when the same version is already applied', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['created'])->toBe([])
        ->and($result['skipped'])->toContain('already applied at this version')
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(1)
        ->and(DB::table('blueprints')->count())->toBe(1);
});

/** Two orgs applying the same blueprint is the ordinary case, and each gets its own rows. */
it('applies the same blueprint into two orgs independently', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    app(Context::class)->setOrg($this->other);
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(EntryType::query()->where('handle', 'dispatch')->count())->toBe(2)
        ->and(DB::table('blueprints')->count())->toBe(2);
});

/**
 * ⚠️ A TYPE BELONGS TO ONE THING. Adopting an `article` the operator built by hand would put this blueprint's
 * fields on a type it does not understand, and record in the receipt that it created something it did not.
 */
it('refuses a type it did not create, under the default policy', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'will not adopt a type it did not create');
});

it('skips a type it did not create when the declaration says so', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    FixtureBlueprint::$onCollision = OnCollision::Skip;

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toContain('entry type dispatch')
        /* The type stays theirs — skipping adopts the row, it does not rewrite it. */
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->value('name'))
        ->toBe('Theirs');
});

/**
 * Storage is shared across entry types by design (ADR-006), so a handle this org already defines is ADOPTED —
 * which is the opposite default from an entry type, and for the opposite reason.
 */
it('adopts compatible existing storage rather than duplicating it', function (): void {
    FieldStorage::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch_body', 'type' => 'textarea',
        'cardinality' => 1, 'pii_class' => 'none',
    ]);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['adopted'])->toContain('field storage dispatch_body')
        ->and(FieldStorage::query()->where('handle', 'dispatch_body')->count())->toBe(1);
});

/** ⚠️ Adoption keeps the stored definition, so a divergent declaration is refused rather than silently taken. */
it('refuses to adopt storage whose classification differs', function (): void {
    FieldStorage::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch_body', 'type' => 'textarea',
        'cardinality' => 1, 'pii_class' => 'personal',
    ]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'privacy classification');
});

it('refuses to adopt storage of a different shape', function (): void {
    FieldStorage::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch_body', 'type' => 'number',
        'cardinality' => 1, 'pii_class' => 'none',
    ]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'already describes a number field');
});

/**
 * ⚠️ THE ROWS ARE ONE TRANSACTION, SO A REFUSAL PART-WAY LEAVES NONE OF THEM — but the receipt survives,
 * because it is committed first and outside. That is the state an operator can be told about.
 */
it('leaves no rows but a findable receipt when a declaration is refused part-way', function (): void {
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'first', name: 'First', pluralName: 'Firsts', fields: [
            new FieldDeclaration(handle: 'first_body', type: 'textarea', label: 'Body', piiClass: 'none'),
        ]),
        /* Reserved by the admin's own routing, and refused at save by `EntryType`. */
        new EntryTypeDeclaration(handle: 'create', name: 'Nope', pluralName: 'Nopes'),
    ];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class);

    expect(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(0)
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->count())->toBe(0);

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt)->not->toBeNull()
        ->and($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest)->toBeNull();
});

/** The manifest is the second of the three inputs a later merge needs, so it records what was applied. */
it('records what it applied in the manifest', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    $manifest = Blueprint::receiptFor('fixture')->manifest;

    expect($manifest['version'])->toBe('1.0.0')
        ->and($manifest['entry_types'][0]['handle'])->toBe('dispatch')
        ->and($manifest['entry_types'][0]['fields'][0]['handle'])->toBe('dispatch_body')
        ->and($manifest['entry_types'][0]['fields'][0]['pii_class'])->toBe('none');
});

/** The receipt is a proof, so it may not be planted or re-pointed in bulk. */
it('refuses a bulk write to the receipt', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(fn () => Blueprint::query()->update(['handle' => 'forged']))
        ->toThrow(RuntimeException::class, 'cannot be written in bulk');
});
