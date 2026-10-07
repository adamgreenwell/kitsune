<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\MissingTableReader;

/*
 * May this reader reach this? — ADR-040's read door: any live source, one indexed read, no memo, and never an answer
 * on a database error.
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

afterEach(fn () => Fx::tearDown());

/** @return list<array{sql: string, bindings: array<int, mixed>}> the statements the callback ran */
function statementsOf(Closure $callback): array
{
    $seen = [];
    DB::listen(static function (QueryExecuted $query) use (&$seen): void {
        $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $callback();

    return $seen;
}

it('answers for a held, an absent, a revoked and a lapsed entitlement', function (): void {
    $now = CarbonImmutable::now('UTC');
    Fx::plant($this->site, $this->id, 'course.held');
    Fx::plant($this->site, $this->id, 'course.revoked', revoked: $now->subMinute());
    Fx::plant($this->site, $this->id, 'course.lapsed', expires: $now->subSecond());
    Fx::plant($this->site, $this->id, 'course.dated', expires: $now->addDay());

    expect(Fx::check()->holds('course.held'))->toBeTrue()
        ->and(Fx::check()->holds('course.dated'))->toBeTrue()
        ->and(Fx::check()->holds('course.absent'))->toBeFalse()
        ->and(Fx::check()->holds('course.revoked'))->toBeFalse()
        ->and(Fx::check()->holds('course.lapsed'))->toBeFalse();
});

it('sees a grant and a revoke made earlier in the same request', function (): void {
    $check = app(EntitlementCheck::class);
    Fx::owner();

    expect($check->holds('course.advanced-php'))->toBeFalse();

    Fx::writer()->grant((int) $this->id, 'course.advanced-php', 'test.order:1', null);

    expect($check->holds('course.advanced-php'))->toBeTrue();

    Fx::writer()->revoke((int) $this->id, 'course.advanced-php', 'test.order:1');

    expect($check->holds('course.advanced-php'))->toBeFalse();
});

it('reads entitlements once a call, with the site and org as plain equalities and no source', function (): void {
    Fx::plant($this->site, $this->id, 'course.advanced-php', 'test.order:1');
    $check = Fx::check();
    $check->holds('course.warm-up');

    $statements = statementsOf(fn () => $check->holds('course.advanced-php'));
    $sql = str_replace(['"', '`'], '', $statements[0]['sql'] ?? '');

    expect($statements)->toHaveCount(1)
        ->and($sql)->toContain('entitlements.site_id = ? and entitlements.org_id = ? and entitlements.reader_id = ? and entitlements.entitlement = ?')
        ->and($sql)->not->toContain('source')
        ->and($statements[0]['bindings'])->toContain($this->id);
});

it('plans as one read of the unique index\'s three-column prefix on SQLite, whatever sources there are', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The plan is SQLite\'s; the engines\' are measured and recorded in ADR-040 (M3).');
    }

    foreach (['test.order:1', 'test.order:2', 'core.comp'] as $source) {
        Fx::plant($this->site, $this->id, 'course.advanced-php', $source);
    }

    $statements = statementsOf(fn () => Fx::check()->holds('course.advanced-php'));
    $plan = collect(DB::select('explain query plan '.$statements[0]['sql'], $statements[0]['bindings']))->pluck('detail')->implode(' | ');

    expect($plan)->toContain('USING INDEX entitlements_site_id_reader_id_entitlement_source_unique (site_id=? AND reader_id=? AND entitlement=?)');
});

it('throws rather than answers when the entitlements query fails, chaining nothing', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits implicitly on MySQL and MariaDB; the reader-table case covers every engine.');
    }

    Fx::plant($this->site, $this->id, 'course.advanced-php');
    DB::statement('alter table entitlements rename to entitlements_elsewhere');

    try {
        Fx::check()->holds('course.advanced-php');
        $this->fail('a failed query answered');
    } catch (EntitlementUnavailable $unavailable) {
        expect($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->entitlement)->toBe('course.advanced-php')
            ->and($unavailable->getMessage())->toStartWith('Whether the reader holds [course.advanced-php] could not be read: the database refused the query (SQLSTATE ')
            ->and($unavailable->getMessage())->not->toContain($this->id);
    }
});

it('maps a failure loading the reader at every door, with the reader\'s id nowhere', function (): void {
    $id = '81234';
    Fx::declareReaders(MissingTableReader::class);
    Auth::forgetGuards();
    session()->put(Auth::guard(Fx::GUARD)->getName(), $id);
    Fx::forget();

    // Each failure inside a savepoint of its own: on PostgreSQL a failed statement aborts the test's transaction.
    // The read door: the guard's own user load is the request's first query on the readers' table.
    try {
        DB::transaction(fn () => Fx::check()->holds('course.advanced-php'));
        $this->fail('a failed reader load answered');
    } catch (EntitlementUnavailable $unavailable) {
        expect($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->getMessage())->not->toContain($id);
    }

    // The write doors: an owner acting, so the lookup that fails is the reader's, under the mapping.
    session()->forget(Auth::guard(Fx::GUARD)->getName());
    Auth::forgetGuards();
    Fx::owner();

    foreach ([
        'grant' => fn () => Fx::writer()->grant($id, 'course.advanced-php', 'test.order:1', null),
        'comp' => fn () => Fx::writer()->comp($id, 'course.advanced-php', null),
        'revoke' => fn () => Fx::writer()->revoke($id, 'course.advanced-php', 'test.order:1'),
        'forget' => fn () => Fx::writer()->forget($id),
        'export' => fn () => EntitlementRecords::forReader($id),
    ] as $door => $act) {
        try {
            DB::transaction($act);
            $this->fail("{$door} answered on a failed lookup");
        } catch (EntitlementRefused $refused) {
            expect($refused->reason)->toBe(EntitlementRefusal::Database, $door)
                ->and($refused->getPrevious())->toBeNull()
                ->and($refused->getMessage())->not->toContain($id)
                ->and($refused->getMessage())->toMatch('/\(SQLSTATE \w+\)/');
        }
    }
});

it('takes no reader, site, org or source from its caller', function (): void {
    $parameters = (new ReflectionMethod(EntitlementCheck::class, 'holds'))->getParameters();

    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['entitlement'])
        ->and(array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(EntitlementCheck::class))->getMethods(ReflectionMethod::IS_PUBLIC)))
        ->toBe(['__construct', 'holds']);
});

it('gives an owner no bypass', function (): void {
    Auth::guard(Fx::GUARD)->logout();
    $owner = Fx::owner();
    Fx::plant($this->site, (string) $owner->getKey(), 'course.advanced-php');

    expect(Fx::check()->holds('course.advanced-php'))->toBeFalse();
});
