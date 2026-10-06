<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * The one door in — ADR-040: what it writes, what it records, and what it refuses with nothing written or recorded.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->owner = Fx::member();
});

afterEach(fn () => Fx::tearDown());

/** @return list<string> the audit actions recorded for the org in context, oldest first */
function credentialActions(): array
{
    return AuditLog::query()->where('action', 'like', 'credential.%')->orderBy('id')->pluck('action')->all();
}

it('stores a value a consumer reads back exactly', function (): void {
    $value = Fx::value('fx_test_');

    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, $value);

    expect(Fx::reader()->secret(Fx::PAYMENT)->reveal())->toBe($value)
        ->and(credentialActions())->toBe([CredentialWriter::SET]);
});

it('records a set, a replace, a removal, and a set again after it, one row each', function (): void {
    $writer = Fx::writer();

    $writer->set(Fx::SHARED, null, Fx::value('', 40));
    $first = Credential::query()->firstOrFail();
    $writer->set(Fx::SHARED, null, Fx::value('', 40));
    $writer->remove(Fx::SHARED, null);
    $writer->remove(Fx::SHARED, null);
    $writer->set(Fx::SHARED, null, Fx::value('', 40));

    $row = Credential::query()->sole();

    expect(credentialActions())->toBe([CredentialWriter::SET, CredentialWriter::REPLACED, CredentialWriter::REMOVED, CredentialWriter::SET])
        ->and($row->getKey())->toBe($first->getKey())
        ->and(AuditLog::query()->where('action', 'like', 'credential.%')->pluck('target_id')->unique()->values()->all())->toBe([(string) $first->getKey()]);
});

it('tombstones a removal: the row stays as the record\'s target, with no value and no key', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    $before = Credential::query()->sole();
    $this->travel(5)->minutes();

    Fx::writer()->remove(Fx::SHARED, null);
    $after = Credential::query()->sole();

    expect($after->getKey())->toBe($before->getKey())
        ->and($after->ciphertext)->toBeNull()
        ->and($after->key_id)->toBeNull()
        ->and($after->changed_at->greaterThan($before->changed_at))->toBeTrue();
});

it('removes nothing that is not there, and records nothing', function (): void {
    Fx::writer()->remove(Fx::SHARED, null);

    expect(Credential::query()->exists())->toBeFalse()
        ->and(credentialActions())->toBe([]);
});

it('never records the value, its ends, the credential\'s name or its ciphertext', function (): void {
    $value = Fx::value('fx_live_');

    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $value);
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));
    $ciphertext = (string) DB::table('credentials')->value('ciphertext');
    Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live);
    Fx::writer()->switchTo(CredentialMode::Live);

    $rows = DB::table('audit_log')->where('action', 'like', 'credential.%')->get()->map(fn (object $row): array => (array) $row)->all();

    expect($rows)->toHaveCount(4);

    // ⚠️ Not `->not->toContain($needle, $message)`: `toContain()` is variadic, so a message becomes a second needle.
    foreach ($rows as $row) {
        foreach ($row as $column => $held) {
            foreach (['value' => $value, 'its start' => substr($value, 0, 12), 'its end' => substr($value, -8), 'the name' => Fx::PAYMENT, 'the ciphertext' => substr($ciphertext, 0, 24)] as $what => $needle) {
                expect(str_contains((string) $held, $needle))->toBeFalse("[{$column}] holds {$what}");
            }
        }
    }
});

