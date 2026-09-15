<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The release body both servers run, deploy/release.sh, run for real against a throwaway checkout and site root — #111.
 *
 * ⚠️ RUN, NOT READ, for the reason SplitPackageScriptTest gives. A deploy script is prose until something executes
 * it, and the drafts this one replaced cited commands, flags and quoting that did not survive being run: a refusal
 * written as `'Composer\'s'` is not even valid bash.
 *
 * PHP and Composer are stubs that log every call, so the order of the steps is asserted exactly. The checks the script
 * runs through the application, and the config, event, route and view caches, run on real PHP, against a minimal
 * fixture app that uses this repository's vendor. That proves the check mechanisms and the step order on a minimal
 * app. It does not boot Filament or Kitsune: the real provider set is guarded at deploy time, by the serving check.
 *
 * Needs only bash, git and PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-release-'.bin2hex(random_bytes(6));
    $this->release = releasePath($this->dir);

    File::makeDirectory($this->release, 0755, true);
    File::makeDirectory($this->dir.'/bin');
    File::makeDirectory($this->dir.'/stubbin');

    releaseEnvFile($this->dir, releaseEnvWith());

    // The real files the script checks for or runs, and the committed storage placeholders it replaces with a link.
    $copied = [
        'skeleton/artisan',
        'skeleton/composer.json',
        'skeleton/.env.example',
        'skeleton/.gitignore',
        'skeleton/bootstrap/cache/.gitignore',
        'packages/core/composer.json',
        ...explode("\n", releaseGit($repo, 'ls-files', 'skeleton/storage')),
    ];

    foreach ($copied as $path) {
        File::ensureDirectoryExists(dirname($this->release.'/'.$path));
        File::copy($repo.'/'.$path, $this->release.'/'.$path);
    }

    $fixtures = [
        'skeleton/bootstrap/app.php' => releaseFixtureApp(),
        'skeleton/fixture/ReleaseFixtureServiceProvider.php' => releaseFixtureProvider(),
        'skeleton/resources/views/fixture.blade.php' => "<p>{{ 'a release fixture' }}</p>\n",
        'skeleton/public/.gitkeep' => '',
    ];

    foreach ($fixtures as $path => $contents) {
        File::ensureDirectoryExists(dirname($this->release.'/'.$path));
        File::put($this->release.'/'.$path, $contents);
    }

    releaseGit($this->release, 'init', '--quiet', '--initial-branch=main');
    releaseGit($this->release, 'add', '--all');
    releaseGit($this->release, 'commit', '--quiet', '-m', 'A release fixture');

    File::put($this->dir.'/bin/php', releasePhpStub());
    File::put($this->dir.'/bin/composer', '');
    File::put($this->dir.'/stubbin/id', releaseIdStub());
    chmod($this->dir.'/bin/php', 0755);
    chmod($this->dir.'/stubbin/id', 0755);

    // What the Composer stub installs as skeleton/vendor/autoload.php: this repository's autoloader.
    File::put($this->dir.'/autoload.php', "<?php\n\nreturn require ".var_export($repo.'/vendor/autoload.php', true).";\n");
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** The release directory the script runs in, under the test's site root. */
function releasePath(string $dir): string
{
    return $dir.'/site/releases/20260915120000';
}

/** Run git in a directory with a fixed identity, and return its trimmed output. */
function releaseGit(string $cwd, string ...$args): string
{
    $process = new Process(
        ['git', '-c', 'user.name=Release Test', '-c', 'user.email=release@kitsune.test', '-c', 'commit.gpgsign=false', ...$args],
        $cwd,
    );
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * The shared .env the happy path deploys with.
 *
 * @return array<string, string>
 */
function releaseEnvEntries(): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://stage.kitsune.test',
        'SESSION_SECURE_COOKIE' => 'true',
        'DB_CONNECTION' => 'pgsql',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
    ];
}

/**
 * The happy path's .env with some entries replaced, a null removing one, and raw lines appended after them.
 *
 * @param  array<string, string|null>  $changes
 * @param  list<string>  $appended
 */
function releaseEnvWith(array $changes = [], array $appended = []): string
{
    $lines = [];

    foreach (array_merge(releaseEnvEntries(), $changes) as $key => $value) {
        if ($value !== null) {
            $lines[] = "{$key}={$value}";
        }
    }

    return implode("\n", [...$lines, ...$appended])."\n";
}

