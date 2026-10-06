<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialUnavailability;
use Kitsune\Core\Credentials\CredentialUnavailable;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * Test and live mode — ADR-040: "a mismatch between the mode a key belongs to and the mode an org is operating in is
 * refused, because money appearing to move without moving is the worst failure available here".
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    Fx::member();
});

afterEach(fn () => Fx::tearDown());

/** @param  Closure(): mixed  $write */
function credentialRefusal(Closure $write): CredentialRefusal
{
    try {
        $write();
    } catch (CredentialRefused $refused) {
        return $refused->reason;
    }

    throw new RuntimeException('it was written');
}

/** @param  Closure(): mixed  $read */
function credentialReadRefusal(Closure $read): CredentialUnavailability
{
    try {
        $read();
    } catch (CredentialUnavailable $unavailable) {
        return $unavailable->reason;
    }

    throw new RuntimeException('it was read');
}

it('puts a new org in test mode, and switches it once each way, recording each switch', function (): void {
    expect(Fx::states()->mode())->toBe(CredentialMode::Test)
        ->and(Fx::writer()->switchTo(CredentialMode::Test))->toBeFalse()
        ->and(Fx::writer()->switchTo(CredentialMode::Live))->toBeTrue()
        ->and(Fx::writer()->switchTo(CredentialMode::Live))->toBeFalse()
        ->and(Fx::states()->mode())->toBe(CredentialMode::Live)
        ->and(Fx::writer()->switchTo(CredentialMode::Test))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'like', 'credential.%')->orderBy('id')->pluck('action')->all())
        ->toBe([CredentialWriter::MODE_LIVE, CredentialWriter::MODE_TEST])
        ->and(DB::table('org_credential_modes')->count())->toBe(1);
});

it('keeps a mode on every stored value at the database, where a credential kept once files under none', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    // ⚠️ NOT NULL, because the unique index counts NULLs as distinct: two "unmoded" rows for one slot could sit side by
    // side, and which one a read found would be the engine's choice. In a savepoint, so PostgreSQL's transaction lives.
    $row = (array) DB::table('credentials')->first();
    unset($row['id']);

    expect(DB::table('credentials')->value('mode'))->toBe('none')
        ->and(fn () => DB::transaction(fn () => DB::table('credentials')->insert([...$row, 'mode' => null])))->toThrow(QueryException::class)
        ->and(DB::table('credentials')->count())->toBe(1);
});

/** ⚠️ ADR-040's own sentence, three ways. */
it('refuses a test-mode key against a live-mode org: at write, at read, and moved below the model', function (): void {
    Fx::writer()->switchTo(CredentialMode::Live);

    // At write: a test key pasted for live mode, named as one.
    expect(fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_test_')))->toThrow(
        new RuntimeException('Fixture payment key was not saved for live mode: it is a test-mode key (it begins fx_test_). A test key in live mode takes no money while appearing to (ADR-040). Nothing was written.'),
    );

    // At read: a live org with only a test value stored gets nothing, never the test value.
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));

    expect(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::NotSet);

    // Moved below the model, the test row's ciphertext into the live row: its envelope says test.
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));
    $test = DB::table('credentials')->where('mode', 'test')->first(['ciphertext', 'key_id']);
    DB::table('credentials')->where('mode', 'live')->update(['ciphertext' => $test->ciphertext, 'key_id' => $test->key_id]);

    expect(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::Misfiled);
});

it('refuses a live-mode key for test mode, in its own words', function (): void {
    expect(fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_live_')))->toThrow(
        new RuntimeException('Fixture payment key was not saved for test mode: it is a live-mode key (it begins fx_live_). A live key in test mode moves real money. Nothing was written.'),
    );
});

it('refuses a value that begins with neither mode\'s prefix as the wrong shape, naming what it takes', function (): void {
    expect(fn () => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('zz_live_')))->toThrow(
        new RuntimeException('Fixture payment key was not saved for live mode: a value here begins fx_live_, and this one does not. Nothing was written.'),
    );
});

it('takes a mode-neutral value for whichever line it was pasted into', function (): void {
    Fx::writer()->set(Fx::HOOK, CredentialMode::Test, $test = Fx::value('fxhook_', 40));
    Fx::writer()->set(Fx::HOOK, CredentialMode::Live, $live = Fx::value('fxhook_', 40));

    expect(Fx::reader()->secret(Fx::HOOK)->reveal())->toBe($test);

    Fx::writer()->switchTo(CredentialMode::Live);

    expect(Fx::reader()->secret(Fx::HOOK)->reveal())->toBe($live);
});

it('stores live keys while in test, and serves each mode only its own', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, $test = Fx::value('fx_test_'));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $live = Fx::value('fx_live_'));

    expect(Fx::reader()->secret(Fx::PAYMENT)->reveal())->toBe($test);

    Fx::writer()->switchTo(CredentialMode::Live);

    expect(Fx::reader()->secret(Fx::PAYMENT)->reveal())->toBe($live);

    Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live);

    expect(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::NotSet);
});

it('switches to live with live values missing, and each read then fails closed', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));

    expect(Fx::writer()->switchTo(CredentialMode::Live))->toBeTrue()
        ->and(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::NotSet);
});

it('needs the mode named for a credential kept per mode, and refuses one for a credential kept once', function (): void {
    expect(credentialRefusal(fn () => Fx::writer()->set(Fx::PAYMENT, null, Fx::value('fx_test_'))))->toBe(CredentialRefusal::ModeRequired)
        ->and(credentialRefusal(fn () => Fx::writer()->set(Fx::SHARED, CredentialMode::Live, Fx::value('', 40))))->toBe(CredentialRefusal::ModeNotTaken);
});

it('refuses on read a stored value its declaration no longer takes', function (): void {
    Fx::writer()->switchTo(CredentialMode::Live);
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));

    // A module upgrade that moved its prefixes about: the stored live value now reads as the test mode's.
    app(CredentialSlots::class)->flush();
    app(CredentialSlots::class)->register(new CredentialSlot(
        name: Fx::PAYMENT,
        label: 'Fixture payment key',
        help: 'Where the fixture provider shows it.',
        moded: true,
        prefixes: ['test' => ['fx_live_'], 'live' => ['fx_real_']],
        minLength: 32,
    ));

    expect(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::WrongMode);

    app(CredentialSlots::class)->flush();
    app(CredentialSlots::class)->register(new CredentialSlot(
        name: Fx::PAYMENT,
        label: 'Fixture payment key',
        help: 'Where the fixture provider shows it.',
        moded: true,
        prefixes: ['test' => ['fx_try_'], 'live' => ['fx_real_']],
        minLength: 32,
    ));

    expect(credentialReadRefusal(fn () => Fx::reader()->secret(Fx::PAYMENT)))->toBe(CredentialUnavailability::WrongShape);
});

it('offers no way to ask for the other mode\'s value', function (): void {
    $method = new ReflectionMethod(CredentialReader::class, 'secret');

    expect($method->getNumberOfParameters())->toBe(1)
        ->and($method->getParameters()[0]->getName())->toBe('slot');
});
