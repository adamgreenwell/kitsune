<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * When access ends — ADR-040: derived at read time from PHP's clock, never stored as "expired", half-open at whole
 * seconds, and UTC whatever zone the application or the caller is in. No scheduler, sweeper or worker.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->id = (string) $this->reader->getKey();
    Fx::signIn($this->reader);
});

afterEach(function (): void {
    date_default_timezone_set('UTC');
    Fx::tearDown();
});

it('counts a source until its end, and not at it', function (): void {
    $end = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');
    Fx::plant($this->site, $this->id, 'course.advanced-php', expires: $end);

    $at = function (string $instant): bool {
        $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));

        return Fx::check()->holds('course.advanced-php');
    };

    expect($at('2026-11-05 11:59:59'))->toBeTrue()
        ->and($at('2026-11-05 11:59:59.600'))->toBeTrue()
        ->and($at('2026-11-05 12:00:00'))->toBeFalse()
        ->and($at('2026-11-05 12:00:01'))->toBeFalse();
});

it('counts a source with no end for ever, and a revoked one never, whatever its end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.lifetime');
    Fx::plant($this->site, $this->id, 'course.revoked', expires: CarbonImmutable::parse('2030-01-01', 'UTC'), revoked: CarbonImmutable::parse('2026-10-01', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.revoked-later', revoked: CarbonImmutable::parse('2027-01-01', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2099-01-01', 'UTC'));

    expect(Fx::check()->holds('course.lifetime'))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));

    expect(Fx::check()->holds('course.revoked'))->toBeFalse()
        // A revocation stamped in the future, as nothing in core writes one, still answers no.
        ->and(Fx::check()->holds('course.revoked-later'))->toBeFalse();
});

it('floors the end it is given to the second', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::owner();

    Fx::writer()->grant((int) $this->id, 'course.advanced-php', 'test.order:1', CarbonImmutable::parse('2026-11-05 12:00:00.900', 'UTC'));

    expect((string) DB::table('entitlements')->value('expires_at'))->toStartWith('2026-11-05 12:00:00')
        ->and(Entitlement::query()->sole()->expires_at?->format('Y-m-d H:i:s.u'))->toBe('2026-11-05 12:00:00.000000');
});

it('stores a Paris end as UTC under a New York application, and answers across the boundary', function (): void {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::owner();

    Fx::writer()->grant((int) $this->id, 'course.advanced-php', 'test.order:1', CarbonImmutable::parse('2026-12-01 09:30:00', 'Europe/Paris'));

    expect(substr((string) DB::table('entitlements')->value('expires_at'), 0, 19))->toBe('2026-12-01 08:30:00');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:29:59', 'America/New_York'));

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:30:00', 'America/New_York'));

    expect(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

it('holds through two sources until the later one ends', function (): void {
    Fx::plant($this->site, $this->id, 'course.advanced-php', 'commerce.order:1', expires: CarbonImmutable::parse('2026-11-01', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.advanced-php', 'commerce.order:2', expires: CarbonImmutable::parse('2026-12-01', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2026-11-15', 'UTC'));

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-01', 'UTC'));

    expect(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});

/** ⚠️ TWO ENCODINGS OF ONE RULE, one SQL and one PHP, pinned against one table of rows and instants. */
it('agrees with itself: the SQL and the PHP reading of "live", row by row and instant by instant', function (): void {
    $t = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');
    $rows = [
        'no end' => [null, null],
        'ends at T' => [$t, null],
        'ended before T' => [$t->subDay(), null],
        'ends after T' => [$t->addDay(), null],
        'revoked, ends later' => [$t->addYear(), $t->subDay()],
        'revoked, no end' => [null, $t->subDay()],
        'revoked in the future' => [null, $t->addDay()],
    ];

    foreach ($rows as $label => [$expires, $revoked]) {
        Fx::plant($this->site, $this->id, 'course.advanced-php', 'test.order:'.md5($label), $expires, $revoked);
    }

    // Each instant in UTC and in a zone five hours behind it: "live" is a property of the instant, not of its spelling.
    foreach ([$t->subDay(), $t->subSecond(), $t, $t->addSecond(), $t->addYears(2)] as $instant) {
        foreach ([$instant, $instant->setTimezone('America/New_York')] as $now) {
            $sql = Entitlement::query()->liveAt($now)->pluck('id')->sort()->values()->all();
            $php = Entitlement::query()->get()->filter(fn (Entitlement $row): bool => $row->isLiveAt($now))->pluck('id')->sort()->values()->all();

            expect($php)->toBe($sql, 'at '.$now->toIso8601String());
        }
    }
});
