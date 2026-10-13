<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);
use Dotenv\Parser\Parser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

$mode = $argv[1] ?? '';
$root = (string) getcwd();
$refusals = [];

function release_refuse(string $reason): void
{
    $GLOBALS['refusals'][] = $reason;
}

function release_finish(): never
{
    foreach ($GLOBALS['refusals'] as $reason) {
        fwrite(STDERR, "Refusing to release: {$reason}\n");
    }

    exit($GLOBALS['refusals'] === [] ? 0 : 1);
}

if (! in_array($mode, ['packages', 'syntax', 'environment', 'serving'], true)) {
    release_refuse("there is no check named [{$mode}]");
    release_finish();
}

if ($mode === 'packages') {
    // No Laravel boot, no .env read, and no installed package code before its origin is checked.
    try {
        $manifest = json_decode((string) file_get_contents($root.'/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $inventory = json_decode((string) file_get_contents($root.'/skeleton/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        release_refuse('the Composer package inventory or skeleton manifest could not be read');
        release_finish();
    }

    if (! is_array($manifest) || ! is_array($inventory) || ! is_array($inventory['packages'] ?? null)) {
        release_refuse('the Composer package inventory or skeleton manifest is malformed');
        release_finish();
    }

    $installed = [];

    foreach ($inventory['packages'] as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $installed[$package['name']] = $package;
        }
    }

    foreach (array_keys($manifest['require'] ?? []) as $name) {
        if (str_starts_with($name, 'kitsune/') && ! isset($installed[$name])) {
            release_refuse("{$name} is required but was not installed");
        }
    }

    $commerce = $installed['kitsune/commerce'] ?? null;

    if (($argv[2] ?? '0') === '1') {
        if (! is_array($commerce)) {
            release_refuse('kitsune/commerce was requested but was not installed');
        } elseif (! is_string($commerce['version'] ?? null) || $commerce['version'] === ''
            || ($installed['kitsune/core']['version'] ?? null) !== $commerce['version']) {
            release_refuse('kitsune/core and kitsune/commerce do not have the same Composer version');
        }
    } elseif ($commerce !== null) {
        release_refuse('kitsune/commerce was installed without KITSUNE_INSTALL_COMMERCE=1');
    }

    foreach ($installed as $name => $package) {
        if (! str_starts_with($name, 'kitsune/')) {
            continue;
        }

        if (preg_match('~^kitsune/[a-z0-9-]+$~D', $name) !== 1) {
            release_refuse('a first-party package has an invalid name');

            continue;
        }

        $short = substr($name, strlen('kitsune/'));
        $source = $root.'/packages/'.$short;
        $copy = $root.'/skeleton/vendor/'.$name;

        if (($package['dist']['type'] ?? null) !== 'path' || ($package['dist']['url'] ?? null) !== '../packages/'.$short
            || ! is_dir($source) || ! is_dir($copy) || is_link($copy)) {
            release_refuse("{$name} was not copied from this checkout's packages/{$short}");

            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source));
            $target = $copy.$relative;

            // Composer's path mirror deliberately excludes this archive-control metadata; it is not runtime code.
            if ($relative === '/.gitattributes') {
                continue;
            }

            $expected = hash_file('sha256', $file->getPathname());
            $actual = is_file($target) ? hash_file('sha256', $target) : false;

            if (! is_string($expected) || ! is_string($actual) || $expected !== $actual) {
                release_refuse("{$name} does not contain this checkout's package files");
                break;
            }
        }

        // A nested link or an extra file is not a copy either, even if every expected leaf happens to match.
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = substr($file->getPathname(), strlen($copy));

            if ($file->isLink() || ($file->isFile() && ! is_file($source.$relative))) {
                release_refuse("{$name} contains a link or a file outside this checkout's package");
                break;
            }
        }
    }

    release_finish();
}

if (! is_file($root.'/skeleton/vendor/autoload.php')) {
    release_refuse('skeleton/vendor/autoload.php is missing, so nothing was installed');
    release_finish();
}

require $root.'/skeleton/vendor/autoload.php';

