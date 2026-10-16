<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

it('refuses to retire a source without its producer transaction at actual level zero', function (): void {
    Fx::boot();
    Fx::declareReaders();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    try {
        $org = Fx::org('source-retirement-zero');
        $site = Fx::site($org, 'source-retirement-zero');
        $reader = Fx::reader($org);
        app(Context::class)->setSite($site);
        $id = Fx::plant($site, (string) $reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
        expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        expect(fn () => Fx::writer()->retireVerifiedOrderSource('commerce.order:73', static fn () => null))->toThrow(EntitlementRefused::class);
        expect(DB::table('entitlements')->where('id', $id)->exists())->toBeTrue()->and(DB::table('audit_log')->count())->toBe(0);
    } finally {
        CarbonImmutable::setTestNow();
        Fx::tearDown();
    }
});
