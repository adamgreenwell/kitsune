<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;

/*
 * The entitlement writer at transaction level 0, on a file another process can read — ADR-040: a change to a reader's
 * access that cannot be recorded is not kept, and a rival holding the database is a refusal, never a half-made grant.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('zero');
    $this->site = Fx::site($this->org, 'zero');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->id = (int) $this->reader->getKey();
    Fx::owner();
});

afterEach(fn () => Fx::tearDown());

/** What another process sees: the committed rows and their records. @return array{rows: list<array<string, mixed>>, audit: int} */
function entitlementZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);

    return [
        'rows' => $pdo->query('select * from entitlements order by id')->fetchAll(PDO::FETCH_ASSOC),
        'audit' => (int) $pdo->query("select count(*) from audit_log where action like 'entitlement.%'")->fetchColumn(),
    ];
}

/** From here on, the record of any change to an entitlement is refused by the database itself. */
function refuseEntitlementRecords(): void
{
    DB::statement("create trigger refuse_entitlement_records before insert on audit_log when new.action like 'entitlement.%' begin select raise(abort, 'the audit log is refusing'); end");
}

it('keeps nothing of a change whose record cannot be written, chaining nothing', function (Closure $before, Closure $change): void {
    $before($this);
    $seen = entitlementZeroSeen($this->custodyFile);
    refuseEntitlementRecords();

    try {
        $change($this);
        $this->fail('a change that could not be recorded was kept');
    } catch (EntitlementRefused $refused) {
        expect($refused->reason)->toBe(EntitlementRefusal::Database)
            ->and($refused->getPrevious())->toBeNull();
    }

    expect(entitlementZeroSeen($this->custodyFile))->toBe($seen)
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with([
    'a grant' => [fn () => null, fn ($t) => Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:1', null)],
    'an extension' => [
        fn ($t) => Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:1', CarbonImmutable::now()->addDay()),
        fn ($t) => Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:1', null),
    ],
    'a comp\'s reinstatement' => [
        function ($t): void {
            Fx::writer()->comp($t->id, 'course.advanced-php', null);
            Fx::writer()->revoke($t->id, 'course.advanced-php', EntitlementSource::COMP);
        },
        fn ($t) => Fx::writer()->comp($t->id, 'course.advanced-php', null),
    ],
    'a revoke' => [
        fn ($t) => Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:1', null),
        fn ($t) => Fx::writer()->revoke($t->id, 'course.advanced-php', 'test.order:1'),
    ],
    'an erasure' => [
        function ($t): void {
            Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:1', null);
            Fx::writer()->grant($t->id, 'course.advanced-php', 'test.order:2', null);
        },
        fn ($t) => Fx::writer()->forget($t->id),
    ],
]);

it('refuses, writing nothing, while a rival holds the database, and grants once it lets go', function (string $mode): void {
    DB::statement("pragma journal_mode = {$mode}");
    DB::connection()->getPdo()->exec('pragma busy_timeout = 200');

    $rival = new PDO('sqlite:'.$this->custodyFile);
    $rival->exec('begin immediate');

    try {
        try {
            Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
            $this->fail('a grant went through a rival holding the database');
        } catch (EntitlementRefused $refused) {
            expect($refused->reason)->toBeIn([EntitlementRefusal::Database, EntitlementRefusal::Race])
                ->and($refused->getPrevious())->toBeNull();
        }
    } finally {
        $rival->exec('rollback');
    }

    expect(entitlementZeroSeen($this->custodyFile))->toBe(['rows' => [], 'audit' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->toBe(GrantOutcome::Granted)
        ->and(entitlementZeroSeen($this->custodyFile)['audit'])->toBe(1);
})->with(['delete', 'wal']);

/**
 * ⚠️ A COMMIT THE DATABASE REFUSES IS RETHROWN RAW BY LARAVEL (review), and escaped every door as a `PDOException` no
 * producer branches on. It is a `Database` refusal now, which does not claim "nothing was written": a busy SQLite can
 * fail a COMMIT that landed. Asking again is safe, and the second answer says what the first could not.
 */
it('refuses a commit the database refused, in words that do not claim nothing was written, and asking again is safe', function (string $when, GrantOutcome $again): void {
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = $when;

    try {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        $this->fail('a refused commit answered');
    } catch (EntitlementRefused $refused) {
        expect($refused->reason)->toBe(EntitlementRefusal::Database)
            ->and($refused->getPrevious())->toBeNull()
            ->and($refused->getMessage())->toBe('[course.advanced-php] may not have been granted: the database refused to commit (SQLSTATE HY000), and whether the change applied cannot be told from here. Asking again is safe: a repeat changes nothing that already landed.');
    }

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->toBe($again)
        ->and(entitlementZeroSeen($this->custodyFile)['audit'])->toBe(1);
})->with([
    'before it applied' => ['before', GrantOutcome::Granted],
    'after it applied' => ['after', GrantOutcome::Unchanged],
]);

it('maps a refused commit at a revoke and an erasure too', function (): void {
    Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);

    foreach ([fn () => Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'), fn () => Fx::writer()->forget($this->id)] as $door) {
        $pdo->failNextCommit = 'before';

        try {
            $door();
            $this->fail('a refused commit answered');
        } catch (EntitlementRefused $refused) {
            expect($refused->reason)->toBe(EntitlementRefusal::Database)
                ->and($refused->getPrevious())->toBeNull();
        }
    }

    expect(entitlementZeroSeen($this->custodyFile)['rows'])->toHaveCount(1);
});
