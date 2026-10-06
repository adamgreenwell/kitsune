<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AttributeOnlyReader;
use Kitsune\Core\Tests\Fixtures\CaseFoldingReader;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\MissingTableReader;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The one door in — ADR-040: its decision table per source, who may act, and what it never writes down. Audit is
 * asserted by count throughout: a write that changes nothing records nothing.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->id = (int) $this->reader->getKey();
    $this->owner = Fx::owner();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
});

afterEach(fn () => Fx::tearDown());

/** The entitlement audit rows, oldest first. */
function entitlementAudit(): array
{
    return AuditLog::query()->where('action', 'like', 'entitlement.%')->orderBy('id')->pluck('action')->all();
}

function refusalOf(Closure $write): EntitlementRefused
{
    try {
        $write();
    } catch (EntitlementRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The write was not refused.');
}

function at(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

describe('a grant or a comp, on its own source\'s row', function (): void {
    it('writes a row where there was none, and records it', function (string $door): void {
        $outcome = $door === 'grant'
            ? Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2027-01-01'))
            : Fx::writer()->comp($this->id, 'course.advanced-php', at('2027-01-01'));

        $row = Entitlement::query()->sole();

        expect($outcome)->toBe(GrantOutcome::Granted)
            ->and($row->org_id)->toBe($this->org->getKey())
            ->and($row->site_id)->toBe($this->site->getKey())
            ->and($row->reader_id)->toBe((string) $this->id)
            ->and($row->source)->toBe($door === 'grant' ? 'test.order:1' : 'core.comp')
            ->and($row->expires_at?->toDateTimeString())->toBe('2027-01-01 00:00:00')
            ->and($row->revoked_at)->toBeNull()
            ->and($row->changed_at->toDateTimeString())->toBe('2026-10-06 12:00:00')
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED]);
    })->with(['grant', 'comp']);

    it('decides by the row and the end given', function (?string $existing, ?string $end, GrantOutcome $outcome, ?string $stored): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', $existing !== null ? at($existing) : null);
        Fx::writer()->comp($this->id, 'course.advanced-php', $existing !== null ? at($existing) : null);
        $before = entitlementAudit();

        expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', $end !== null ? at($end) : null))->toBe($outcome)
            ->and(Fx::writer()->comp($this->id, 'course.advanced-php', $end !== null ? at($end) : null))->toBe($outcome);

        foreach (Entitlement::query()->get() as $row) {
            expect($row->expires_at?->toDateTimeString())->toBe($stored);
        }

        expect(entitlementAudit())->toBe($outcome === GrantOutcome::Extended
            ? [...$before, EntitlementWriter::EXTENDED, EntitlementWriter::EXTENDED]
            : $before);
    })->with([
        'no end, then any end' => [null, '2027-01-01', GrantOutcome::Unchanged, null],
        'no end, then no end' => [null, null, GrantOutcome::Unchanged, null],
        'an end, then no end' => ['2027-01-01', null, GrantOutcome::Extended, null],
        'an end, then a later one' => ['2027-01-01', '2027-06-01', GrantOutcome::Extended, '2027-06-01 00:00:00'],
        'an end, then the same' => ['2027-01-01', '2027-01-01', GrantOutcome::Unchanged, '2027-01-01 00:00:00'],
        'an end, then an earlier one' => ['2027-01-01', '2026-12-01', GrantOutcome::Unchanged, '2027-01-01 00:00:00'],
    ]);

    it('writes and records once, however often the same grant comes', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2027-01-01'));
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2027-01-01'));

        expect(Entitlement::query()->count())->toBe(1)
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED]);
    });

    it('extends a lapsed row of the same source, recording an extension', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2026-10-07'));
        $this->travelTo(at('2026-11-01'));

        expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2026-12-01')))->toBe(GrantOutcome::Extended)
            ->and(Entitlement::query()->sole()->expires_at?->toDateTimeString())->toBe('2026-12-01 00:00:00')
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED, EntitlementWriter::EXTENDED]);
    });

    it('refuses an end at or before now, or past what every engine stores', function (Closure $until, EntitlementRefusal $reason): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00.000', 'UTC'));

        foreach (['grant' => fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', $until()), 'comp' => fn () => Fx::writer()->comp($this->id, 'course.advanced-php', $until())] as $write) {
            expect(refusalOf($write)->reason)->toBe($reason);
        }

        expect(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    })->with([
        'now' => [fn () => at('2026-10-06 12:00:00'), EntitlementRefusal::AlreadyEnded],
        'a fraction after now' => [fn () => CarbonImmutable::parse('2026-10-06 12:00:00.400', 'UTC'), EntitlementRefusal::AlreadyEnded],
        'before now' => [fn () => at('2026-10-06 11:59:59'), EntitlementRefusal::AlreadyEnded],
        'a second after 9999' => [fn () => at('9999-12-31 23:59:59')->addSecond(), EntitlementRefusal::TooFar],
    ]);

    it('takes the last second every engine stores', function (): void {
        expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('9999-12-31 23:59:59')))->toBe(GrantOutcome::Granted);
    });

    it('never defaults the end, so no end is always said', function (string $method): void {
        $until = array_values(array_filter(
            (new ReflectionMethod(EntitlementWriter::class, $method))->getParameters(),
            static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'until',
        ))[0];

        expect($until->isDefaultValueAvailable())->toBeFalse()
            ->and($until->allowsNull())->toBeTrue();
    })->with(['grant', 'comp']);
});

