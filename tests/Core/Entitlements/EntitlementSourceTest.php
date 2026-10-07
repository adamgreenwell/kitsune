<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * A grant remembers where it came from — ADR-040, as amended by Adam on 2026-10-06 ("track each source"): a refund
 * removes only what its own source gave, and access stays while any other source is live. Written from the attacker's
 * side where there is one: a replayed payment after its refund, a comp that should survive a refund.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->id = (int) $this->reader->getKey();
    $this->owner = Fx::owner();
});

afterEach(function (): void {
    Fx::tearDown();
});

/** Every column of every row, read raw: what "byte for byte" compares. */
function entitlementRows(): array
{
    return DB::table('entitlements')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
}

function rowOf(string $source): array
{
    return (array) DB::table('entitlements')->where('source', $source)->first();
}

/** Whether the reader holds it right now, asked as the reader's own request would ask. */
function readerHolds(object $test, string $name = 'course.advanced-php'): bool
{
    Fx::signIn($test->reader);
    $holds = Fx::check()->holds($name);
    Auth::guard(Fx::GUARD)->logout();
    Fx::forget();

    return $holds;
}

dataset('accepted sources', [
    'commerce.order:4821',
    'commerce.order:01jabc5x7k9m2n4p6q8r0s1t3v',
    'mixed case' => ['commerce.order:cs_live_a1B2c3'],
    'import.legacy:batch-7',
    'no reference' => ['import.legacy'],
    'lms-starter.enrolment:42',
    'a 64-character reference' => ['commerce.order:'.str_repeat('A', 64)],
]);

dataset('refused sources', [
    'an empty reference' => ['commerce.order:'],
    'two colons' => ['commerce.order::1'],
    'a space in the reference' => ['commerce.order:a b'],
    'a dot in the reference' => ['commerce.order:a.b'],
    'a slash in the reference' => ['commerce.order:1/2'],
    'a trailing line break' => ["commerce.order:1\n"],
    'a leading space' => [' commerce.order:1'],
    'a trailing space' => ['commerce.order:1 '],
    'upper case producer' => ['Commerce.order:1'],
    'upper case kind' => ['commerce.Order:1'],
    'one word and a reference' => ['commerce:1'],
    'one word' => ['commerce'],
    'three words' => ['commerce.order.x:1'],
    'a digit-led second word' => ['commerce.2026:1'],
    'non-ASCII' => ['commerce.order:é'],
    'a 65-character reference' => ['commerce.order:'.str_repeat('A', 65)],
    'over 100 bytes' => ['commerce.'.str_repeat('a', 92)],
    'empty' => [''],
]);

it('accepts a well-formed source', function (string $source): void {
    expect(EntitlementSource::isSource($source))->toBeTrue()
        ->and(EntitlementSource::isReserved($source))->toBeFalse();
})->with('accepted sources');

it('refuses a malformed source on a grant and a revoke, with no query and nothing written', function (string $source): void {
    $audited = AuditLog::query()->count();

    expect(EntitlementSource::isSource($source))->toBeFalse();

    foreach ([EntitlementRefused::GRANT => fn () => Fx::writer()->grant($this->id, 'course.advanced-php', $source, null), EntitlementRefused::REVOKE => fn () => Fx::writer()->revoke($this->id, 'course.advanced-php', $source)] as $door => $write) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $write();
            $this->fail("{$door} took a malformed source");
        } catch (EntitlementRefused $refused) {
            // The same words whatever was given: the source is never repeated.
            expect($refused->reason)->toBe(EntitlementRefusal::NotASource)
                ->and($refused->getMessage())->toBe(EntitlementRefused::because(EntitlementRefusal::NotASource, $door, 'course.advanced-php')->getMessage());
        } finally {
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
        }

        // The owner check's own memoised reads are allowed; nothing touches entitlements or the readers' table.
        expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'entitlements') || str_contains($sql, 'test_readers')))->toBe([]);
    }

    expect(Entitlement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audited);
})->with('refused sources');

