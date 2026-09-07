<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Exceptions\ReservedHandleException;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;

/*
 * CONTRIBUTING makes these rules that fail the build: Laravel's unique and
 * exists do not go through Eloquent, so they ignore global scopes. The
 * failure mode is not a wrong error message — it is telling one customer
 * that another customer's content exists.
 */

beforeEach(function (): void {
    $this->orgA = Org::create(['name' => 'A', 'slug' => 'a']);
    $this->orgB = Org::create(['name' => 'B', 'slug' => 'b']);

    app(Context::class)->setOrg($this->orgA);
    $this->siteA = Site::create(['org_id' => $this->orgA->id, 'handle' => 'a', 'slug' => 'a', 'name' => 'A']);
    // A sibling site in the SAME org. Without it these tests only ever
    // crossed the org boundary, and CONTRIBUTING requires both.
    $this->siteA2 = Site::create(['org_id' => $this->orgA->id, 'handle' => 'a2', 'slug' => 'a2', 'name' => 'A2']);

    app(Context::class)->setOrg($this->orgB);
    $this->siteB = Site::create(['org_id' => $this->orgB->id, 'handle' => 'b', 'slug' => 'b', 'name' => 'B']);

    $this->type = EntryType::create(['org_id' => $this->orgA->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);

    app(Context::class)->setSite($this->siteA);
    $this->entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Taken', 'slug' => 'taken']);
});

afterEach(fn () => app(Context::class)->forget());

describe('scopedUnique', function (): void {
    it('rejects a duplicate within the same scope', function (): void {
        $v = Validator::make(['slug' => 'taken'], ['slug' => [Rule::scopedUnique(Entry::class, 'slug')]]);

        expect($v->fails())->toBeTrue();
    });

    it('ALLOWS the same value in another org, because it is invisible there', function (): void {
        // The whole point. Laravel's `unique` would reject this and tell
        // org B that org A holds the slug — an information leak, and one
        // org B can do nothing about.
        app(Context::class)->setSite($this->siteB);

        $v = Validator::make(['slug' => 'taken'], ['slug' => [Rule::scopedUnique(Entry::class, 'slug')]]);

        expect($v->fails())->toBeFalse();
    });

    it('ALLOWS the same value on a sibling site in the same org', function (): void {
        // The same-org boundary, which these tests previously never crossed.
        // Uniqueness is per site: two sites in one org may both have /about.
        app(Context::class)->setSite($this->siteA2);

        $v = Validator::make(['slug' => 'taken'], ['slug' => [Rule::scopedUnique(Entry::class, 'slug')]]);

        expect($v->fails())->toBeFalse();
    });

    it('counts a soft-deleted row, because the database index still does', function (): void {
        // The unique index does not include deleted_at, so excluding trashed
        // rows reports the slug as free and the INSERT then fails on a
        // constraint violation — a 500 where the user should have seen a
        // validation message.
        $this->entry->delete();

        $v = Validator::make(['slug' => 'taken'], ['slug' => [Rule::scopedUnique(Entry::class, 'slug')]]);

        expect($v->fails())->toBeTrue();
    });

    it('ignores the record being edited', function (): void {
        $v = Validator::make(
            ['slug' => 'taken'],
            ['slug' => [Rule::scopedUnique(Entry::class, 'slug', $this->entry->id)]],
        );

        expect($v->fails())->toBeFalse();
    });
});

describe('scopedExists', function (): void {
    it('accepts a record inside the current scope', function (): void {
        $v = Validator::make(['id' => $this->entry->id], ['id' => [Rule::scopedExists(Entry::class)]]);

        expect($v->fails())->toBeFalse();
    });

    it('REJECTS a record on a sibling site in the same org', function (): void {
        // Cross-SITE within one org — the boundary Filament's tenancy does
        // cover, tested from the attacker's side anyway.
        app(Context::class)->setSite($this->siteA2);

        $v = Validator::make(['id' => $this->entry->id], ['id' => [Rule::scopedExists(Entry::class)]]);

        expect($v->fails())->toBeTrue();
    });

    it('REJECTS a record belonging to another org', function (): void {
        // The more dangerous half: Laravel's `exists` would accept this, and
        // the application would then write a reference to another customer's
        // row. That is a cross-org write, not just a leak.
        app(Context::class)->setSite($this->siteB);

        $v = Validator::make(['id' => $this->entry->id], ['id' => [Rule::scopedExists(Entry::class)]]);

        expect($v->fails())->toBeTrue();
    });
});

describe('reserved handles (ADR-012)', function (): void {
    it('refuses to save a type whose handle collides with a route segment', function (): void {
        expect(fn () => EntryType::create([
            'org_id' => $this->orgA->id, 'handle' => 'create', 'name' => 'X', 'plural_name' => 'Xs',
        ]))->toThrow(ReservedHandleException::class);
    });

    it('explains why rather than only refusing', function (): void {
        try {
            EntryType::create(['org_id' => $this->orgA->id, 'handle' => 'edit', 'name' => 'X', 'plural_name' => 'Xs']);
            $this->fail('expected ReservedHandleException');
        } catch (ReservedHandleException $e) {
            expect($e->getMessage())->toContain('ambiguous')->toContain('create, edit, delete');
        }
    });

    it('allows an ordinary handle', function (): void {
        expect(EntryType::create([
            'org_id' => $this->orgA->id, 'handle' => 'podcast', 'name' => 'P', 'plural_name' => 'Ps',
        ])->exists)->toBeTrue();
    });
});

describe('reserved handles cover registered routes', function (): void {
    it('rejects every page segment EntryResource registers', function (): void {
        // The list and the routes must not drift. `related` was missing when
        // ManageEntryRelations registered /{type}/{record}/related, which
        // reopened the collision the list exists to prevent.
        $registered = collect(EntryResource::getPages())
            ->keys()
            ->map(fn (string $key): string => strtolower($key))
            ->push('related')
            ->unique();

        // Collect first, assert once. expect(...)->toContain($a, $b) treats
        // the second argument as ANOTHER expected value, not a message —
        // the same trap as not->toThrow(Class, $message), which silently
        // passed a test earlier in this project.
        $unreserved = $registered
            ->reject(fn (string $segment): bool => $segment === 'index')
            ->reject(fn (string $segment): bool => in_array($segment, EntryType::RESERVED_HANDLES, true))
            ->values()
            ->all();

        expect($unreserved)->toBe([]);
    });
});
