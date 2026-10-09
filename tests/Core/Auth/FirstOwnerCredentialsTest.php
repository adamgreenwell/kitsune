<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Auth\FirstOwnerCredentials;
use Kitsune\Core\Auth\PasswordRules;
use Symfony\Component\Process\Process;

/*
 * The first owner's password and address, checked before anything is written — ADR-026, as amended.
 *
 * ⚠️ EVERY RULE IS ABOUT SIGNING IN LATER, so each case here is one a real owner could type and then never sign in
 * with: a space nobody sees, an arrow key at the prompt, bytes bcrypt never reads, an address the browser refuses.
 */

const OWNER_TOO_SHORT = 'The password is shorter than 15 characters.';
const OWNER_CONTROL = 'The password contains a control character';
const OWNER_NOT_UTF8 = 'The password is not valid UTF-8';
const OWNER_WHITESPACE = 'The password begins or ends with whitespace';
const OWNER_TOO_LONG = 'The password is longer than 72 bytes.';
const OWNER_IS_EMAIL = 'The password is the owner\'s email address.';

it('refuses a password the owner could not sign in with, by its own rule', function (string $password, string $refusal): void {
    expect(FirstOwnerCredentials::passwordRefusal($password, 'owner@example.test'))->toStartWith($refusal);
})->with([
    'nothing' => ['', OWNER_TOO_SHORT],
    'fourteen characters' => [str_repeat('a', 14), OWNER_TOO_SHORT],
    'fourteen characters of two bytes each, counted as characters' => [str_repeat('é', 14), OWNER_TOO_SHORT],
    'a Tab' => ["abc\tdefghijklmnop", OWNER_CONTROL],
    'a NUL, which bcrypt refuses' => ["abc\0defghijklmnop", OWNER_CONTROL],
    'an arrow key at the hidden prompt' => ["abc\x1b[Adefghijklm", OWNER_CONTROL],
    'a DEL' => ["abc\x7fdefghijklmnop", OWNER_CONTROL],
    'not UTF-8' => [str_repeat("\xff", 16), OWNER_NOT_UTF8],
    'a leading space' => [' leading-sixteen!', OWNER_WHITESPACE],
    'a trailing space' => ['trailing-sixteen ', OWNER_WHITESPACE],
    'a leading no-break space' => ["\u{00A0}nbsp-leading-x", OWNER_WHITESPACE],
    'a byte-order mark a file was saved with' => ["\u{FEFF}correct-horse-battery", OWNER_WHITESPACE],
    'a trailing zero-width space' => ["correct-horse-battery\u{200B}", OWNER_WHITESPACE],
    'seventy-three bytes' => [str_repeat('a', 73), OWNER_TOO_LONG],
    'thirty-seven characters of two bytes each, counted as bytes' => [str_repeat('é', 37), OWNER_TOO_LONG],
]);

it('refuses the owner\'s own address as the password, in any case', function (string $password): void {
    expect(FirstOwnerCredentials::passwordRefusal($password, 'Owner@Example.test'))->toStartWith(OWNER_IS_EMAIL);
})->with(['owner@example.test', 'OWNER@EXAMPLE.TEST', 'oWnEr@eXaMpLe.TeSt']);

it('accepts a password at each boundary', function (string $password): void {
    expect(FirstOwnerCredentials::passwordRefusal($password, 'owner@example.test'))->toBeNull();
})->with([
    'fifteen characters' => [str_repeat('a', 15)],
    'fifteen characters of two bytes each' => [str_repeat('é', 15)],
    'seventy-two bytes' => [str_repeat('a', 72)],
    'thirty-six characters of two bytes each' => [str_repeat('é', 36)],
    'a space inside' => ['surf the left break'],
    'a zero-width joiner inside an emoji somebody typed' => ["surf-\u{1F469}\u{200D}\u{1F4BB}-left-break"],
]);