describe('a revoke, on its own source\'s row', function (): void {
    it('stamps a live row, and records it', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        $this->travelTo(at('2026-10-07 08:00:00'));

        expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeTrue();

        $row = Entitlement::query()->sole();

        expect($row->revoked_at?->toDateTimeString())->toBe('2026-10-07 08:00:00')
            ->and($row->changed_at->toDateTimeString())->toBe('2026-10-07 08:00:00')
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED, EntitlementWriter::REVOKED]);
    });

    it('stamps a lapsed row too, and records it', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', at('2026-10-07'));
        $this->travelTo(at('2026-11-01'));

        expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeTrue()
            ->and(Entitlement::query()->sole()->revoked_at)->not->toBeNull()
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED, EntitlementWriter::REVOKED]);
    });

    it('writes and records nothing for an absent or an already revoked row', function (): void {
        expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeFalse();

        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1');
        $row = (array) DB::table('entitlements')->first();
        $this->travelTo(at('2026-12-01'));

        expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeFalse()
            ->and((array) DB::table('entitlements')->first())->toBe($row)
            ->and(entitlementAudit())->toBe([EntitlementWriter::GRANTED, EntitlementWriter::REVOKED]);
    });

    it('takes access from a reader the host has already deleted', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        TestReader::query()->whereKey($this->id)->delete();

        expect(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeTrue();
    });
});

describe('the reader', function (): void {
    it('is stored as one string however the caller spells the key', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        Fx::writer()->grant((string) $this->id, 'course.advanced-php', 'test.order:1', null);

        expect(Entitlement::query()->sole()->reader_id)->toBe((string) $this->id)
            ->and(refusalOf(fn () => Fx::writer()->grant('0'.$this->id, 'course.advanced-php', 'test.order:1', null))->reason)
            ->toBe(EntitlementRefusal::NotAReader);
    });

    it('is stored as the host spells them', function (): void {
        Fx::declareReaders(CaseFoldingReader::class);
        CaseFoldingReader::query()->create(['id' => 'abc', 'email' => 'abc@example.test']);
        Fx::owner('owner2@kitsune.test');

        Fx::writer()->grant('ABC', 'course.advanced-php', 'test.order:1', null);

        expect(Entitlement::query()->sole()->reader_id)->toBe('abc');
    });

    it('is matched as the host spells them on a revoke, an export and an erasure', function (): void {
        Fx::declareReaders(CaseFoldingReader::class);
        CaseFoldingReader::query()->create(['id' => 'abc', 'email' => 'abc@example.test']);
        Fx::owner('owner2@kitsune.test');
        Fx::writer()->grant('abc', 'course.advanced-php', 'test.order:1', null);
        Fx::writer()->grant('abc', 'course.advanced-php', 'test.order:2', null);

        expect(Fx::writer()->revoke('ABC', 'course.advanced-php', 'test.order:1'))->toBeTrue()
            ->and(EntitlementRecords::forReader('ABC'))->toHaveCount(2)
            ->and(Fx::writer()->forget('ABC'))->toBe(2)
            ->and(DB::table('entitlements')->count())->toBe(0);
    });

    it('must belong to this organisation for a grant or a comp', function (Closure $whose): void {
        $id = $whose($this);

        foreach ([fn () => Fx::writer()->grant($id, 'course.advanced-php', 'test.order:1', null), fn () => Fx::writer()->comp($id, 'course.advanced-php', null)] as $write) {
            $refused = refusalOf($write);

            expect($refused->reason)->toBe(EntitlementRefusal::UnknownReader)
                ->and($refused->getMessage())->not->toContain((string) $id);
        }

        expect(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    })->with([
        'nobody' => [fn () => 987654],
        'another org\'s reader' => [fn ($test) => (int) Fx::reader(Org::create(['slug' => 'other', 'name' => 'Other']), 'other@example.test')->getKey()],
    ]);
});

