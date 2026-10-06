<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * Write-only, asked of every exit a value could take from inside the process — ADR-040: "a secret that cannot be read
 * back through any admin path". The admin's own exits are PR B's browser test.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    Fx::member();
});

afterEach(fn () => Fx::tearDown());

/** @return list<string> the value, and the pieces of it a careless message would show */
function credentialPieces(string $value): array
{
    return [$value, substr($value, 0, 12), substr($value, -8)];
}

/**
 * Every string a trace's frames were called with, arrays opened to a few levels — what a reporter that prints arguments
 * would print. Objects are left out: a trace shows them as `Object(Class)`, and printing the application whole does not
 * fit in memory.
 *
 * @param  array<int, array<string, mixed>>  $trace
 * @return list<string>
 */
function credentialTraceStrings(array $trace): array
{
    $found = [];
    $walk = static function (mixed $value, int $depth) use (&$walk, &$found): void {
        if (is_string($value)) {
            $found[] = $value;
        } elseif (is_array($value) && $depth < 4) {
            foreach ($value as $item) {
                $walk($item, $depth + 1);
            }
        }
    };

    foreach (credentialProductionFrames($trace) as $frame) {
        $walk($frame['args'] ?? [], 0);
    }

    return $found;
}

/**
 * The frames of core's own code. The test's closures take the value as an argument too, and say nothing about whether
 * the store keeps it out of a trace.
 *
 * @param  array<int, array<string, mixed>>  $trace
 * @return list<array<string, mixed>>
 */
function credentialProductionFrames(array $trace): array
{
    return array_values(array_filter($trace, static fn (array $frame): bool => str_starts_with((string) ($frame['class'] ?? ''), 'Kitsune\\Core\\')
        && ! str_starts_with((string) ($frame['class'] ?? ''), 'Kitsune\\Core\\Tests\\')));
}

/** The same, from the trace as a string: the lines that call into core's own code. */
function credentialProductionLines(string $trace): string
{
    return implode("\n", array_filter(explode("\n", $trace), static fn (string $line): bool => str_contains($line, '): Kitsune\\Core\\')
        && ! str_contains($line, '): Kitsune\\Core\\Tests\\')));
}

/** Not vacuous: core's frames really do carry arguments, so the value's absence is the attribute's doing. */
function credentialTraceHasArguments(Throwable $e): bool
{
    foreach (credentialProductionFrames($e->getTrace()) as $frame) {
        if (($frame['args'] ?? []) !== []) {
            return true;
        }
    }

    return false;
}

function credentialHoldsNone(string $haystack, string $value, string $where): void
{
    foreach (credentialPieces($value) as $piece) {
        expect(str_contains($haystack, $piece))->toBeFalse("{$where} holds a piece of the value");
    }
}

it('turns a model into an array, JSON or a string with not even its ciphertext', function (): void {
    Fx::writer()->set(Fx::SHARED, null, $value = Fx::value('', 40));
    $row = Credential::query()->sole();
    $ciphertext = (string) DB::table('credentials')->value('ciphertext');

    foreach (['toArray' => json_encode($row->toArray()), 'toJson' => $row->toJson(), 'string' => (string) $row, 'json_encode' => json_encode($row)] as $how => $out) {
        expect(str_contains((string) $out, substr($ciphertext, 0, 24)))->toBeFalse("{$how} holds the ciphertext")
            ->and(str_contains((string) $out, (string) $row->key_id))->toBeFalse("{$how} holds the key id");
        credentialHoldsNone((string) $out, $value, $how);
    }
});

it('keeps the value out of every refusal\'s message and trace, even with arguments in traces', function (Closure $write, string $value): void {
    $before = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        $write($value);
        $this->fail('nothing was refused');
    } catch (CredentialRefused $refused) {
        credentialHoldsNone($refused->getMessage(), $value, 'the message');
        expect(credentialTraceHasArguments($refused))->toBeTrue();
        credentialHoldsNone(implode("\n", credentialTraceStrings($refused->getTrace())), $value, 'the trace');
        expect(credentialProductionLines($refused->getTraceAsString()))->not->toBe('');
        credentialHoldsNone(credentialProductionLines($refused->getTraceAsString()), $value, 'the trace as a string');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $before);
    }
})->with([
    'the other mode' => [fn (string $v) => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, $v), Fx::value('fx_test_')],
    'no prefix it takes' => [fn (string $v) => Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, $v), Fx::value('zz_test_')],
    'too long' => [fn (string $v) => Fx::writer()->set(Fx::SHARED, null, $v), Fx::value('', 300)],
    'a space' => [fn (string $v) => Fx::writer()->set(Fx::SHARED, null, $v), Fx::value('', 40).' tail'],
    'a mode it does not take' => [fn (string $v) => Fx::writer()->set(Fx::SHARED, CredentialMode::Test, $v), Fx::value('', 40)],
    'in the name\'s place' => [fn (string $v) => Fx::writer()->set($v, null, Fx::value('', 40)), Fx::value('fx_live_')],
    'no app key' => [function (string $v): void {
        config(['app.key' => '']);
        Fx::forget();
        Fx::writer()->set(Fx::SHARED, null, $v);
    }, Fx::value('', 40)],
]);