it('refuses a core source through grant()', function (string $source): void {
    $audited = AuditLog::query()->count();

    try {
        Fx::writer()->grant($this->id, 'course.advanced-php', $source, null);
        $this->fail('grant() wrote a core source');
    } catch (EntitlementRefused $refused) {
        expect($refused->reason)->toBe(EntitlementRefusal::ReservedSource)
            ->and($refused->getMessage())->toBe(EntitlementRefused::because(EntitlementRefusal::ReservedSource, EntitlementRefused::GRANT, 'course.advanced-php')->getMessage());
    }

    expect(Entitlement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audited);
})->with(['core.comp', 'core.other', 'core.comp:1']);

it('is its own grammar, apart from a name\'s and a credential\'s', function (): void {
    expect(EntitlementSource::isSource('commerce.2026:1'))->toBeFalse()
        ->and(EntitlementName::isName('issue.2026-10'))->toBeTrue()
        ->and(EntitlementSource::isSource('issue.2026-10'))->toBeFalse()
        ->and(EntitlementSource::isSource('commerce.order:4821'))->toBeTrue()
        ->and(CredentialSlot::isName('commerce.order:4821'))->toBeFalse()
        ->and(EntitlementName::isName('commerce.order:4821'))->toBeFalse();
});

it('keeps the comp when the order is refunded — Adam\'s answer', function (): void {
    expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', null))->toBe(GrantOutcome::Granted)
        ->and(Fx::writer()->comp($this->id, 'course.advanced-php', null))->toBe(GrantOutcome::Granted);

    $comp = rowOf(EntitlementSource::COMP);
    $audited = AuditLog::query()->count();

    expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:4821'))->toBeTrue()
        ->and(readerHolds($this))->toBeTrue()
        ->and(rowOf(EntitlementSource::COMP))->toBe($comp)
        ->and(Entitlement::query()->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe($audited + 1)
        ->and(AuditLog::query()->where('action', 'like', 'entitlement.%')->count())->toBe(3);
});

it('keeps a second order when the first is refunded, and holds nothing once both are', function (): void {
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', null);
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:2', null);
    $second = rowOf('commerce.order:2');

    Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:1');

    expect(readerHolds($this))->toBeTrue()
        ->and(rowOf('commerce.order:2'))->toBe($second);

    Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:2');

    expect(readerHolds($this))->toBeFalse();
});

it('holds through any live source, whichever was written first', function (array $rows, bool $holds): void {
    $now = CarbonImmutable::now();

    foreach ($rows as [$source, $revoked]) {
        Fx::plant($this->site, (string) $this->id, 'course.advanced-php', $source, revoked: $revoked ? $now->subMinute() : null);
    }

    expect(readerHolds($this))->toBe($holds);
})->with([
    'revoked order, then live comp' => [[['commerce.order:1', true], ['core.comp', false]], true],
    'live comp, then revoked order' => [[['core.comp', false], ['commerce.order:1', true]], true],
    'live order, then revoked comp' => [[['commerce.order:1', false], ['core.comp', true]], true],
    'revoked comp, then live order' => [[['core.comp', true], ['commerce.order:1', false]], true],
    'only the comp' => [[['core.comp', false]], true],
    'only the order' => [[['commerce.order:1', false]], true],
    'both revoked' => [[['commerce.order:1', true], ['core.comp', true]], false],
]);

it('keeps a refunded source revoked however often it is replayed, asserted by count', function (): void {
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', null);
    Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:4821');
    $row = rowOf('commerce.order:4821');

    expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', null))->toBe(GrantOutcome::StillRevoked)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', null))->toBe(GrantOutcome::StillRevoked)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', CarbonImmutable::now()->addYears(5)))->toBe(GrantOutcome::StillRevoked)
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(rowOf('commerce.order:4821'))->toBe($row)
        ->and(AuditLog::query()->where('action', 'like', 'entitlement.%')->pluck('action')->all())
        ->toBe([EntitlementWriter::GRANTED, EntitlementWriter::REVOKED])
        ->and(readerHolds($this))->toBeFalse();
});

it('keeps a source revoked after it lapsed, against a later grant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));

    expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:1'))->toBeTrue()
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC')))
        ->toBe(GrantOutcome::StillRevoked)
        ->and(readerHolds($this))->toBeFalse();
});

