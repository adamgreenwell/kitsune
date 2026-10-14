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
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\MissingTableReader;
use Kitsune\Core\Tests\Fixtures\ScopeStrippingReaderProvider;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Catalogue badges ask once for a bounded list, with the singular check's reader, source, time and isolation rules.
 * A batch never degrades a database failure into an empty answer or keeps a partial result (ADR-040, as amended).
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

/** @return list<array{sql: string, bindings: array<int, mixed>}> */
function entitlementBatchStatements(Closure $callback): array
{
    $seen = [];
    DB::listen(static function (QueryExecuted $query) use (&$seen): void {
        $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $callback();

    return $seen;
}

it('coalesces names in first occurrence order, with false answers for absent names and string keys throughout', function (): void {
    Fx::plant($this->site, $this->id, 'course.007');
    Fx::plant($this->site, $this->id, 'course.0');
    Fx::plant($this->site, $this->id, 'course.unrequested');

    expect(Fx::check()->holdsMany(['course.absent', 'course.007', 'course.0', 'course.007']))->toBe([
        'course.absent' => false,
        'course.007' => true,
        'course.0' => true,
    ]);
});

it('refuses every malformed batch before a query, without repeating its input', function (array $names): void {
    // An unusable reader table proves validation also precedes a session's first reader load.
    Fx::declareReaders(MissingTableReader::class);
    session()->put(Auth::guard(Fx::GUARD)->getName(), '81234');
    Auth::forgetGuards();

    $statements = entitlementBatchStatements(function () use ($names): void {
        try {
            Fx::check()->holdsMany($names);
            $this->fail('a malformed batch was accepted');
        } catch (InvalidArgumentException $invalid) {
            expect($invalid->getMessage())->toBe('An entitlement batch must be a list of at most 100 valid entitlement names.')
                ->and($invalid->getPrevious())->toBeNull();
        }
    });

    expect($statements)->toBe([]);
})->with([
    'an associative array' => [['reader' => 'course.held']],
    'a sparse list' => [[1 => 'course.held']],
    'an integer' => [[81234]],
    'a numeric string' => [['81234']],
    'a null' => [[null]],
    'a nested array' => [[['course.held']]],
    'an object' => [[(object) ['reader' => '81234']]],
    'a boolean' => [[true]],
    'a float' => [[1.0]],
    'a missing dot' => [['held']],
    'an uppercase name' => [['course.Held']],
    'a trailing newline' => [["course.held\n"]],
    'an overlong name' => [['course.'.str_repeat('a', 100)]],
    'one valid name before a bad name' => [['course.held', '81234']],
    '101 duplicates' => [array_fill(0, 101, 'course.held')],
]);

it('bounds raw input before deduplication and accepts exactly 100 items', function (): void {
    Fx::plant($this->site, $this->id, 'course.held');

    expect(EntitlementCheck::BATCH_LIMIT)->toBe(100)
        ->and(Fx::check()->holdsMany(array_fill(0, 100, 'course.held')))->toBe(['course.held' => true]);
});

it('does no work for an empty list, including no reader load', function (): void {
    Fx::declareReaders(MissingTableReader::class);
    session()->put(Auth::guard(Fx::GUARD)->getName(), '81234');
    Auth::forgetGuards();

    expect(entitlementBatchStatements(fn () => expect(Fx::check()->holdsMany([]))->toBe([])))->toBe([]);
});

it('checks 100 names with three sources each in one statement, selecting names alone', function (): void {
    $now = CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC');
    $this->travelTo($now);
    $expected = [];

    foreach (range(1, 100) as $number) {
        $name = 'course.product-'.$number;
        Fx::plant($this->site, $this->id, $name, 'commerce.order:1', revoked: $now->subDay());
        Fx::plant($this->site, $this->id, $name, 'commerce.order:2', expires: $now);
        Fx::plant($this->site, $this->id, $name, 'core.comp', expires: $now->addDay(), revoked: $number % 2 === 0 ? $now : null);
        $expected[$name] = $number % 2 === 1;
    }

    $check = Fx::check();
    $check->holdsMany(['course.warm-up']);
    $statements = entitlementBatchStatements(fn () => expect($check->holdsMany(array_keys($expected)))->toBe($expected));
    $sql = str_replace(['"', '`'], '', $statements[0]['sql'] ?? '');

    expect($statements)->toHaveCount(1)
        ->and($sql)->toStartWith('select distinct entitlements.entitlement from entitlements')
        ->and($sql)->toContain('entitlements.site_id = ? and entitlements.org_id = ? and entitlements.reader_id = ? and entitlements.entitlement in (')
        ->and($sql)->not->toContain('source')
        ->and($statements[0]['bindings'])->toContain($this->id)
        ->and(DB::table('entitlements')->count())->toBe(300)
        ->and(DB::table('audit_log')->count())->toBe(0);

    if (DB::connection()->getDriverName() === 'sqlite') {
        $plan = collect(DB::select('explain query plan '.$statements[0]['sql'], $statements[0]['bindings']))->pluck('detail')->implode(' | ');

        expect($plan)->toContain('USING INDEX entitlements_site_id_reader_id_entitlement_source_unique (site_id=? AND reader_id=? AND entitlement=?)');
    }
});

it('uses any live source in either insertion order, never whichever source sorts first', function (bool $liveFirst): void {
    $now = CarbonImmutable::now('UTC');
    $sources = [
        ['commerce.order:1', $now->subDay()],
        ['commerce.order:2', null],
    ];

    foreach ($liveFirst ? array_reverse($sources) : $sources as [$source, $revoked]) {
        Fx::plant($this->site, $this->id, 'course.held', $source, revoked: $revoked);
    }

    expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => true]);
})->with(['live first' => [true], 'live last' => [false]]);