/** ⚠️ The sign-in form's field is `type="email"`, so the browser's grammar decides as surely as the server's does. */
it('asks the first owner for the minimum configured, and never for less than the floor', function (): void {
    config(['kitsune.passwords.min_characters' => 20]);

    expect(FirstOwnerCredentials::passwordRefusal(str_repeat('a', 19), 'owner@example.test'))->toStartWith('The password is shorter than 20 characters.')
        ->and(FirstOwnerCredentials::passwordRefusal(str_repeat('a', 20), 'owner@example.test'))->toBeNull()
        ->and(sprintf(FirstOwnerCredentials::PROMPT, 'owner@example.test', PasswordRules::minCharacters()))
        ->toBe('Choose a password for owner@example.test, the first owner (hidden; at least 20 characters)');

    config(['kitsune.passwords.min_characters' => 8]);

    expect(FirstOwnerCredentials::passwordRefusal(str_repeat('a', 14), 'owner@example.test'))->toStartWith(OWNER_TOO_SHORT);
});

it('refuses an address the sign-in form would not accept', function (string $email): void {
    expect(FirstOwnerCredentials::emailRefusal($email))->toStartWith('Refusing `--owner`: that is not an email address the sign-in form accepts');
})->with([
    'no at sign' => ['no-at-sign'],
    'a quoted local part, which only the server accepts' => ['"a b"@x.test'],
    'a non-ASCII local part, which only the server accepts' => ['ü@x.test'],
    'a non-ASCII domain' => ['a@bücher.de'],
    'two dots' => ['a..b@x.test'],
    'a leading dot' => ['.a@x.test'],
    'a trailing newline' => ["a@x.test\n"],
    'longer than 255 characters, which only the server refuses' => [str_repeat('l', 10).'@'.implode('.', array_fill(0, 4, str_repeat('d', 62)))],
]);

it('accepts an address both grammars accept, stored as typed', function (string $email): void {
    expect(FirstOwnerCredentials::emailRefusal($email))->toBeNull();
})->with(['adam@localhost', 'Owner@X.test', 'a@xn--bcher-kva.de']);

it('never repeats the value it refused', function (): void {
    expect(FirstOwnerCredentials::emailRefusal('hunter2-not-an-address'))->not->toContain('hunter2');
});

it('takes one line ending off, and only one', function (string $line, string $password): void {
    expect(FirstOwnerCredentials::withoutLineEnd($line))->toBe($password);
})->with([
    ["pw\n", 'pw'],
    ["pw\r\n", 'pw'],
    ["pw\n\n", "pw\n"],
    ['pw', 'pw'],
    ["pw\r", "pw\r"],
]);

/** @return resource */
function ownerStream(string $contents)
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

it('reads the first line of a stream, and nothing after it', function (): void {
    expect(FirstOwnerCredentials::fromStream(ownerStream("correct-horse-battery\n"), 'owner@example.test'))->toBe('correct-horse-battery')
        ->and(FirstOwnerCredentials::fromStream(ownerStream("correct-horse-battery\r\nsecond\n"), 'owner@example.test'))->toBe('correct-horse-battery');
});

it('refuses a stream with no password on its first line', function (string $contents): void {
    expect(fn () => FirstOwnerCredentials::fromStream(ownerStream($contents), 'owner@example.test'))
        ->toThrow(RuntimeException::class, 'standard input had no password on its first line');
})->with(['nothing' => [''], 'an empty line' => ["\n"]]);

/** ⚠️ The read itself is bounded, not only its verdict: an unbounded `fgets()` on `/dev/zero` reads until memory runs out. */
it('reads a bounded line, and refuses what it read', function (): void {
    $stream = ownerStream(str_repeat('a', 5000)."\n");

    expect(fn () => FirstOwnerCredentials::fromStream($stream, 'owner@example.test'))
        ->toThrow(RuntimeException::class, OWNER_TOO_LONG);

    expect(ftell($stream))->toBe(FirstOwnerCredentials::READ_BOUND);
});

it('refuses a byte-order mark at the start of a piped password', function (): void {
    expect(fn () => FirstOwnerCredentials::fromStream(ownerStream("\xEF\xBB\xBFcorrect-horse-battery\n"), 'owner@example.test'))
        ->toThrow(RuntimeException::class, OWNER_WHITESPACE);
});

