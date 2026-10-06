<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestUlidReader;

/*
 * What `entitlements` stores and how each engine compares it — ADR-040, on every leg of the matrix: a collation that
 * folds case or pads spaces would make one reader answer for another, and one order's refund revoke another's.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('store');
    $this->site = Fx::site($this->org, 'main');
});

afterEach(fn () => Fx::tearDown());

function rawEntitlement(array $overrides = []): array
{
    return [
        'org_id' => app(Context::class)->orgId(),
        'site_id' => app(Context::class)->siteId(),
        'reader_id' => '7',
        'entitlement' => 'course.advanced-php',
        'source' => 'test.order:1',
        'expires_at' => null,
        'revoked_at' => null,
        'changed_at' => '2026-10-06 12:00:00',
        ...$overrides,
    ];
}

/** A raw insert's failure, or null when it was accepted — inside a savepoint, so a refusal leaves PostgreSQL usable. */
function rawInsertFails(array $row): bool
{
    try {
        DB::transaction(static fn () => DB::table('entitlements')->insert($row));

        return false;
    } catch (QueryException) {
        return true;
    }
}

it('refuses a row with no site or no source at the database', function (string $column): void {
    expect(rawInsertFails(rawEntitlement([$column => null])))->toBeTrue();
})->with(['site_id', 'source', 'reader_id', 'entitlement', 'org_id', 'changed_at']);

it('refuses a second row with the same four key columns, and accepts one differing only in source', function (): void {
    expect(rawInsertFails(rawEntitlement()))->toBeFalse()
        ->and(rawInsertFails(rawEntitlement(['expires_at' => '2030-01-01 00:00:00'])))->toBeTrue()
        ->and(rawInsertFails(rawEntitlement(['source' => 'test.order:2'])))->toBeFalse()
        ->and(DB::table('entitlements')->count())->toBe(2);
});