describe('who may act', function (): void {
    it('lets an owner do everything, recorded as the owner', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        Fx::writer()->comp($this->id, 'course.advanced-php', null);
        Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1');
        EntitlementRecords::forReader($this->id);
        Fx::writer()->forget($this->id);

        $actors = AuditLog::query()->where('action', 'like', 'entitlement.%')->get(['actor_type', 'actor_id'])->map(fn ($row) => [$row->actor_type, $row->actor_id])->unique()->values()->all();

        expect($actors)->toBe([[(new TestUser)->getMorphClass(), (string) $this->owner->getKey()]]);
    });

    it('trusts the system for a grant, a revoke, an export and an erasure, recording no actor', function (): void {
        Fx::nobody();

        expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->toBe(GrantOutcome::Granted)
            ->and(Fx::writer()->revoke($this->id, 'course.advanced-php', 'test.order:1'))->toBeTrue()
            ->and(EntitlementRecords::forReader($this->id))->toHaveCount(1)
            ->and(Fx::writer()->forget($this->id))->toBe(1)
            ->and(AuditLog::query()->where('action', 'like', 'entitlement.%')->whereNotNull('actor_id')->count())->toBe(0);
    });

    it('never trusts the system with a comp', function (): void {
        Fx::nobody();

        expect(refusalOf(fn () => Fx::writer()->comp($this->id, 'course.advanced-php', null))->reason)->toBe(EntitlementRefusal::NotAnOwner)
            ->and(Entitlement::query()->count())->toBe(0);
    });

    it('refuses a member who is not an owner, at every door', function (): void {
        Fx::member();
        Fx::forget();

        foreach (everyEntitlementDoor($this->id) as $door => $act) {
            expect(refusalOf($act)->reason)->toBe(EntitlementRefusal::NotAnOwner, $door);
        }

        expect(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    });

    it('refuses a reader\'s own request at every door, whether their session rides along or the route made them the user', function (bool $shouldUse): void {
        Fx::nobody();
        Fx::signIn($this->reader);

        if ($shouldUse) {
            Auth::shouldUse(Fx::GUARD);
        }

        foreach (everyEntitlementDoor($this->id) as $door => $act) {
            expect(refusalOf($act)->reason)->toBe(EntitlementRefusal::ReaderActing, $door);
        }

        expect(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    })->with(['riding along' => [false], 'the route\'s user' => [true]]);

    it('refuses a reader\'s own request to export or erase with the org alone in context', function (): void {
        Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);
        $victim = (int) Fx::reader(email: 'victim@example.test')->getKey();
        Fx::writer()->grant($victim, 'course.advanced-php', 'test.order:2', null);
        Fx::nobody();
        Fx::signIn($this->reader);
        app(Context::class)->setSite(null)->setOrg($this->org);

        expect(app(Context::class)->siteId())->toBeNull()
            ->and(refusalOf(fn () => EntitlementRecords::forReader($victim))->reason)->toBe(EntitlementRefusal::ReaderActing)
            ->and(refusalOf(fn () => Fx::writer()->forget($victim))->reason)->toBe(EntitlementRefusal::ReaderActing)
            ->and(DB::table('entitlements')->count())->toBe(2);
    });

    it('refuses a reader\'s own request on a guard core cannot use for readers', function (): void {
        Fx::nobody();
        Fx::declareReaders(AttributeOnlyReader::class);
        Fx::signIn((new AttributeOnlyReader)->forceFill($this->reader->getAttributes()));

        expect(Fx::guard()->fault())->not->toBeNull()
            ->and(refusalOf(fn () => Fx::writer()->forget($this->id))->reason)->toBe(EntitlementRefusal::ReaderActing)
            ->and(refusalOf(fn () => EntitlementRecords::forReader($this->id))->reason)->toBe(EntitlementRefusal::ReaderActing);
    });

    it('lets an owner act while a reader\'s session rides along', function (): void {
        Fx::signIn($this->reader);

        expect(Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->toBe(GrantOutcome::Granted);
    });
});

/** @return array<string, Closure> */
function everyEntitlementDoor(int $id): array
{
    return [
        'grant' => fn () => Fx::writer()->grant($id, 'course.advanced-php', 'test.order:1', null),
        'comp' => fn () => Fx::writer()->comp($id, 'course.advanced-php', null),
        'revoke' => fn () => Fx::writer()->revoke($id, 'course.advanced-php', 'test.order:1'),
        'forget' => fn () => Fx::writer()->forget($id),
        'export' => fn () => EntitlementRecords::forReader($id),
    ];
}

describe('what it never writes down', function (): void {
    it('keeps the reader, the name and the source out of every audit column', function (): void {
        DB::table('test_readers')->where('id', $this->id)->update(['id' => 81234]);
        $this->id = 81234;

        Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93177', at('2027-01-01'));
        Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93177', null);
        Fx::writer()->comp($this->id, 'course.advanced-php', null);
        Fx::writer()->revoke($this->id, 'course.advanced-php', EntitlementSource::COMP);
        Fx::writer()->comp($this->id, 'course.advanced-php', null);
        Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:93177');
        Fx::writer()->forget($this->id);

        $rows = DB::table('audit_log')->where('action', 'like', 'entitlement.%')->get();

        expect($rows->pluck('action')->all())->toBe([
            EntitlementWriter::GRANTED, EntitlementWriter::EXTENDED, EntitlementWriter::GRANTED, EntitlementWriter::REVOKED,
            EntitlementWriter::REINSTATED, EntitlementWriter::REVOKED, EntitlementWriter::ERASED, EntitlementWriter::ERASED,
        ]);

        foreach ($rows as $row) {
            foreach ((array) $row as $column => $value) {
                foreach (['81234', 'course.advanced-php', 'commerce.order:93177', '93177', 'core.comp'] as $secret) {
                    expect(str_contains((string) $value, $secret))->toBeFalse("audit_log.{$column} holds {$secret}");
                }
            }

            expect($row->target_type)->toBe((new Entitlement)->getMorphClass());
        }
    });

    it('repeats neither the reader nor the source in any refusal', function (): void {
        $reader = 81234;
        $refusals = [];

        $refusals[] = refusalOf(fn () => Fx::writer()->grant($reader, 'course.advanced-php', 'commerce.order:93177', null));
        $refusals[] = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93177', at('2020-01-01')));
        $refusals[] = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'core.comp:93177', null));
        $refusals[] = refusalOf(fn () => Fx::writer()->grant('81234x', 'course.advanced-php', 'commerce.order:93177', null));
        $refusals[] = refusalOf(fn () => Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:93177 '));
        Fx::member('m@kitsune.test');
        Fx::forget();
        $refusals[] = refusalOf(fn () => Fx::writer()->forget($reader));

        foreach ($refusals as $refused) {
            expect($refused->getMessage())->not->toContain('81234')
                ->and($refused->getMessage())->not->toContain('93177')
                ->and($refused->getPrevious())->toBeNull()
                ->and(array_keys(get_object_vars($refused)))->toBe(['reason', 'entitlement']);
        }
    });
});

