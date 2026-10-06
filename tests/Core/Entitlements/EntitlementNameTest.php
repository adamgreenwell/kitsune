<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * An entitlement's name, refused for shape exactly as a permission is, with existence deliberately unchecked — ADR-040's
 * *When it lands*. "Exactly as" is the discipline, one encoding loud on a write and quiet on a check, not the regex.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
});

afterEach(function (): void {
    Fx::tearDown();
});

dataset('accepted names', [
    'course.advanced-php',
    'download.whitepaper-2026',
    'issue.2026-10',
    'pass.a',
    '100 bytes' => ['course.'.str_repeat('a', 93)],
]);

dataset('refused names', [
    'one word' => ['course'],
    'an empty second word' => ['course.'],
    'an empty first word' => ['.php'],
    'two dots' => ['course..x'],
    'three words' => ['course.x.y'],
    'upper case first' => ['Course.x'],
    'upper case second' => ['course.X'],
    'an underscore' => ['course.advanced_php'],
    'a leading hyphen' => ['course.-x'],
    'a trailing hyphen' => ['course.x-'],
    'a doubled hyphen' => ['course.x--y'],
    'a digit-led first word' => ['2026.issue'],
    'a trailing line break' => ["course.x\n"],
    'a NUL' => ["course.x\0"],
    'a leading space' => [' course.x'],
    'a trailing space' => ['course.x '],
    'non-ASCII' => ['course.é'],
    '101 bytes' => ['course.'.str_repeat('a', 94)],
    'empty' => [''],
]);

it('accepts a well-formed name', function (string $name): void {
    expect(EntitlementName::isName($name))->toBeTrue();
})->with('accepted names');

it('refuses a malformed name loudly on a write, quietly on a check, with no query either way', function (string $name): void {
    Fx::owner();
    $audited = AuditLog::query()->count();
    Fx::signIn($this->reader);
    Fx::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(EntitlementName::isName($name))->toBeFalse()
        ->and(Fx::check()->holds($name))->toBeFalse();
    $checked = DB::getQueryLog();
    DB::disableQueryLog();

    expect($checked)->toBe([]);

    try {
        Fx::writer()->grant((int) $this->reader->getKey(), $name, 'test.order:1', null);
        $this->fail('a malformed name was granted');
    } catch (EntitlementRefused $refused) {
        expect($refused->reason)->toBe(EntitlementRefusal::NotAName)
            ->and($refused->entitlement)->toBeNull()
            // The same words whatever was given: the input is never repeated.
            ->and($refused->getMessage())->toBe(EntitlementRefused::because(EntitlementRefusal::NotAName, EntitlementRefused::GRANT)->getMessage());
    }

    expect(Entitlement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audited);
})->with('refused names');

it('grants and holds a name nobody declared — existence deliberately unchecked', function (): void {
    Fx::owner();

    expect(Fx::writer()->grant((int) $this->reader->getKey(), 'never.declared-anywhere', 'test.order:1', null))->toBe(GrantOutcome::Granted);

    Fx::signIn($this->reader);

    expect(Fx::check()->holds('never.declared-anywhere'))->toBeTrue();
});

it('is its own grammar, not a credential\'s', function (): void {
    expect(EntitlementName::isName('issue.2026-10'))->toBeTrue()
        ->and(CredentialSlot::isName('issue.2026-10'))->toBeFalse();
});