/** Rewrite the shared .env. */
function releaseEnvFile(string $dir, string $contents): void
{
    File::put($dir.'/site/.env', $contents);
}

/**
 * Run the release body the way both servers do: from the root of the release, with every input in the environment.
 *
 * ⚠️ THE ENVIRONMENT STARTS EMPTY. phpunit.xml sets APP_ENV=testing and DB_CONNECTION=testing for this process, and a
 * deploy-shell variable wins over .env, so inheriting them would fail every check for a reason no server has. Symfony
 * Process drops a variable whose value is false.
 *
 * @param  array<string, string|false>  $env
 */
function releaseRun(string $dir, array $env = [], ?string $cwd = null): Process
{
    $release = releasePath($dir);

    $process = new Process(
        ['bash', dirname(__DIR__, 3).'/deploy/release.sh'],
        $cwd ?? $release,
        array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), [
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => $dir.'/stubbin:'.getenv('PATH'),
            'DEPLOY_SHA' => releaseGit($release, 'rev-parse', 'HEAD'),
            'SITE_ROOT' => $dir.'/site',
            'PHP_BIN' => $dir.'/bin/php',
            'COMPOSER_BIN' => $dir.'/bin/composer',
            'STUB_LOG' => $dir.'/calls.log',
            'STUB_COMPOSER' => $dir.'/bin/composer',
            'STUB_REAL_PHP' => PHP_BINARY,
            'STUB_AUTOLOAD' => $dir.'/autoload.php',
        ], $env),
    );

    $process->setTimeout(120);
    $process->run();

    return $process;
}

/**
 * Every call the stubs logged, in order.
 *
 * @return list<string>
 */
function releaseLog(string $dir): array
{
    $log = $dir.'/calls.log';

    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
}

/**
 * The logged calls that start with a prefix.
 *
 * @return list<string>
 */
function releaseCalls(string $dir, string $prefix): array
{
    return array_values(array_filter(releaseLog($dir), fn (string $call): bool => str_starts_with($call, $prefix)));
}

/** Whether this suite itself runs as root, where ownership refusals cannot be provoked. */
function releaseRunsAsRoot(): bool
{
    return trim((new Process(['id', '-u']))->mustRun()->getOutput()) === '0';
}

/**
 * A skeleton bootstrap that boots the framework with one fixture provider and the health route.
 *
 * withExceptions() binds the exception handler, as the skeleton's bootstrap does: without it, the health route's
 * report() cannot resolve one, and a failing /up throws instead of answering 500.
 */
function releaseFixtureApp(): string
{
    return <<<'PHP'
    <?php

    use Illuminate\Foundation\Application;

    require_once __DIR__.'/../fixture/ReleaseFixtureServiceProvider.php';

    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(health: '/up')
        ->withExceptions()
        ->withProviders([ReleaseFixtureServiceProvider::class])
        ->create();

    PHP;
}

/** A provider that registers an unknown optimize task, or breaks /up, when the environment asks it to. */
function releaseFixtureProvider(): string
{
    return <<<'PHP'
    <?php

    use Illuminate\Foundation\Events\DiagnosingHealth;
    use Illuminate\Support\Facades\Event;
    use Illuminate\Support\ServiceProvider;

    final class ReleaseFixtureServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            if (getenv('FIXTURE_BOOT_FAILS') === '1') {
                throw new RuntimeException('SECRET-BOOT-MESSAGE');
            }

            if (getenv('FIXTURE_OPTIMIZES') === '1') {
                $this->optimizes('fixture:optimize', key: 'fixture');
            }

            if (getenv('FIXTURE_HEALTH_FAILS') === '1') {
                Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('down'));
            }
        }
    }

    PHP;
}