/**
 * ⚠️ BY REFLECTION AS WELL AS BY TRACE, because a trace shows only the frames above a refusal, and the cipher's and the
 * secret's frames are never among them: a value sealed or wrapped by a frame that then failed would print in full.
 */
it('marks every parameter that carries plaintext, or a key, as sensitive', function (string $class, string $method, string $parameter): void {
    $found = array_values(array_filter((new ReflectionMethod($class, $method))->getParameters(), static fn (ReflectionParameter $p): bool => $p->getName() === $parameter));

    expect($found)->toHaveCount(1)
        ->and($found[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'the value set' => [CredentialWriter::class, 'set', 'value'],
    'the name set, which may be a value' => [CredentialWriter::class, 'set', 'slot'],
    'the name removed' => [CredentialWriter::class, 'remove', 'slot'],
    'the name declared' => [CredentialWriter::class, 'declared', 'slot'],
    'the value checked' => [CredentialSlot::class, 'refusalFor', 'value'],
    'the value matched' => [CredentialSlot::class, 'otherModePrefixOf', 'value'],
    'the value compared' => [CredentialSlot::class, 'beginsWithAny', 'value'],
    'the value sealed' => [CredentialCipher::class, 'seal', 'value'],
    'the app key derived' => [CredentialCipher::class, 'derive', 'configured'],
    'the key tagged' => [CredentialCipher::class, 'keyIdOf', 'key'],
    'the value wrapped' => [Secret::class, '__construct', 'value'],
]);

it('chains nothing and quotes no ciphertext when the database refuses the write', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    // A unique violation, forced: a listener files the writer's new row where the first one already is.
    Credential::creating(static function (Credential $row): void {
        $row->setAttribute('slot', Fx::SHARED);
        $row->setAttribute('mode', 'none');
    });

    try {
        Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));
        $this->fail('the collision was not refused');
    } catch (CredentialRefused $refused) {
        expect($refused->getPrevious())->toBeNull()
            ->and($refused->getMessage())->toBe('Fixture payment key was not saved: it was changed somewhere else at the same moment. Nothing was written; try again.');
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }
});

it('turns a deadlock inside a caller\'s transaction into a refusal that chains nothing', function (): void {
    // The test's own transaction is the caller's, so the writer's is nested: there Laravel turns a deadlock into a
    // `DeadlockException` chaining the query, ciphertext and all.
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "credentials"')) {
            throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
        }
    });

    try {
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
        $this->fail('the deadlock was not refused');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe(CredentialRefusal::Race)
            ->and($refused->getPrevious())->toBeNull();
    }
});

it('sends the database no binding that holds the value', function (): void {
    $value = Fx::value('', 40);
    $bindings = [];
    DB::listen(static function (QueryExecuted $query) use (&$bindings): void {
        $bindings[] = $query->sql.' '.json_encode($query->bindings);
    });

    Fx::writer()->set(Fx::SHARED, null, $value);
    Fx::reader()->secret(Fx::SHARED);

    expect($bindings)->not->toBe([]);

    foreach ($bindings as $sent) {
        credentialHoldsNone($sent, $value, 'a query');
    }
});

it('hands a consumer a secret that no dumper, cast or serialiser can show', function (): void {
    Fx::writer()->set(Fx::SHARED, null, $value = Fx::value('', 40));
    $secret = Fx::reader()->secret(Fx::SHARED);

    ob_start();
    var_dump($secret);
    $dumped = (string) ob_get_clean();

    foreach (['var_dump' => $dumped, 'print_r' => print_r($secret, true), 'var_export' => var_export($secret, true), 'array' => print_r((array) $secret, true), 'json_encode' => (string) json_encode($secret), 'in a log context' => (string) json_encode(['key' => $secret])] as $how => $out) {
        credentialHoldsNone($out, $value, $how);
    }

    expect($secret->reveal())->toBe($value)
        ->and(fn () => serialize($secret))->toThrow(LogicException::class)
        ->and(fn () => clone $secret)->toThrow(LogicException::class)
        ->and(fn () => (string) $secret)->toThrow(Error::class)
        ->and(fn () => unserialize('O:'.strlen(Secret::class).':"'.Secret::class.'":0:{}'))->toThrow(LogicException::class);
});
