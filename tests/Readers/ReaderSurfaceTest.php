<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\PasswordRefusal;
use Kitsune\Core\Auth\PasswordRules;
use Kitsune\Core\Console\ReadersCommand;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Http\Middleware\RequireGuest;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Http\MiddlewarePriority;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\ReaderRoutes;

/*
 * The surface reader accounts add — ADR-037, as built. Two symbols are public, under CONTRIBUTING's third exception:
 * the contract a host's model implements and the line a host's routes call. Everything else is `@internal`, so the
 * v1.2 freeze inherits a list rather than a phrase.
 */

/** @return list<string> every PHP file under these paths of the repository */
function surfaceFiles(string ...$paths): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach ($paths as $path) {
        if (is_file($root.'/'.$path)) {
            $files[] = $root.'/'.$path;

            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/** @return list<class-string> */
function surfaceSymbols(): array
{
    $symbols = [EmailAddress::class, PasswordRules::class, PasswordRefusal::class, ReadersCommand::class, ReaderArea::class, RequireReader::class, RequireGuest::class, MiddlewarePriority::class, ReaderToken::class];

    foreach (surfaceFiles('packages/core/src/Readers', 'packages/core/src/Http/Controllers/Readers') as $file) {
        $relative = substr($file, strlen(dirname(__DIR__, 2).'/packages/core/src/'), -4);
        $symbols[] = 'Kitsune\\Core\\'.str_replace('/', '\\', $relative);
    }

    return $symbols;
}

/** The files the front door is made of: core's reader code and the skeleton's reader. */
function surfaceDoorFiles(): array
{
    return surfaceFiles(
        'packages/core/src/Readers',
        'packages/core/src/Http/Controllers/Readers',
        'packages/core/src/Http/Middleware/ReaderArea.php',
        'packages/core/src/Http/Middleware/RequireReader.php',
        'packages/core/src/Http/Middleware/RequireGuest.php',
        'packages/core/src/Console/ReadersCommand.php',
        'packages/core/src/Auth/EmailAddress.php',
        'packages/core/src/Auth/PasswordRules.php',
        'packages/core/src/Models/ReaderToken.php',
        'skeleton/app/Models/Reader.php',
    );
}

/** A file's code without its comments, which name what the code must never do. */
function surfaceCode(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
    }

    return $code;
}

it('marks every symbol it adds @internal, but the contract and the routes line', function (): void {
    $symbols = surfaceSymbols();

    // Not vacuous: the sweep found the controllers and the sessions.
    expect($symbols)->toContain('Kitsune\\Core\\Http\\Controllers\\Readers\\SignInController', 'Kitsune\\Core\\Readers\\ReaderSessions', ReaderAccount::class, ReaderRoutes::class);

    $public = array_values(array_filter($symbols, static fn (string $symbol): bool => ! str_contains((string) (new ReflectionClass($symbol))->getDocComment(), '@internal')));

    expect($public)->toBe([ReaderAccount::class, ReaderRoutes::class]);
});

it('keeps the contract to five methods, exactly as published — it never grows', function (): void {
    $methods = [];

    foreach ((new ReflectionClass(ReaderAccount::class))->getMethods() as $method) {
        $methods[$method->getName()] = [
            $method->isStatic(),
            array_map(static fn (ReflectionParameter $parameter): string => (string) $parameter->getType().' $'.$parameter->getName(), $method->getParameters()),
            (string) $method->getReturnType(),
        ];
    }

    expect($methods)->toBe([
        'findByEmail' => [true, ['string $email'], '?static'],
        'createReader' => [true, ['string $email', '?string $passwordHash', '?DateTimeInterface $verifiedAt'], 'static'],
        'readerEmail' => [false, [], 'string'],
        'exportAccount' => [false, [], 'array'],
        'eraseAccount' => [false, [], 'void'],
    ]);
});

it('keeps an address, a password, a key and a connection out of a stack trace', function (): void {
    $sensitive = ['email', 'password', 'passwordHash', 'token', 'secret', 'reader', 'key', 'address', 'typed', 'ip', 'subject', 'line', 'hash', 'url', 'link', 'body', 'confirmation'];
    $bare = [];

    foreach (surfaceDoorFiles() as $file) {
        $source = (string) file_get_contents($file);

        // A declared parameter: a type that starts with a letter, a backslash or `?` — never `||` or `&&` — then the name.
        preg_match_all('/(?<attr>#\[\\\\SensitiveParameter\]\s*)?(?:\??[A-Za-z\\\\][\w\\\\|&]*\s+)\$(?<name>\w+)\s*[,)=]/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (in_array($match['name'], $sensitive, true) && $match['attr'] === '') {
                $bare[] = basename($file).': $'.$match['name'];
            }
        }
    }

    expect(array_values(array_unique($bare)))->toBe([]);
});

