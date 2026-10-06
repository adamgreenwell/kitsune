<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * Both boundaries, from the attacker's side — AGENTS.md §9 and ADR-040: an entitlement is one site's, and a reader is
 * one org's, with no framework safety net for a loose id.
 */

beforeEach(function (): void {
    Fx::boot();
    Fx::declareReaders();

    $this->orgA = Fx::org('golfdom');
    $this->fr = Fx::site($this->orgA, 'fr', 'golfdom-fr');
    $this->en = Fx::site($this->orgA, 'en', 'golfdom-en');
    $this->reader = Fx::reader();
    $this->id = (int) $this->reader->getKey();
    Fx::owner('owner-a@kitsune.test');
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', null);

    $this->orgB = Fx::org('rival');
    $this->siteB = Fx::site($this->orgB, 'main');
});

afterEach(fn () => Fx::tearDown());

it('gives a reader nothing on a sibling site of the same org, even the same brand\'s other language', function (): void {
    Fx::signIn($this->reader);
    app(Context::class)->setSite($this->fr);
    Fx::forget();

    expect(Fx::check()->holds('course.advanced-php'))->toBeFalse();

    app(Context::class)->setSite($this->en);

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue();

    // Granting on fr gives fr, and leaves en as it was.
    Auth::guard(Fx::GUARD)->logout();
    app(Context::class)->setSite($this->fr);
    Fx::owner('owner-a2@kitsune.test');
    $en = (array) DB::table('entitlements')->where('site_id', $this->en->getKey())->first();
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:2', null);
    Auth::guard('web')->logout();
    Fx::signIn($this->reader);

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue()
        ->and((array) DB::table('entitlements')->where('site_id', $this->en->getKey())->first())->toBe($en);
});

it('never answers from a row filed under another org, though it names this org\'s site', function (): void {
    app(Context::class)->setSite($this->fr);
    Fx::plant($this->fr, (string) $this->id, 'course.planted', orgId: (int) $this->orgB->getKey());
    Fx::signIn($this->reader);

    expect(Entitlement::query()->where('entitlement', 'course.planted')->count())->toBe(1)
        ->and(Fx::check()->holds('course.planted'))->toBeFalse();
});

it('sees, counts and changes nothing of another org\'s readers from this org\'s context', function (): void {
    app(Context::class)->setSite($this->siteB);
    Fx::owner('owner-b@kitsune.test');
    $before = DB::table('entitlements')->get()->all();

    expect(Entitlement::query()->count())->toBe(0);

    foreach ([
        'grant' => fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', null),
        'comp' => fn () => Fx::writer()->comp($this->id, 'course.advanced-php', null),
    ] as $door => $act) {
        try {
            $act();
            $this->fail("{$door} wrote for another org's reader");
        } catch (EntitlementRefused $refused) {
            expect($refused->reason)->toBe(EntitlementRefusal::UnknownReader);
        }
    }

    expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:1'))->toBeFalse()
        ->and(EntitlementRecords::forReader($this->id))->toBe([])
        ->and(Fx::writer()->forget($this->id))->toBe(0)
        ->and(DB::table('entitlements')->get()->all())->toEqual($before);
});

it('sees and writes nothing with no context', function (): void {
    app(Context::class)->forget();

    expect(Entitlement::query()->count())->toBe(0)
        ->and(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:9', null))
        ->toThrow(EntitlementRefused::class, 'there is no site in context')
        ->and(DB::table('entitlements')->count())->toBe(1);
});

it('files a grant under the site in context and its org, whatever else is around', function (): void {
    app(Context::class)->setSite($this->en);
    Fx::owner('owner-a3@kitsune.test');

    expect(Fx::writer()->grant($this->id, 'course.other', 'commerce.order:3', null))->toBe(GrantOutcome::Granted)
        ->and(Entitlement::query()->where('entitlement', 'course.other')->sole()->only(['org_id', 'site_id']))
        ->toBe(['org_id' => $this->orgA->getKey(), 'site_id' => $this->en->getKey()]);
});