describe('a write that does not land', function (): void {
    it('is refused as cancelled when a listener cancels the save, recording nothing', function (): void {
        Entitlement::saving(static fn (): bool => false);

        try {
            $refused = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null));
        } finally {
            Entitlement::flushEventListeners();
            Entitlement::clearBootedModels();
        }

        expect($refused->reason)->toBe(EntitlementRefusal::Cancelled)
            ->and($refused->getMessage())->toBe('[course.advanced-php] was not granted: a listener cancelled the save. Nothing was written, and nothing is recorded.')
            ->and(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    });

    it('is refused as a race, chaining nothing, when the unique index catches a second row', function (): void {
        Entitlement::creating(function (): void {
            Fx::plant($this->site, (string) $this->id, 'course.advanced-php', 'test.order:1');
        });

        try {
            $refused = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null));
        } finally {
            Entitlement::flushEventListeners();
            Entitlement::clearBootedModels();
        }

        expect($refused->reason)->toBe(EntitlementRefusal::Race)
            ->and($refused->getPrevious())->toBeNull()
            ->and($refused->getMessage())->toBe('[course.advanced-php] was not granted: it was changed somewhere else at the same moment. Nothing was written; try again.')
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    });

    it('turns a deadlock inside a caller\'s transaction into a race that chains nothing', function (): void {
        // The test's own transaction is the caller's, so the writer's is nested: Laravel turns a deadlock into a
        // `DeadlockException` chaining the query — the reader's id and the source among its bindings.
        expect(DB::transactionLevel())->toBeGreaterThan(0);

        DB::listen(static function (QueryExecuted $query): void {
            if (preg_match('/^insert into [`"]?entitlements[`"]?/i', $query->sql) === 1) {
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
            }
        });

        $refused = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93177', null));

        expect($refused->reason)->toBe(EntitlementRefusal::Race)
            ->and($refused->getPrevious())->toBeNull()
            ->and($refused->getMessage())->not->toContain('93177');
    });

    it('rolls its own writes back when the record of them times out inside a caller\'s transaction', function (): void {
        // The case `TransactionRecovery` is for: nested, Laravel turns a lock-wait timeout into a `DeadlockException`
        // without `ROLLBACK TO`, and the row saved a moment before would stay in the caller's transaction — committed,
        // and never recorded, if the caller went on.
        expect(DB::transactionLevel())->toBeGreaterThan(0);

        DB::listen(static function (QueryExecuted $query): void {
            if (preg_match('/^insert into [`"]?audit_log[`"]?/i', $query->sql) === 1) {
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
            }
        });

        expect(refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->reason)->toBe(EntitlementRefusal::Race)
            ->and(DB::table('entitlements')->count())->toBe(0)
            ->and(DB::table('audit_log')->where('action', 'like', 'entitlement.%')->count())->toBe(0);
    });

    it('leaves nothing when the caller\'s transaction rolls back', function (): void {
        try {
            DB::transaction(function (): never {
                Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null);

                throw new RuntimeException('the caller changed its mind');
            });
        } catch (RuntimeException) {
        }

        expect(Entitlement::query()->count())->toBe(0)
            ->and(entitlementAudit())->toBe([]);
    });

    it('refuses with no site, or a site that is gone, in its own words', function (): void {
        app(Context::class)->setSite(null)->setOrg($this->org);

        $none = refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null));

        expect($none->reason)->toBe(EntitlementRefusal::NoSiteContext)
            ->and($none->getMessage())->toBe('Refusing to grant an entitlement: there is no site in context. An entitlement belongs to one site (ADR-040, ADR-037), and the writer takes the site its caller set, never an argument. Nothing was written.');

        app(Context::class)->setSite($this->site);
        DB::table('sites')->where('id', $this->site->getKey())->delete();

        expect(refusalOf(fn () => Fx::writer()->grant($this->id, 'course.advanced-php', 'test.order:1', null))->reason)->toBe(EntitlementRefusal::SiteGone);
    });

    it('maps a failure loading a reader\'s session at every write door, with nobody on web', function (): void {
        Fx::declareReaders(MissingTableReader::class);
        Fx::nobody();
        session()->put(Auth::guard(Fx::GUARD)->getName(), '81234');
        Auth::forgetGuards();
        Fx::forget();

        foreach ([
            'grant' => fn () => Fx::writer()->grant(81234, 'course.advanced-php', 'test.order:1', null),
            'comp' => fn () => Fx::writer()->comp(81234, 'course.advanced-php', null),
            'revoke' => fn () => Fx::writer()->revoke(81234, 'course.advanced-php', 'test.order:1'),
            'forget' => fn () => Fx::writer()->forget(81234),
            'export' => fn () => EntitlementRecords::forReader(81234),
        ] as $door => $act) {
            // Each in a savepoint of its own: on PostgreSQL a failed statement aborts the test's transaction.
            $refused = refusalOf(fn () => DB::transaction($act));

            expect($refused->reason)->toBe(EntitlementRefusal::Database, $door)
                ->and($refused->getPrevious())->toBeNull()
                ->and($refused->getMessage())->not->toContain('81234');
        }
    });

    it('refuses an erasure or an export with no organisation in context', function (): void {
        app(Context::class)->forget();

        foreach ([fn () => Fx::writer()->forget($this->id), fn () => EntitlementRecords::forReader($this->id)] as $door) {
            expect(refusalOf($door)->reason)->toBe(EntitlementRefusal::NoOrgContext);
        }
    });
});

it('is built afresh for each request, as the request\'s site and reader are', function (string $abstract): void {
    $first = app($abstract);
    app()->forgetScopedInstances();

    expect(app($abstract))->not->toBe($first);
})->with([
    ReaderGuard::class,
    EntitlementCheck::class,
    EntitlementWriter::class,
]);
