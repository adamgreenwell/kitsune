<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tests\Fixtures\PivotScopedThing;

/*
 * The third hostile test, alongside cross-site and cross-org.
 *
 * ADR-021 lists users among the org-scoped models, and they are the one shape
 * `OrgScope` could not express: membership is many-to-many, so there is no
 * `org_id` column to compare. Getting this wrong means enumerating every
 * account on the installation — and Filament does not model Org, so nothing
 * else would catch it.
 */

beforeEach(function (): void {
    $this->orgA = Org::create(['name' => 'A', 'slug' => 'pivot-a']);
    $this->orgB = Org::create(['name' => 'B', 'slug' => 'pivot-b']);

    $this->mine = PivotScopedThing::create(['name' => 'Mine']);
    $this->theirs = PivotScopedThing::create(['name' => 'Theirs']);

    DB::table('pivot_scoped_thing_org')->insert([
        ['org_id' => $this->orgA->id, 'pivot_scoped_thing_id' => $this->mine->id],
        ['org_id' => $this->orgB->id, 'pivot_scoped_thing_id' => $this->theirs->id],
    ]);
});

afterEach(fn () => app(Context::class)->forget());

it('resolves the attribute, so the kernel knows how to scope it', function (): void {
    expect(ScopeResolver::for(PivotScopedThing::class))->toBe(OrgScopedThroughPivot::class);
});

it('returns NOTHING with no org context, failing closed', function (): void {
    // Same discipline as OrgScope. It is also what makes the authentication
    // carve-out necessary rather than incidental: a user is resolved before
    // any org exists, so that path must stand the scope down explicitly.
    expect(PivotScopedThing::count())->toBe(0);
});

it('returns only rows the current org is a member of', function (): void {
    app(Context::class)->setOrg($this->orgA);

    expect(PivotScopedThing::pluck('name')->all())->toBe(['Mine']);
});

it('does not leak another org\'s rows', function (): void {
    app(Context::class)->setOrg($this->orgB);

    expect(PivotScopedThing::pluck('name')->all())->toBe(['Theirs'])
        ->and(PivotScopedThing::whereKey($this->mine->id)->exists())->toBeFalse();
});

it('cannot be escaped by asking for the row by id', function (): void {
    // Enumeration is the exposure. find() is the first thing an attacker
    // reaches for once a list is closed to them.
    app(Context::class)->setOrg($this->orgA);

    expect(PivotScopedThing::find($this->theirs->id))->toBeNull();
});

it('shows a row to EVERY org that is a member of it', function (): void {
    // The reason a pivot is needed at all: membership is many-to-many, and
    // an `org_id` column cannot express a row belonging to two orgs.
    DB::table('pivot_scoped_thing_org')->insert([
        ['org_id' => $this->orgB->id, 'pivot_scoped_thing_id' => $this->mine->id],
    ]);

    app(Context::class)->setOrg($this->orgA);
    expect(PivotScopedThing::whereKey($this->mine->id)->exists())->toBeTrue();

    app(Context::class)->setOrg($this->orgB);
    expect(PivotScopedThing::whereKey($this->mine->id)->exists())->toBeTrue();
});

it('counts a row ONCE even when it belongs to several orgs', function (): void {
    // whereExists rather than a join, precisely for this: a join multiplies
    // rows per membership and quietly changes every count() in the admin.
    DB::table('pivot_scoped_thing_org')->insert([
        ['org_id' => $this->orgB->id, 'pivot_scoped_thing_id' => $this->mine->id],
    ]);

    app(Context::class)->setOrg($this->orgA);

    expect(PivotScopedThing::count())->toBe(1);
});

it('can be stood down deliberately, with a reason', function (): void {
    $all = PivotScopedThing::withoutScopeBecause(
        'the authentication path resolves one row before any org exists',
        fn ($query) => $query->pluck('name')->all(),
    );

    expect($all)->toBe(['Mine', 'Theirs']);
});