/** A stand-in for PHP: it logs every call, and runs the real PHP only for the application checks and four caches. */
function releasePhpStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    set -euo pipefail

    log() {
      printf '%s\n' "$*" >> "$STUB_LOG"
    }

    case "$1" in
      -r)
        if [[ "$2" == *extension_loaded* ]]; then
          log 'php extensions'
          [[ "${STUB_FAIL:-}" != extensions ]] || exit 1
        else
          log 'php version'
        fi
        ;;
      --)
        log "php check $2"
        exec "$STUB_REAL_PHP" "$@"
        ;;
      "$STUB_COMPOSER")
        shift
        log "composer $*"
        [[ "${STUB_FAIL:-}" != "$1" ]] || exit 1

        if [[ "$1" == install ]]; then
          mkdir -p skeleton/vendor/kitsune
          [[ "${STUB_NO_AUTOLOAD:-}" == 1 ]] || cp "$STUB_AUTOLOAD" skeleton/vendor/autoload.php

          if [[ "${STUB_CORE_SYMLINK:-}" == 1 ]]; then
            ln -s ../../../packages/core skeleton/vendor/kitsune/core
          else
            mkdir skeleton/vendor/kitsune/core
            cp packages/core/composer.json skeleton/vendor/kitsune/core/composer.json
          fi

          : > skeleton/composer.lock
        fi
        ;;
      skeleton/artisan)
        log "artisan ${*:2}"
        [[ "${STUB_FAIL:-}" != "$2" ]] || exit 1

        case "$2" in
          storage:link)
            [[ "${STUB_NO_LINK:-}" == 1 ]] || ln -s "$PWD/skeleton/storage/app/public" skeleton/public/storage
            ;;
          config:cache | event:cache | route:cache | view:cache)
            [[ "${STUB_CACHE:-}" == noop ]] || exec "$STUB_REAL_PHP" "$@"
            ;;
        esac
        ;;
      *)
        log "php unexpected $*"
        exit 1
        ;;
    esac

    BASH;
}

/** A stand-in for id: `id -u` answers STUB_UID, so root is refused without root, and the suite still runs as root. */
function releaseIdStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    if [[ "${1:-}" == -u ]]; then
      echo "${STUB_UID:-1000}"
    else
      exec /usr/bin/id "$@"
    fi

    BASH;
}

it('is valid bash', function (): void {
    /*
     * ⚠️ THE FIRST DRAFT WAS NOT. A backslash is literal inside single quotes, so `'Composer\'s'` left the quote open,
     * and every line after it was one long string. Nothing had run it.
     */
    $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/deploy/release.sh']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getErrorOutput())->toBe('');
});

it('runs every step in order, and nothing else', function (): void {
    /*
     * ⚠️ THE EXACT LIST IS THE ASSERTION. It is what proves `optimize`, `filament:optimize`, --seed, db:seed,
     * migrate:fresh and key:generate never reach artisan, and that migrate comes only after the serving check.
     */
    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(trim($run->getOutput()))->toEndWith('is ready to activate.')
        ->and(releaseLog($this->dir))->toBe([
            'php version',
            'composer --version --no-interaction',
            'php extensions',
            'composer config -d skeleton repositories.kitsune-core {"type":"path","url":"../packages/core","options":{"symlink":false}}',
            'composer install -d skeleton --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts',
            'composer audit -d skeleton --no-dev --abandoned=report',
            'php check syntax',
            'artisan package:discover --no-interaction',
            'php check environment',
            'artisan filament:assets --no-interaction',
            'artisan storage:link --no-interaction',
            'artisan config:cache --no-interaction',
            'artisan event:cache --no-interaction',
            'artisan route:cache --no-interaction',
            'artisan view:cache --no-interaction',
            'artisan icons:cache --no-interaction',
            'php check serving',
            'artisan migrate --force --no-interaction',
            'artisan kitsune:audit-patterns --strict --no-interaction',
            'artisan kitsune:schema-sync --no-interaction',
        ]);
});

it('links the shared state, and builds the caches inside the release', function (): void {
    $site = $this->dir.'/site';

    // A stand-in for the live release's compiled view, which no step of a new release may write beside or delete.
    File::ensureDirectoryExists($site.'/storage/framework/views');
    File::put($site.'/storage/framework/views/live.php', '<?php');

    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(readlink($this->release.'/skeleton/.env'))->toBe($site.'/.env')
        ->and(readlink($this->release.'/skeleton/storage'))->toBe($site.'/storage');

    foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        expect(is_dir($site.'/storage/'.$path))->toBeTrue("storage/{$path} was not created");
    }

    foreach (['config.php', 'routes-v7.php', 'events.php'] as $cache) {
        expect(is_file($this->release.'/skeleton/bootstrap/cache/'.$cache))->toBeTrue("{$cache} was not built in the release");
    }

    /*
     * ⚠️ COMPILED VIEWS STAY IN THE RELEASE. view:cache empties its compiled-view directory first, and the shared one
     * holds the live release's views. The fixture view is one only view:cache compiles, since /up renders another, so its
     * compiled file proves view:cache ran. The name follows Compiler::getCompiledPath with view.relative_hash off.
     */
    $views = $this->release.'/skeleton/bootstrap/cache/views';
    $compiled = hash('xxh128', 'v2'.$this->release.'/skeleton/resources/views/fixture.blade.php').'.php';
    $cached = require $this->release.'/skeleton/bootstrap/cache/config.php';

    expect($cached['view']['compiled'])->toBe($views)
        ->and(is_file($views.'/'.$compiled))->toBeTrue('view:cache did not compile the fixture view into the release')
        ->and(array_map('basename', glob($site.'/storage/framework/views/*') ?: []))->toBe(['live.php']);
});