it('prompts on a terminal, or on a stream it was handed, and on nothing else', function (): void {
    FirstOwnerCredentials::refuseUnlessPromptable(ownerStream("correct-horse-battery\n"));

    expect(true)->toBeTrue();
});

/**
 * ⚠️ THE PROCESS'S OWN STANDARD INPUT, AS A PIPE: what `ssh host 'php artisan …'` with no `-t` gives the command. Symfony
 * would read a hidden answer from it plainly, so it is refused before anything is asked.
 */
it('refuses to prompt on its own standard input when that is a pipe', function (): void {
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
    $process = new Process([PHP_BINARY, '-r', "require '{$autoload}'; try { "
        .'Kitsune\Core\Auth\FirstOwnerCredentials::refuseUnlessPromptable(STDIN); echo "ASKED"; } '
        .'catch (Throwable $e) { echo $e->getMessage(); }']);
    $process->setInput("correct-horse-battery\ncorrect-horse-battery\n");
    $process->setTimeout(30);
    $process->run();

    expect($process->getOutput())->toContain('Refusing to ask for the password: standard input is not a terminal')
        ->not->toContain('ASKED');
});

it('asks a question that is hidden, never shown instead, and kept as typed', function (): void {
    $question = FirstOwnerCredentials::question('x');

    expect($question->isHidden())->toBeTrue()
        ->and($question->isHiddenFallback())->toBeFalse()
        ->and($question->isTrimmable())->toBeFalse();
});

/*
 * ⚠️ THE TWO TERMINAL BRANCHES, IN A REAL TERMINAL. A pseudo-terminal echoes what is written to it before anything
 * reads it, so these assert the refusal's words, never the absence of echo.
 */

/** Run PHP in a child process attached to a pseudo-terminal, and return what it printed. */
function ownerUnderPty(string $code, array $env = []): string
{
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
    $process = new Process([PHP_BINARY, '-r', "require '{$autoload}'; {$code}"], null, $env + ['PATH' => getenv('PATH')]);
    $process->setPty(true);
    $process->setTimeout(30);
    $process->run();

    return $process->getOutput();
}

it('refuses a password read from standard input when it is a terminal, before reading', function (): void {
    $out = ownerUnderPty(
        'try { Kitsune\Core\Auth\FirstOwnerCredentials::fromStream(STDIN, "x@y.test"); echo "READ"; } '
        .'catch (Throwable $e) { echo $e->getMessage(); }'
    );

    expect($out)->toContain('Refusing `--owner-password-stdin`: standard input is a terminal')
        ->not->toContain('READ');
})->skip(! Process::isPtySupported(), 'no pseudo-terminal on this host');

it('prompts on its own standard input when that is a terminal', function (): void {
    $out = ownerUnderPty(
        'try { Kitsune\Core\Auth\FirstOwnerCredentials::refuseUnlessPromptable(STDIN); echo "ASKED"; } '
        .'catch (Throwable $e) { echo $e->getMessage(); }'
    );

    expect($out)->toContain('ASKED');
})->skip(! Process::isPtySupported(), 'no pseudo-terminal on this host');

it('refuses to ask where the terminal cannot hide what is typed', function (): void {
    $out = ownerUnderPty(
        'try { Kitsune\Core\Auth\FirstOwnerCredentials::ask(new Symfony\Component\Console\Style\SymfonyStyle('
        .'new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\StreamOutput(STDERR)), "x@y.test"); echo "ASKED"; } '
        .'catch (Throwable $e) { echo $e->getMessage(); }',
        /* No `stty` on the path, so the input cannot be hidden. */
        ['PATH' => '/nonexistent'],
    );

    expect($out)->toContain('Refusing to ask for the password: this terminal cannot hide what is typed')
        ->not->toContain('ASKED');
})->skip(! Process::isPtySupported(), 'no pseudo-terminal on this host');