it('uses UTC whole seconds with exclusive expiry and rejects a future-stamped revocation', function (): void {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    $end = CarbonImmutable::parse('2026-11-05 13:00:00', 'Europe/Paris');
    Fx::plant($this->site, $this->id, 'course.dated', expires: $end);
    Fx::plant($this->site, $this->id, 'course.lifetime');
    Fx::plant($this->site, $this->id, 'course.revoked-later', revoked: $end->addDay());

    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:59:59.999', 'America/New_York'));

    expect(Fx::check()->holdsMany(['course.dated', 'course.lifetime', 'course.revoked-later']))->toBe([
        'course.dated' => true, 'course.lifetime' => true, 'course.revoked-later' => false,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));

    expect(Fx::check()->holdsMany(['course.dated', 'course.lifetime', 'course.revoked-later']))->toBe([
        'course.dated' => false, 'course.lifetime' => true, 'course.revoked-later' => false,
    ]);
});

it('sees a grant and revoke in the same request through the same check instance', function (): void {
    $check = Fx::check();
    Fx::owner();

    expect($check->holdsMany(['course.held']))->toBe(['course.held' => false]);

    Fx::writer()->grant((int) $this->id, 'course.held', 'commerce.order:1', null);

    expect($check->holdsMany(['course.held']))->toBe(['course.held' => true]);

    Fx::writer()->revoke((int) $this->id, 'course.held', 'commerce.order:1');

    expect($check->holdsMany(['course.held']))->toBe(['course.held' => false]);
});

it('does not read a sibling site or another reader, and sees a context change without a memo', function (): void {
    Fx::plant($this->site, $this->id, 'course.held');
    $otherReader = Fx::reader(email: 'other@example.test');
    Fx::plant($this->site, (string) $otherReader->getKey(), 'course.somebody-else');
    $check = Fx::check();

    expect($check->holdsMany(['course.held', 'course.somebody-else']))->toBe([
        'course.held' => true, 'course.somebody-else' => false,
    ]);

    Fx::site($this->org, 'sibling');

    expect($check->holdsMany(['course.held', 'course.somebody-else']))->toBe([
        'course.held' => false, 'course.somebody-else' => false,
    ]);

    app(Context::class)->setSite($this->site);

    expect($check->holdsMany(['course.held']))->toBe(['course.held' => true]);
});