if ($mode === 'syntax') {
    // skeleton/.env is the link Laravel loads, so it is what gets parsed.
    $contents = is_readable($root.'/skeleton/.env') ? file_get_contents($root.'/skeleton/.env') : false;

    if ($contents === false) {
        release_refuse('skeleton/.env cannot be read');
        release_finish();
    }

    $parser = new Parser;

    try {
        $parser->parse($contents);
    } catch (Throwable) {
        // ⚠️ THE PARSER'S MESSAGE QUOTES THE VALUE IT CHOKED ON, so it is never printed. The line is found instead:
        // the entry that breaks parsing starts on the line after the longest prefix of lines that still parses, and a
        // quoted value spanning several lines counts as one entry, because a prefix ending inside it does not parse.
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $parsed = 0;

        foreach (array_keys($lines) as $index) {
            try {
                $parser->parse(implode("\n", array_slice($lines, 0, $index + 1)));
                $parsed = $index + 1;
            } catch (Throwable) {
                // Not this prefix.
            }
        }

        release_refuse('the shared .env is not valid dotenv syntax at the entry starting on line '.($parsed + 1)
            .'. The parser\'s own message is withheld, because it quotes the value');
    }

    if ($GLOBALS['refusals'] === []) {
        echo "The shared .env parses.\n";
    }

    release_finish();
}

try {
    $app = require $root.'/skeleton/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    release_refuse(sprintf(
        'the application did not boot: %s at %s:%d. Its message is withheld, because it can quote configuration; '
        .'run artisan in %s to read it',
        $e::class,
        $e->getFile(),
        $e->getLine(),
        $root.'/skeleton',
    ));
    release_finish();
}

// What Laravel loaded, not what .env says: duplicate keys, `export` prefixes and quotes resolve as the framework
// resolves them, and a variable in the deploy process's environment wins over .env, as it will when this serves.
$config = $app->make('config');

if ($config->get('app.env') !== 'production') {
    release_refuse('APP_ENV must be production, and the app loads '.json_encode($config->get('app.env')));
}

if ($config->get('app.debug') !== false) {
    release_refuse('APP_DEBUG must be false: debug pages show the environment to anyone who triggers an error');
}

if (! is_string($config->get('app.url')) || ! str_starts_with($config->get('app.url'), 'https://')) {
    release_refuse('APP_URL must start with https://, and the app loads '.json_encode($config->get('app.url')));
}

if ($config->get('session.secure') !== true) {
    release_refuse('SESSION_SECURE_COOKIE must be true, so the session cookie is never sent over plain HTTP');
}

if ($config->get('database.default') !== 'pgsql') {
    release_refuse('DB_CONNECTION must be pgsql (an absent one falls back to SQLite), and the app loads '
        .json_encode($config->get('database.default')));
}

// ⚠️ STRICTER THAN laravel_cloud(), which fires only on '1': any LARAVEL_CLOUD is refused, because nothing on these
// servers has a reason to set it. On '1' Laravel runs its Cloud bootstrappers and changes how scheduled commands handle
// output, and it would trust every proxy if the skeleton's explicit trustProxies() list did not replace that (ADR-034).
if (array_key_exists('LARAVEL_CLOUD', $_ENV) || array_key_exists('LARAVEL_CLOUD', $_SERVER) || getenv('LARAVEL_CLOUD') !== false) {
    release_refuse('LARAVEL_CLOUD is set, and it must not be, in any form: nothing on these servers sets it');
}

try {
    $app->make('encrypter');
} catch (Throwable $e) {
    release_refuse('APP_KEY does not make a working encrypter ('.$e::class.')');
}

if ($mode === 'serving') {
    // Once config is cached, .env is not read again, so the checks above judged the cached config.
    if (! $app->configurationIsCached()) {
        release_refuse('the configuration was not cached ('.$app->getCachedConfigPath().' is missing)');
    }

    if (! $app->routesAreCached()) {
        release_refuse('the route table was not cached ('.$app->getCachedRoutesPath().' is missing)');
    }

    if (! $app->eventsAreCached()) {
        release_refuse('the event map was not cached ('.$app->getCachedEventsPath().' is missing)');
    }

    // ⚠️ THE CACHE STEPS ARE A LIST, AND A VENDOR CAN GROW IT. `optimize` runs every task a provider registers; this
    // script runs them by name instead, so a task it has never heard of is refused rather than silently skipped.
    $known = [
        'filament' => 'filament:optimize', // skipped on purpose: docs/architecture.md, Filament item 7
        'blade-icons' => 'icons:cache',    // run by name
    ];

    foreach (ServiceProvider::$optimizeCommands as $key => $command) {
        if (($known[$key] ?? null) !== $command) {
            release_refuse("a provider registers the optimize task [{$key}] => [{$command}], which deploy/release.sh neither "
                .'runs nor deliberately skips. Decide which it should be, then add it to the cache steps or the known list');
        }
    }

    if ($GLOBALS['refusals'] === []) {
        try {
            $status = $kernel->handle(Request::create('/up'))->getStatusCode();
        } catch (Throwable $e) {
            $status = $e::class;
        }

        if ($status !== 200) {
            release_refuse("/up answered {$status} instead of 200, so this release does not serve");
        }
    }
}

if ($GLOBALS['refusals'] === []) {
    echo "The {$mode} check passed.\n";
}

release_finish();