it('refuses, writing and recording nothing', function (Closure $write, CredentialRefusal $reason): void {
    Fx::writer()->set(Fx::SHARED, null, $kept = Fx::value('', 40));
    $before = DB::table('credentials')->get()->all();
    $actions = credentialActions();

    try {
        $write();
        $this->fail('nothing was refused');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe($reason)
            ->and($refused->getPrevious())->toBeNull();
    }

    expect(DB::table('credentials')->get()->all())->toEqual($before)
        ->and(credentialActions())->toBe($actions)
        ->and(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($kept);
})->with([
    'not a name' => [fn () => Fx::writer()->set('Not A Name', null, Fx::value('', 40)), CredentialRefusal::NotAName],
    'nothing declares it' => [fn () => Fx::writer()->set('fx.nobody', null, Fx::value('', 40)), CredentialRefusal::UnknownSlot],
    'no mode for a moded one' => [fn () => Fx::writer()->set(Fx::PAYMENT, null, Fx::value('fx_test_')), CredentialRefusal::ModeRequired],
    'a mode for one value' => [fn () => Fx::writer()->set(Fx::SHARED, CredentialMode::Test, Fx::value('', 40)), CredentialRefusal::ModeNotTaken],
    'empty' => [fn () => Fx::writer()->set(Fx::SHARED, null, ''), CredentialRefusal::Empty],
    'a space' => [fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40).' x'), CredentialRefusal::Characters],
    'a line break' => [fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)."\n"), CredentialRefusal::Characters],
    'beyond ASCII' => [fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40).'é'), CredentialRefusal::Characters],
    'too short' => [fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 31)), CredentialRefusal::TooShort],
    'too long' => [fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 256)), CredentialRefusal::TooLong],
    'the other mode' => [fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_test_')), CredentialRefusal::OtherMode],
    'no prefix it takes' => [fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('zz_test_')), CredentialRefusal::Shape],
    'removing what nothing declares' => [fn () => Fx::writer()->remove('fx.nobody', null), CredentialRefusal::UnknownSlot],
]);

it('refuses a member who is not an owner, and writes nothing', function (): void {
    Fx::member(owner: false, email: 'member@kitsune.test');

    foreach ([
        fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)),
        fn () => Fx::writer()->remove(Fx::SHARED, null),
        fn () => Fx::writer()->switchTo(CredentialMode::Live),
    ] as $write) {
        try {
            $write();
            $this->fail('a member who is not an owner was let through');
        } catch (CredentialRefused $refused) {
            expect($refused->reason)->toBe(CredentialRefusal::NotAnOwner);
        }
    }

    expect(DB::table('credentials')->count())->toBe(0)
        ->and(DB::table('org_credential_modes')->count())->toBe(0)
        ->and(credentialActions())->toBe([]);
});

it('names the owner rule when a member is refused', function (): void {
    Fx::member(owner: false, email: 'member@kitsune.test');

    expect(fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)))->toThrow(
        new RuntimeException('Refusing to change a credential: only an owner of this organisation may change its credentials (ADR-033). Nothing was written.'),
    );
});

it('writes for the system with nobody signed in, as an unattributed record', function (): void {
    Auth::guard('web')->logout();

    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    expect(AuditLog::query()->where('action', CredentialWriter::SET)->sole()->actor_id)->toBeNull();
});

it('refuses with no organisation in context', function (): void {
    app(Context::class)->forget();

    expect(fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)))->toThrow(
        new RuntimeException('Refusing to change a credential: there is no organisation context, so the change could not be recorded, and an unrecorded change is refused (ADR-020). Nothing was written.'),
    )->and(DB::table('credentials')->count())->toBe(0);
});

it('writes and records nothing when a listener cancels the save', function (): void {
    Credential::saving(static fn (): bool => false);

    try {
        expect(fn () => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40)))->toThrow(
            new RuntimeException('Fixture shared secret was not saved: a listener cancelled the save. Nothing was written, and nothing is recorded.'),
        );
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }

    expect(DB::table('credentials')->count())->toBe(0)
        ->and(credentialActions())->toBe([]);
});

it('keeps the old value when an enclosing transaction rolls back', function (): void {
    Fx::writer()->set(Fx::SHARED, null, $kept = Fx::value('', 40));

    try {
        DB::transaction(function (): void {
            Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

            throw new RuntimeException('the caller changed its mind');
        });
    } catch (RuntimeException) {
    }

    expect(Fx::reader()->secret(Fx::SHARED)->reveal())->toBe($kept)
        ->and(credentialActions())->toBe([CredentialWriter::SET]);
});

it('always records a replace, even of the same value, because telling would mean reading it', function (): void {
    $value = Fx::value('', 40);

    Fx::writer()->set(Fx::SHARED, null, $value);
    Fx::writer()->set(Fx::SHARED, null, $value);

    expect(credentialActions())->toBe([CredentialWriter::SET, CredentialWriter::REPLACED]);
});