it('stores every instant as UTC wall clock in whole seconds, whatever the zone it was given in', function (): void {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');

    try {
        Fx::declareReaders();
        $reader = Fx::reader();
        Fx::owner();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00.750', 'UTC'));

        Fx::writer()->grant((int) $reader->getKey(), 'course.advanced-php', 'test.order:1', CarbonImmutable::parse('2026-12-01 09:30:15.900', 'Europe/Paris'));

        $raw = (array) DB::table('entitlements')->first();

        expect(substr((string) $raw['expires_at'], 0, 19))->toBe('2026-12-01 08:30:15')
            ->and(substr((string) $raw['changed_at'], 0, 19))->toBe('2026-10-06 12:00:00')
            ->and(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $raw['expires_at']))->toBe(1)
            ->and(Entitlement::query()->sole()->expires_at?->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('writes only an instant through its cast', function (mixed $value): void {
    $row = new Entitlement;

    expect(fn () => $row->setAttribute('expires_at', $value))->toThrow(InvalidArgumentException::class, 'only an instant is written');
})->with([
    'an ISO string' => '2026-12-01T09:30:15Z',
    'a stored spelling' => '2026-12-01 09:30:15',
    'a timestamp' => 1_790_000_000,
]);

it('round-trips the far instants on every engine', function (string $instant): void {
    Fx::plant($this->site, '7', 'course.advanced-php', expires: CarbonImmutable::parse($instant, 'UTC'));

    expect(Entitlement::query()->sole()->expires_at?->format('Y-m-d H:i:s'))->toBe($instant);
})->with(['2040-01-01 00:00:00', '9999-12-31 23:59:59']);

it('tells readers apart by bytes, never by a collation', function (): void {
    Fx::declareReaders(TestUlidReader::class);
    $ids = ['abc', 'ABC', 'abc '];

    // Signed in as unsaved instances: a host table on MySQL could not hold `abc` and `ABC` both, which is the point.
    Fx::plant($this->site, 'abc', 'course.advanced-php');

    foreach ($ids as $id) {
        $signedIn = (new TestUlidReader)->forceFill(['id' => $id, 'org_id' => $this->org->getKey(), 'email' => 'r@example.test']);
        Fx::signIn($signedIn);

        expect(Fx::check()->holds('course.advanced-php'))->toBe($id === 'abc', "reader [{$id}]");
    }

    expect(Entitlement::query()->where('reader_id', 'ABC')->count())->toBe(0)
        ->and(Entitlement::query()->where('reader_id', 'abc ')->count())->toBe(0)
        ->and(rawInsertFails(rawEntitlement(['reader_id' => 'ABC'])))->toBeFalse()
        ->and(rawInsertFails(rawEntitlement(['reader_id' => 'abc '])))->toBeFalse();
});

it('tells sources apart by bytes, so one order\'s refund never revokes another\'s', function (): void {
    Fx::declareReaders();
    $reader = Fx::reader();
    Fx::owner();
    $id = (int) $reader->getKey();

    expect(Fx::writer()->grant($id, 'course.advanced-php', 'commerce.order:AbC', null))->toBe(GrantOutcome::Granted)
        ->and(Fx::writer()->grant($id, 'course.advanced-php', 'commerce.order:abc', null))->toBe(GrantOutcome::Granted)
        ->and(Fx::writer()->revoke($id, 'course.advanced-php', 'commerce.order:abc'))->toBeTrue()
        ->and(Entitlement::query()->where('source', 'commerce.order:AbC')->sole()->revoked_at)->toBeNull();

    Fx::signIn($reader);

    expect(Fx::check()->holds('course.advanced-php'))->toBeTrue();
});

describe('a site\'s delete', function (): void {
    it('is refused while the site gives a live grant, through the model and the builder, naming the count', function (Closure $delete): void {
        Fx::plant($this->site, '7', 'course.advanced-php', 'commerce.order:1');
        Fx::plant($this->site, '7', 'course.advanced-php', 'core.comp');

        expect($delete)->toThrow(RuntimeException::class, 'Site [main] still gives readers 2 live entitlement grants')
            ->and(Site::query()->count())->toBe(1)
            ->and(DB::table('entitlements')->count())->toBe(2);
    })->with([
        'the model' => [fn () => Site::query()->firstOrFail()->delete()],
        'the builder' => [fn () => Site::query()->delete()],
    ]);

    it('counts the site being deleted, whichever site is in context', function (): void {
        $doomed = $this->site;
        $other = Fx::site($this->org, 'other');
        Fx::plant($other, '8', 'course.advanced-php', 'commerce.order:2');

        // A live grant on the doomed site alone, deleted from the other's context: refused.
        Fx::plant($doomed, '7', 'course.advanced-php', 'commerce.order:1');

        expect(fn () => $doomed->delete())->toThrow(RuntimeException::class, 'Site [main] still gives readers 1 live entitlement grant,');

        // And the other site's grant never blocks the doomed site's delete once its own is revoked.
        DB::table('entitlements')->where('site_id', $doomed->getKey())->update(['revoked_at' => '2026-01-01 00:00:00']);
        $doomed->delete();

        expect(DB::table('sites')->where('id', $doomed->getKey())->exists())->toBeFalse()
            ->and(DB::table('entitlements')->where('site_id', $other->getKey())->count())->toBe(1);
    });

    it('says it in the singular for one grant', function (): void {
        Fx::plant($this->site, '7', 'course.advanced-php');

        expect(fn () => $this->site->delete())->toThrow(RuntimeException::class, 'still gives readers 1 live entitlement grant, and the database would delete it by cascade');
    });

    it('goes ahead, taking them, when only revoked and lapsed rows remain', function (): void {
        $now = CarbonImmutable::now('UTC');
        Fx::plant($this->site, '7', 'course.advanced-php', 'commerce.order:1', revoked: $now->subDay());
        Fx::plant($this->site, '7', 'course.advanced-php', 'commerce.order:2', expires: $now->subSecond());
        Fx::plant($this->site, '8', 'course.advanced-php', 'commerce.order:3', expires: $now->addDay(), revoked: $now->subDay());

        $this->site->delete();

        expect(Site::query()->count())->toBe(0)
            ->and(DB::table('entitlements')->count())->toBe(0);
    });

    it('still lets an org\'s hard delete take everything', function (): void {
        Fx::plant($this->site, '7', 'course.advanced-php');

        $this->org->forceDelete();

        expect(DB::table('sites')->count())->toBe(0)
            ->and(DB::table('entitlements')->count())->toBe(0);
    });
});