it('reinstates a revoked comp with the end given now, exactly, and records it', function (): void {
    $until = CarbonImmutable::now('UTC')->addYear()->startOfSecond();
    $shorter = CarbonImmutable::now('UTC')->addMonth()->startOfSecond();

    Fx::writer()->comp($this->id, 'course.advanced-php', $until);
    Fx::writer()->revoke($this->id, 'course.advanced-php', EntitlementSource::COMP);

    expect(Fx::writer()->comp($this->id, 'course.advanced-php', $shorter))->toBe(GrantOutcome::Reinstated);

    $row = Entitlement::query()->sole();

    expect($row->revoked_at)->toBeNull()
        ->and($row->expires_at?->equalTo($shorter))->toBeTrue()
        ->and(AuditLog::query()->latest('id')->value('action'))->toBe(EntitlementWriter::REINSTATED)
        ->and(readerHolds($this))->toBeTrue();
});

it('never touches a revoked order when comping', function (): void {
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:4821', null);
    Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:4821');
    $order = rowOf('commerce.order:4821');

    expect(Fx::writer()->comp($this->id, 'course.advanced-php', null))->toBe(GrantOutcome::Granted)
        ->and(rowOf('commerce.order:4821'))->toBe($order)
        ->and(Entitlement::query()->count())->toBe(2);
});

it('refuses a comp with nobody signed in, and a revoked comp stays revoked', function (): void {
    Fx::writer()->comp($this->id, 'course.advanced-php', null);
    Fx::writer()->revoke($this->id, 'course.advanced-php', EntitlementSource::COMP);
    $row = rowOf(EntitlementSource::COMP);
    $audited = AuditLog::query()->count();

    Fx::nobody();

    try {
        Fx::writer()->comp($this->id, 'course.advanced-php', null);
        $this->fail('a comp was given with nobody signed in');
    } catch (EntitlementRefused $refused) {
        expect($refused->reason)->toBe(EntitlementRefusal::NotAnOwner)
            ->and($refused->getMessage())->toContain('only an owner of this organisation, signed in');
    }

    expect(rowOf(EntitlementSource::COMP))->toBe($row)
        ->and(AuditLog::query()->count())->toBe($audited)
        ->and(readerHolds($this))->toBeFalse();
});

it('revokes a comp as a source like any other', function (): void {
    Fx::writer()->comp($this->id, 'course.advanced-php', null);

    expect(Fx::writer()->revoke($this->id, 'course.advanced-php', EntitlementSource::COMP))->toBeTrue()
        ->and(readerHolds($this))->toBeFalse();
});

it('decides each source on its own row', function (): void {
    $later = CarbonImmutable::now('UTC')->addYear();
    $earlier = CarbonImmutable::now('UTC')->addMonth();

    // A comp while an order with a later end is live: a second row, Granted.
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', $later);

    expect(Fx::writer()->comp($this->id, 'course.advanced-php', $earlier))->toBe(GrantOutcome::Granted)
        ->and(Entitlement::query()->count())->toBe(2);

    // An order with an earlier end than the comp's: Unchanged for that order.
    Fx::writer()->comp($this->id, 'course.advanced-php', null);

    expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', $earlier))->toBe(GrantOutcome::Unchanged)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:2', $earlier))->toBe(GrantOutcome::Granted)
        ->and(Entitlement::query()->count())->toBe(3);
});

it('reads only its own source\'s row when deciding', function (): void {
    $code = (string) file_get_contents(dirname(__DIR__, 3).'/packages/core/src/Entitlements/EntitlementWriter.php');
    $lockedRow = substr($code, (int) strpos($code, 'private function lockedRow('));

    expect(substr($lockedRow, 0, (int) strpos($lockedRow, "\n    }\n")))->toContain("->where('source', \$source)");
});

it('lapses each source at its own end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:1', CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC'));
    Fx::writer()->comp($this->id, 'course.advanced-php', null);

    $this->travelTo(CarbonImmutable::parse('2026-11-06 00:00:00', 'UTC'));

    expect(readerHolds($this))->toBeTrue()
        ->and(Entitlement::query()->where('source', 'commerce.order:1')->sole()->isLiveAt(CarbonImmutable::now('UTC')))->toBeFalse();
});