it('refuses a row filed under another org even when its site and reader match', function (): void {
    $rival = Fx::org('rival');
    Fx::plant($this->site, $this->id, 'course.planted', orgId: (int) $rival->getKey());
    app(Context::class)->setSite($this->site);

    expect(Fx::check()->holdsMany(['course.planted']))->toBe(['course.planted' => false]);
});

it('refuses another org\'s reader session even through a provider that strips the org scope', function (string $provider): void {
    $rival = Fx::org('rival');
    $rivalSite = Fx::site($rival, 'main');
    Fx::plant($rivalSite, $this->id, 'course.planted');
    Fx::declareReaders(TestReader::class, $provider);
    session()->put(Auth::guard(Fx::GUARD)->getName(), $this->reader->getKey());

    expect(Fx::check()->holdsMany(['course.planted']))->toBe(['course.planted' => false]);
})->with(['ordinary provider' => ['eloquent'], 'scope-stripping provider' => [ScopeStrippingReaderProvider::DRIVER]]);

it('answers false without site or org context before a reader load', function (): void {
    Fx::declareReaders(MissingTableReader::class);
    session()->put(Auth::guard(Fx::GUARD)->getName(), '81234');
    Auth::forgetGuards();
    app(Context::class)->setSite(null)->setOrg($this->org);

    expect(entitlementBatchStatements(fn () => expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => false])))->toBe([]);

    app(Context::class)->forget();

    expect(entitlementBatchStatements(fn () => expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => false])))->toBe([]);
});

it('gives staff no bypass and refuses an undeclared or staff-shaped reader guard', function (): void {
    Auth::guard(Fx::GUARD)->logout();
    $owner = Fx::owner();
    Fx::plant($this->site, (string) $owner->getKey(), 'course.held');

    expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => false]);

    config([ReaderGuard::CONFIG => null]);
    Fx::forget();

    expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => false]);

    Fx::declareReaders(TestUser::class);
    Fx::signIn($owner);

    expect(Fx::check()->holdsMany(['course.held']))->toBe(['course.held' => false]);
});

it('maps a real reader-load database failure to no answer and exposes neither reader nor names', function (): void {
    Fx::declareReaders(MissingTableReader::class);
    session()->put(Auth::guard(Fx::GUARD)->getName(), '81234');
    Auth::forgetGuards();

    try {
        DB::transaction(fn () => Fx::check()->holdsMany(['course.held', 'course.absent']));
        $this->fail('a failed reader load answered');
    } catch (EntitlementUnavailable $unavailable) {
        expect($unavailable->entitlement)->toBeNull()
            ->and($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->getMessage())->toStartWith('Whether the reader holds the requested entitlements could not be read: the database refused the query (SQLSTATE ')
            ->and($unavailable->getMessage())->not->toContain('81234', 'course.held', 'course.absent')
            ->and($unavailable->state)->toMatch('/^\w+$/');
    }
});

it('throws a batch database failure rather than returning an empty or partial answer', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits implicitly on MySQL and MariaDB; the reader-load failure covers every engine.');
    }

    Fx::plant($this->site, $this->id, 'course.held');
    DB::statement('alter table entitlements rename to entitlements_elsewhere');

    try {
        Fx::check()->holdsMany(['course.held', 'course.absent']);
        $this->fail('a failed batch query answered');
    } catch (EntitlementUnavailable $unavailable) {
        expect($unavailable->entitlement)->toBeNull()
            ->and($unavailable->getPrevious())->toBeNull()
            ->and($unavailable->getMessage())->not->toContain($this->id, 'course.held', 'course.absent');
    }
});

it('takes names alone and hides malformed arbitrary input from its own trace frame', function (): void {
    $method = new ReflectionMethod(EntitlementCheck::class, 'holdsMany');
    $parameters = $method->getParameters();

    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['entitlements'])
        ->and($parameters[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);

    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        Fx::check()->holdsMany(['81234']);
        $this->fail('a numeric reader-like name was accepted');
    } catch (InvalidArgumentException $invalid) {
        $frame = $invalid->getTrace()[0];

        expect($frame['class'])->toBe(EntitlementCheck::class)
            ->and($frame['function'])->toBe('holdsMany')
            ->and($frame['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($invalid->getMessage())->not->toContain('81234');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});
