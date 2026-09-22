<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;

/*
 * The console seam — ADR-039. The work lives in `BlueprintApplier`, so what these assert is argument handling,
 * the refusals an operator meets, and the context being given back however the command ends.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();

    app(BlueprintRegistry::class)->register(new FixtureBlueprint);

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
});

afterEach(fn () => app(Context::class)->forget());

it('lists what is registered', function (): void {
    $this->artisan('kitsune:blueprint list')
        ->expectsOutputToContain('fixture')
        ->assertSuccessful();
});

it('applies into a named org', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->exists())
        ->toBeTrue();
});

/**
 * ⚠️ THE CONTEXT IS GIVEN BACK, AND CLEARED RATHER THAN LEFT POINTING AT THE APPLIED ORG. ADR-027 records
 * three benchmark commands getting this wrong: a caller running more than one command was left scoped to an
 * org the first had rolled back or removed.
 */
it('leaves no org in context afterwards', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(app(Context::class)->orgId())->toBeNull();
});

it('gives the context back even when the apply fails', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertFailed();

    expect(app(Context::class)->orgId())->toBeNull();
});

it('refuses an unknown blueprint by name', function (): void {
    $this->artisan('kitsune:blueprint apply nope --org=acme')
        ->expectsOutputToContain('No blueprint is registered under [nope]')
        ->assertFailed();
});

it('refuses an apply with no org named', function (): void {
    $this->artisan('kitsune:blueprint apply fixture')
        ->expectsOutputToContain('needs an organisation')
        ->assertFailed();
});

it('refuses an org slug that does not exist', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=ghost')
        ->expectsOutputToContain('No organisation has the slug [ghost]')
        ->assertFailed();
});

/** The refusal's own reason, not a bare failure — it is the only thing that says which step stopped. */
it('prints the reason a blueprint refused to apply', function (): void {
    EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs',
    ]);

    $this->artisan('kitsune:blueprint apply fixture --org=acme')
        ->expectsOutputToContain('will not adopt a type it did not create')
        ->assertFailed();
});

/**
 * ⚠️ STATUS READS PAST THE ORG SCOPE, because it is asked from a console with no org in context — where a
 * scoped read returns nothing whatever is in the table. The same trap `PersonServiceProvider::uninstall()`
 * measured for its own count.
 */
it('reports receipts across every org, from no context at all', function (): void {
    $this->artisan('kitsune:blueprint apply fixture --org=acme')->assertSuccessful();

    expect(app(Context::class)->orgId())->toBeNull();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('fixture')
        ->assertSuccessful();
});

it('reports an interrupted apply as interrupted', function (): void {
    app(Context::class)->setOrg($this->org);

    /* The state a crash between the intent record and the work leaves behind. */
    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => null, 'applied_at' => null]);

    app(Context::class)->forget();

    $this->artisan('kitsune:blueprint status')
        ->expectsOutputToContain('INTERRUPTED')
        ->assertSuccessful();
});