it('never ends a staff session, asks the default guard or logs anything but one line about the mailer', function (): void {
    $found = [];
    $logs = [];

    foreach (surfaceDoorFiles() as $file) {
        $source = surfaceCode($file);

        foreach (['->invalidate(', 'regenerateToken(', 'Auth::user(', 'auth()->user(', 'Auth::guard()', 'logger(', "'unique", "'exists"] as $needle) {
            if (str_contains($source, $needle)) {
                $found[] = basename($file).': '.$needle;
            }
        }

        $logs[basename($file)] = substr_count($source, 'Log::');
    }

    // ⚠️ ONE LOG LINE, IN `ReaderMail`, and it names only the mailer: a mail that cannot be sent after the response.
    expect($found)->toBe([])
        ->and(array_filter($logs))->toBe(['ReaderMail.php' => 1])
        ->and(surfaceCode(dirname(__DIR__, 2).'/packages/core/src/Readers/ReaderMail.php'))
        ->toContain("Log::warning(sprintf('A reader email could not be made or sent through the mailer [%s]; the address, the link and the error are not logged.', \$mailer));");
});

it('keeps the skeleton\'s reader files to core\'s public reader surface — they are frozen at create-project', function (): void {
    $internal = [];
    $seen = [];

    foreach (['skeleton/app/Models/Reader.php', 'skeleton/app/Providers/AppServiceProvider.php', 'skeleton/routes/web.php'] as $file) {
        // Every core symbol the code names — imported or written out in full — never only the `use` lines.
        preg_match_all('/(Kitsune\\\\Core\\\\[\\w\\\\]+)/', surfaceCode(dirname(__DIR__, 2).'/'.$file), $matches);

        foreach (array_unique($matches[1]) as $symbol) {
            if (! class_exists($symbol) && ! interface_exists($symbol) && ! trait_exists($symbol)) {
                continue;
            }

            $seen[] = $symbol;

            if (str_contains((string) (new ReflectionClass($symbol))->getDocComment(), '@internal')) {
                $internal[] = basename($file).': '.$symbol;
            }
        }
    }

    // Not vacuous: the sweep read the reader's imports.
    expect($seen)->toContain(ReaderRoutes::class, ReaderAccount::class)
        ->and($internal)->toBe([]);
});

it('opens every reader view with the licence, within its first 400 bytes', function (): void {
    $views = glob(dirname(__DIR__, 2).'/packages/core/resources/views/readers/*.blade.php') ?: [];

    expect($views)->toHaveCount(8);

    foreach ($views as $view) {
        $source = (string) file_get_contents($view);

        expect(substr($source, 0, 400))->toContain('Mozilla Public');

        // ⚠️ RAW OUTPUT ONLY IN THE PLAIN-TEXT MAIL, which is never HTML, and nothing escaped there to double up.
        if (basename($view) === 'mail.blade.php') {
            expect($source)->not->toContain('{{ ')->and(substr_count($source, '{!!'))->toBe(1);
        } else {
            expect($source)->not->toContain('{!!');
        }
    }
});
