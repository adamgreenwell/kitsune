<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Auth\ReaderGuardFault;
use Kitsune\Core\Console\EntitlementsCommand;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

/*
 * The surface entitlements add, and what they keep out of a trace — ADR-040, as built. Every symbol is `@internal`, so
 * the v1.2 freeze inherits a list rather than a phrase; the one host-facing thing, `kitsune.readers.guard`, is the
 * exception CONTRIBUTING names.
 */

function entitlementSourceFiles(string $under): array
{
    $root = dirname(__DIR__, 3).'/packages/core/src/'.$under;
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('marks every symbol it adds @internal', function (): void {
    $symbols = [Entitlement::class, ReaderGuard::class, ReaderGuardFault::class, EntitlementsCommand::class];

    foreach (entitlementSourceFiles('Entitlements') as $file) {
        $symbols[] = 'Kitsune\\Core\\Entitlements\\'.basename($file, '.php');
    }

    // Not vacuous: the namespace holds the writer, the check and the export.
    expect($symbols)->toContain(EntitlementWriter::class, 'Kitsune\\Core\\Entitlements\\EntitlementCheck', EntitlementRecords::class);

    $public = array_values(array_filter($symbols, static fn (string $symbol): bool => ! str_contains((string) (new ReflectionClass($symbol))->getDocComment(), '@internal')));

    expect($public)->toBe([], 'these are not marked @internal: '.implode(', ', $public));
});

/**
 * ⚠️ `#[\SensitiveParameter]` IS NOT INHERITED, and PHP's engine default prints every argument into a trace — a log
 * line, an error page — so each parameter that carries a reader's identifier or a source is pinned here, one by one.
 */
it('keeps every reader identifier and every source out of a stack trace', function (string $class, string $method, string $parameter): void {
    $found = null;

    foreach ((new ReflectionMethod($class, $method))->getParameters() as $candidate) {
        if ($candidate->getName() === $parameter) {
            $found = $candidate;
        }
    }

    expect($found)->not->toBeNull("{$class}::{$method}() has no parameter \${$parameter}")
        ->and($found->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'grant: the reader' => [EntitlementWriter::class, 'grant', 'reader'],
    'grant: the source' => [EntitlementWriter::class, 'grant', 'source'],
    'comp: the reader' => [EntitlementWriter::class, 'comp', 'reader'],
    'revoke: the reader' => [EntitlementWriter::class, 'revoke', 'reader'],
    'revoke: the source' => [EntitlementWriter::class, 'revoke', 'source'],
    'forget: the reader' => [EntitlementWriter::class, 'forget', 'reader'],
    'the writer\'s lookup' => [EntitlementWriter::class, 'known', 'reader'],
    'export: the reader' => [EntitlementRecords::class, 'forReader', 'reader'],
    'the guard\'s key' => [ReaderGuard::class, 'key', 'id'],
    'the guard\'s lookup' => [ReaderGuard::class, 'canonical', 'id'],
    'the shared key step' => [EntitlementAuthority::class, 'key', 'reader'],
    'the shared filing step' => [EntitlementAuthority::class, 'filedKey', 'reader'],
    'the decision: the reader' => [EntitlementWriter::class, 'give', 'stored'],
    'the decision: the source' => [EntitlementWriter::class, 'give', 'source'],
    'the row read: the reader' => [EntitlementWriter::class, 'lockedRow', 'stored'],
    'the row read: the source' => [EntitlementWriter::class, 'lockedRow', 'source'],
]);

/**
 * ⚠️ AND AT RUN TIME, under PHP's engine default. This container's `php.ini` hides every argument, which is what hid the
 * leak; a host without one, or with `php.ini-development`, prints them. So the default is switched back on here, and a
 * refusal thrown deep inside each door must print the arguments it was given — as `SensitiveParameterValue`.
 */
it('prints neither the reader nor the source in a refusal\'s trace, under PHP\'s engine default', function (): void {
    Fx::boot();
    $org = Fx::org('trace');
    Fx::site($org, 'main');
    Fx::declareReaders();
    Fx::owner();
    // Both engine defaults: arguments recorded, and strings printed up to 15 bytes, which holds the whole id here.
    $previous = [ini_set('zend.exception_ignore_args', '0'), ini_set('zend.exception_string_param_max_len', '15')];

    try {
        $traces = [];

        foreach ([
            fn () => Fx::writer()->grant('81234', 'course.advanced-php', 'commerce.order:93177', null),
            fn () => Fx::writer()->grant('81234', 'course.advanced-php', 'commerce.order:93177 ', null),
            fn () => Fx::writer()->comp('81234', 'course.advanced-php', null),
            fn () => Fx::writer()->revoke('81234 ', 'course.advanced-php', 'commerce.order:93177'),
            fn () => Fx::writer()->forget('81234 '),
            fn () => EntitlementRecords::forReader('81234 '),
            // Two refused from inside the transaction, where the private helpers are on the stack (review).
            function () use ($org): void {
                DB::table('test_readers')->insert(['id' => 81234, 'org_id' => $org->getKey(), 'email' => 'r@example.test']);
                Entitlement::saving(static fn (): bool => false);

                try {
                    Fx::writer()->grant('81234', 'course.advanced-php', 'commerce.order:93177', null);
                } finally {
                    Entitlement::flushEventListeners();
                    Entitlement::clearBootedModels();
                }
            },
            function (): void {
                DB::table('sites')->where('id', app(Context::class)->siteId())->delete();
                Fx::writer()->revoke('81234', 'course.advanced-php', 'commerce.order:93177');
            },
        ] as $door) {
            try {
                $door();
                $this->fail('the door did not refuse');
            } catch (EntitlementRefused $refused) {
                $traces[] = $refused->getTraceAsString();
            }
        }

        // Not vacuous: an argument no door marks — the entitlement's name — is printed, to the engine's 15 bytes.
        expect(implode("\n", $traces))->toContain("'course.advanced...'");

        foreach ($traces as $trace) {
            expect($trace)->toContain('SensitiveParameterValue')
                ->and($trace)->not->toContain('81234')
                ->and($trace)->not->toContain('93177');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous[0]);
        ini_set('zend.exception_string_param_max_len', (string) $previous[1]);
        Fx::tearDown();
    }
});

it('logs, reports and dispatches nothing', function (): void {
    $core = dirname(__DIR__, 3).'/packages/core/src/';

    foreach ([...entitlementSourceFiles('Entitlements'), $core.'Auth/ReaderGuard.php', $core.'Auth/ReaderGuardFault.php', $core.'Console/EntitlementsCommand.php', $core.'Models/Entitlement.php'] as $file) {
        $source = (string) file_get_contents($file);

        foreach (['Log::', 'logger(', 'report(', 'dump(', 'dd(', 'event(', 'dispatch(', 'Event::'] as $needle) {
            expect(preg_match('/(?<![A-Za-z0-9])'.preg_quote($needle, '/').'/', $source))->toBe(0, basename($file)." calls {$needle}");
        }
    }
});

it('offers the writer\'s doors and nothing more: no duration, no shortening, no reinstating a producer\'s source', function (): void {
    $public = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(EntitlementWriter::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($public);

    expect($public)->toBe(['__construct', 'comp', 'forget', 'grant', 'isErasing', 'isWriting', 'revoke']);
});

it('is reached by nothing in the admin or over HTTP in this half', function (): void {
    foreach ([...entitlementSourceFiles('Filament'), ...entitlementSourceFiles('Http')] as $file) {
        foreach (['EntitlementWriter', 'EntitlementRecords'] as $needle) {
            expect(str_contains((string) file_get_contents($file), $needle))->toBeFalse(basename($file)." reaches {$needle}");
        }
    }
});