it('refuses LARAVEL_CLOUD in the deploy environment, in any form, before anything runs', function (string $value): void {
    /*
     * ⚠️ STRICTER THAN laravel_cloud(), which fires only on '1'. Nothing on these servers has a reason to set the
     * variable at all, and on '1' Laravel switches to its Cloud behaviour, so its presence is the thing refused.
     */
    $run = releaseRun($this->dir, ['LARAVEL_CLOUD' => $value]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and($run->getErrorOutput())->toContain('LARAVEL_CLOUD')
        ->and(releaseLog($this->dir))->toBe([])
        ->and(is_link($this->release.'/skeleton/storage'))->toBeFalse();
})->with(['empty' => '', 'zero' => '0', 'one' => '1']);

it('refuses a release that is not DEPLOY_SHA, before any Composer call', function (string $case): void {
    /*
     * ⚠️ THE PIN LIVES HERE, NOT IN FORGE'S SCRIPT TEXT. Forge's "Deploy Now" and push to deploy carry no sha, and
     * forge_deploy_commit is only a label, so without this check alpha could deploy a commit stage never rehearsed.
     */
    $head = releaseGit($this->release, 'rev-parse', 'HEAD');

    $sha = match ($case) {
        'missing' => false,
        'a branch name' => 'main',
        '39 characters' => substr($head, 0, 39),
        'uppercase' => strtoupper($head),
        'a hash that is not HEAD' => str_repeat('a', 40),
        'HEAD, with a committed file modified' => $head,
    };

    if ($case === 'HEAD, with a committed file modified') {
        File::append($this->release.'/skeleton/composer.json', "\n");
    }

    $run = releaseRun($this->dir, ['DEPLOY_SHA' => $sha]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and(releaseLog($this->dir))->toBe([])
        ->and(is_link($this->release.'/skeleton/storage'))->toBeFalse();
})->with(['missing', 'a branch name', '39 characters', 'uppercase', 'a hash that is not HEAD', 'HEAD, with a committed file modified']);

it('refuses to run as root', function (): void {
    /*
     * ⚠️ A ROOT RUN WOULD LEAVE ROOT-OWNED FILES — logs and the directories step 4 creates in the shared storage, and
     * compiled views in the release — which PHP-FPM, running as forge, then cannot write.
     */
    $run = releaseRun($this->dir, ['STUB_UID' => '0']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('never run as root')
        ->and(releaseLog($this->dir))->toBe([]);
});

it('refuses a shared .env that belongs to another user', function (): void {
    if (releaseRunsAsRoot()) {
        $this->markTestSkipped('Running as root, every file is this user\'s to use.');
    }

    File::delete($this->dir.'/site/.env');
    symlink('/etc/hosts', $this->dir.'/site/.env');

    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the shared .env')
        ->and(releaseLog($this->dir))->toBe([]);
});

it('refuses an .env that is not valid dotenv syntax, without printing the value', function (array $before, int $line): void {
    /*
     * ⚠️ THE PARSER'S MESSAGE QUOTES THE VALUE: "Encountered unexpected whitespace at [hunter2 SECRETVALUE]". Laravel
     * writes it to stderr, and Composer's post-autoload-dump would have booted Laravel inside the deployment log. So
     * Composer runs without scripts, the .env is parsed before anything boots, and only the line is reported — which
     * has to be the line the entry starts on, even after a quoted value that spans several.
     */
    releaseEnvFile($this->dir, releaseEnvWith([], [...$before, 'DB_PASSWORD=hunter2 SECRETVALUE']));

    $run = releaseRun($this->dir);
    $everything = $run->getOutput().$run->getErrorOutput();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain("line {$line}")
        ->and($everything)->not->toContain('hunter2')
        ->and($everything)->not->toContain('SECRETVALUE')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check syntax'])
        ->and(releaseCalls($this->dir, 'artisan'))->toBe([]);
})->with([
    'on its own line' => [[], 7],
    'after a quoted value spanning two lines' => [['NOTE="multi', 'line"'], 9],
]);

it('refuses an unsafe .env by what Laravel loads, after installing and before any cache or migration', function (array $changes, array $appended, string $named): void {
    releaseEnvFile($this->dir, releaseEnvWith($changes, $appended));

    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and($run->getErrorOutput())->toContain($named)
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check environment']);
})->with([
    'APP_ENV=local' => [['APP_ENV' => 'local'], [], 'APP_ENV'],
    'APP_DEBUG=true' => [['APP_DEBUG' => 'true'], [], 'APP_DEBUG'],
    // ⚠️ A grep for APP_DEBUG=false would pass this file. phpdotenv loads the last of duplicate keys.
    'APP_DEBUG=false, then APP_DEBUG=true' => [[], ['APP_DEBUG=true'], 'APP_DEBUG'],
    'an http APP_URL' => [['APP_URL' => 'http://stage.kitsune.test'], [], 'APP_URL'],
    'no SESSION_SECURE_COOKIE' => [['SESSION_SECURE_COOKIE' => null], [], 'SESSION_SECURE_COOKIE'],
    'SESSION_SECURE_COOKIE=false' => [['SESSION_SECURE_COOKIE' => 'false'], [], 'SESSION_SECURE_COOKIE'],
    // ⚠️ An absent DB_CONNECTION falls back to SQLite: a file database in the release directory, lost on the next deploy.
    'no DB_CONNECTION' => [['DB_CONNECTION' => null], [], 'DB_CONNECTION'],
    'DB_CONNECTION=sqlite' => [['DB_CONNECTION' => 'sqlite'], [], 'DB_CONNECTION'],
    'export LARAVEL_CLOUD=1' => [[], ['export LARAVEL_CLOUD=1'], 'LARAVEL_CLOUD'],
    'LARAVEL_CLOUD=0' => [[], ['LARAVEL_CLOUD=0'], 'LARAVEL_CLOUD'],
    'an empty APP_KEY' => [['APP_KEY' => ''], [], 'APP_KEY'],
    'an APP_KEY of the wrong length' => [['APP_KEY' => 'base64:dG9vc2hvcnQ='], [], 'APP_KEY'],
]);

it('accepts what the framework accepts', function (array $changes, array $appended): void {
    releaseEnvFile($this->dir, releaseEnvWith($changes, $appended));

    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());
})->with([
    'a quoted APP_ENV' => [['APP_ENV' => '"production"'], []],
    'no APP_DEBUG, which defaults to false' => [['APP_DEBUG' => null], []],
    'an exported APP_URL' => [['APP_URL' => null], ['export APP_URL=https://stage.kitsune.test']],
]);

it('refuses a deploy-shell variable that overrides a correct .env', function (): void {
    /*
     * ⚠️ THE ENVIRONMENT WINS OVER .env, because Laravel's dotenv repository is immutable. A check that read the file
     * would pass here, and the release would then serve with debug on.
     */
    $run = releaseRun($this->dir, ['APP_DEBUG' => 'true']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('APP_DEBUG')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check environment']);
});

it('refuses to run anywhere but a fresh release directory, and touches nothing', function (string $case): void {
    /*
     * ⚠️ STEP 4 RUNS `rm -rf skeleton/storage`. Pointed at a development checkout, or at the live release, that
     * destroys real files, so where it runs is checked before anything is written.
     */
    $target = $this->release;

    if ($case === 'outside SITE_ROOT/releases') {
        $target = $this->dir.'/outside';
        releaseGit($this->dir, 'clone', '--quiet', $this->release, $target);
    }

    match ($case) {
        'outside SITE_ROOT/releases' => null,
        'with skeleton/vendor present' => File::makeDirectory($this->release.'/skeleton/vendor'),
        'with skeleton/.env a real file' => File::put($this->release.'/skeleton/.env', "APP_ENV=production\n"),
        'with an untracked skeleton/.env.production' => File::put($this->release.'/skeleton/.env.production', ''),
    };

    $run = releaseRun($this->dir, [], $target);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and(releaseLog($this->dir))->toBe([])
        ->and(is_link($target.'/skeleton/storage'))->toBeFalse()
        ->and(is_file($target.'/skeleton/storage/logs/.gitignore'))->toBeTrue()
        ->and(is_file($target.'/skeleton/storage/framework/views/.gitignore'))->toBeTrue();
})->with([
    'outside SITE_ROOT/releases',
    'with skeleton/vendor present',
    'with skeleton/.env a real file',
    'with an untracked skeleton/.env.production',
]);

it('refuses a missing shared .env, a relative SITE_ROOT and a COMPOSER_BIN that is not a file, before any Composer call', function (string $case): void {
    $env = match ($case) {
        'a missing shared .env' => [],
        'a relative SITE_ROOT' => ['SITE_ROOT' => 'site'],
        'a COMPOSER_BIN that is a directory' => ['COMPOSER_BIN' => $this->dir.'/bin'],
        'a COMPOSER_BIN that is not a command' => ['COMPOSER_BIN' => 'kitsune-no-such-composer'],
    };

    if ($case === 'a missing shared .env') {
        File::delete($this->dir.'/site/.env');
    }

    $run = releaseRun($this->dir, $env);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and(releaseLog($this->dir))->toBe([]);
})->with(['a missing shared .env', 'a relative SITE_ROOT', 'a COMPOSER_BIN that is a directory', 'a COMPOSER_BIN that is not a command']);

it('aborts at the first failing step, and runs nothing after it', function (string $step, string $call): void {
    /*
     * ⚠️ `optimize` PRINTS FAIL FOR A FAILED TASK AND STILL EXITS 0, and filament:optimize always returns success. The
     * caches are separate commands so that each of these exit statuses reaches `set -e`, and every step before
     * migrate fails before the schema changes.
     */
    $run = releaseRun($this->dir, ['STUB_FAIL' => $step]);
    $log = releaseLog($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and(end($log))->toStartWith($call);

    if (! in_array($step, ['migrate', 'kitsune:audit-patterns'], true)) {
        expect(releaseCalls($this->dir, 'artisan migrate'))->toBe([]);
    }
})->with([
    'extensions' => ['extensions', 'php extensions'],
    'install' => ['install', 'composer install'],
    'audit' => ['audit', 'composer audit'],
    'package:discover' => ['package:discover', 'artisan package:discover'],
    'filament:assets' => ['filament:assets', 'artisan filament:assets'],
    'storage:link' => ['storage:link', 'artisan storage:link'],
    'config:cache' => ['config:cache', 'artisan config:cache'],
    'event:cache' => ['event:cache', 'artisan event:cache'],
    'route:cache' => ['route:cache', 'artisan route:cache'],
    'view:cache' => ['view:cache', 'artisan view:cache'],
    'icons:cache' => ['icons:cache', 'artisan icons:cache'],
    'migrate' => ['migrate', 'artisan migrate'],
    'kitsune:audit-patterns' => ['kitsune:audit-patterns', 'artisan kitsune:audit-patterns'],
]);

it('refuses a kitsune/core installed as a symlink', function (): void {
    $run = releaseRun($this->dir, ['STUB_CORE_SYMLINK' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('kitsune/core')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['composer install -d skeleton --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts'])
        ->and(releaseCalls($this->dir, 'artisan'))->toBe([]);
});

it('refuses a public/storage link that did not get made, although storage:link exited 0', function (): void {
    $run = releaseRun($this->dir, ['STUB_NO_LINK' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('public/storage')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['artisan storage:link --no-interaction']);
});

it('catches a cache command that exits 0 without building its cache', function (): void {
    $run = releaseRun($this->dir, ['STUB_CACHE' => 'noop']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the configuration was not cached')
        ->and($run->getErrorOutput())->toContain('the route table was not cached')
        ->and($run->getErrorOutput())->toContain('the event map was not cached')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check serving'])
        ->and(releaseCalls($this->dir, 'artisan migrate'))->toBe([]);
});

it('deploys over a site in maintenance mode, and leaves it down', function (): void {
    /*
     * ⚠️ THE MARKER IS IN THE SHARED STORAGE, so the new release activates down too, which is what fixing forward needs.
     * /up is exempt from maintenance mode, so the serving check still proves the release serves.
     */
    File::ensureDirectoryExists($this->dir.'/site/storage/framework');
    File::put($this->dir.'/site/storage/framework/down', '{}');

    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(releaseCalls($this->dir, 'artisan migrate'))->toBe(['artisan migrate --force --no-interaction'])
        ->and(is_file($this->dir.'/site/storage/framework/down'))->toBeTrue();
});

it('refuses an optimize task it does not know', function (): void {
    /*
     * ⚠️ THE CACHE STEPS ARE A LIST, AND A VENDOR CAN GROW IT. A provider that registers a new optimize task would
     * otherwise be skipped in silence, because the script runs the caches by name instead of through `optimize`.
     */
    $run = releaseRun($this->dir, ['FIXTURE_OPTIMIZES' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('[fixture] => [fixture:optimize]')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check serving'])
        ->and(releaseCalls($this->dir, 'artisan migrate'))->toBe([]);
});

it('passes the /up check on a healthy minimal app', function (): void {
    /*
     * This proves the check mechanism — the HTTP kernel, cached routes and production config answering /up — on the
     * fixture app. The real providers are proven by the same check when a real release runs.
     */
    $run = releaseRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getErrorOutput())->not->toContain('/up answered');
});

it('refuses a release whose /up does not answer 200, before migrating', function (): void {
    $run = releaseRun($this->dir, ['FIXTURE_HEALTH_FAILS' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('/up answered 500')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check serving'])
        ->and(releaseCalls($this->dir, 'artisan migrate'))->toBe([]);
});

it('refuses a site root, shared storage, checkout or PHP it cannot trust, before any Composer call', function (string $case, string $named): void {
    if (str_contains($case, 'does not own') || str_contains($case, 'another user')) {
        if (releaseRunsAsRoot()) {
            $this->markTestSkipped('Running as root, every directory is this user\'s to use.');
        }
    }

    $env = match ($case) {
        'a site root this user does not own' => ['SITE_ROOT' => $this->dir.'/not-mine'],
        'a PHP_BIN that is not a command' => ['PHP_BIN' => 'kitsune-no-such-php'],
        default => [],
    };

    match ($case) {
        'a site root this user does not own' => symlink('/', $this->dir.'/not-mine'),
        'a shared storage that belongs to another user' => symlink('/', $this->dir.'/site/storage'),
        'a directory that is not a Kitsune checkout' => File::delete($this->release.'/skeleton/artisan'),
        'a PHP_BIN that is not a command' => null,
    };

    $run = releaseRun($this->dir, $env);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to release')
        ->and($run->getErrorOutput())->toContain($named)
        ->and(releaseLog($this->dir))->toBe([]);
})->with([
    'a site root this user does not own' => ['a site root this user does not own', 'run as the user that owns'],
    'a shared storage that belongs to another user' => ['a shared storage that belongs to another user', 'belongs to another user'],
    'a directory that is not a Kitsune checkout' => ['a directory that is not a Kitsune checkout', 'is not the root of a Kitsune checkout'],
    'a PHP_BIN that is not a command' => ['a PHP_BIN that is not a command', 'PHP_BIN is not a command'],
]);

it('refuses an install that left no autoloader, before anything boots', function (): void {
    $run = releaseRun($this->dir, ['STUB_NO_AUTOLOAD' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('skeleton/vendor/autoload.php is missing')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check syntax'])
        ->and(releaseCalls($this->dir, 'artisan'))->toBe([]);
});

it('refuses an app that does not boot, and withholds the exception message', function (): void {
    /*
     * ⚠️ A BOOT EXCEPTION'S MESSAGE CAN QUOTE CONFIGURATION, so the refusal names the exception's class and where it
     * was thrown, and never its message.
     */
    $run = releaseRun($this->dir, ['FIXTURE_BOOT_FAILS' => '1']);
    $everything = $run->getOutput().$run->getErrorOutput();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the application did not boot: RuntimeException')
        ->and($everything)->not->toContain('SECRET-BOOT-MESSAGE')
        ->and(array_slice(releaseLog($this->dir), -1))->toBe(['php check environment'])
        ->and(releaseCalls($this->dir, 'artisan migrate'))->toBe([]);
});
