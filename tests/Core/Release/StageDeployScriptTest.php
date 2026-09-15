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
 * Stage's stand-in for Forge's zero-downtime macros, deploy/stage-deploy.sh, run for real against a local bare
 * repository and a temporary site root — issue #111.
 *
 * ⚠️ RUN, NOT READ. The commit it deploys carries a fake deploy/release.sh that logs what it was given and can
 * succeed, fail, wait for a file or sleep, so the runner's own guarantees are proven in isolation: which commit, in
 * which directory, in what order, what a failure leaves behind, the swap, the reload, retention and the lock.
 * deploy/release.sh itself is ReleaseScriptTest's.
 *
 * sudo, perl and id are stubs on PATH. perl logs and then runs the real perl, so the swap the tests see is the
 * rename(2) stage runs, and it can signal the deploy while it is still the running command.
 *
 * Needs only bash, git and perl, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-stage-'.bin2hex(random_bytes(6));
    $this->site = $this->dir.'/site';

    File::makeDirectory($this->dir.'/work', 0755, true);
    File::makeDirectory($this->dir.'/bin');
    File::makeDirectory($this->site);

    stageGit($this->dir, 'init', '--quiet', '--bare', '--initial-branch=main', $this->dir.'/origin.git');
    stageGit($this->dir.'/work', 'init', '--quiet', '--initial-branch=main');

    foreach (['sudo' => stageSudoStub(), 'perl' => stagePerlStub(), 'id' => stageIdStub()] as $name => $stub) {
        File::put($this->dir.'/bin/'.$name, $stub);
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    $this->sha = stageCommit($this->dir, 'The first commit');
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** Run git in a directory with a fixed identity, and return its trimmed output. */
function stageGit(string $cwd, string ...$args): string
{
    $process = new Process(
        ['git', '-c', 'user.name=Stage Test', '-c', 'user.email=stage@kitsune.test', '-c', 'commit.gpgsign=false', ...$args],
        $cwd,
    );
    $process->mustRun();

    return trim($process->getOutput());
}

/** Commit the fake release body with a distinguishing file, push it to the origin's main, and return the commit. */
function stageCommit(string $dir, string $message): string
{
    $work = $dir.'/work';

    File::ensureDirectoryExists($work.'/deploy');
    File::put($work.'/deploy/release.sh', stageFakeRelease());
    File::put($work.'/commit.txt', $message."\n");

    stageGit($work, 'add', '--all');
    stageGit($work, 'commit', '--quiet', '-m', $message);
    stageGit($work, 'push', '--quiet', $dir.'/origin.git', 'HEAD:main');

    return stageGit($work, 'rev-parse', 'HEAD');
}

/** The real perl, found before the stubs go on PATH. */
function stageRealPerl(): string
{
    static $perl = null;

    return $perl ??= trim((new Process(['sh', '-c', 'command -v perl']))->mustRun()->getOutput());
}

/**
 * The deploy, not yet started, with an environment that starts empty so nothing of this process's leaks into it.
 *
 * @param  array<string, string|false>  $env
 */
function stageProcess(string $dir, string $sha, array $env = []): Process
{
    $process = new Process(
        ['bash', dirname(__DIR__, 3).'/deploy/stage-deploy.sh'],
        $dir,
        array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), [
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => $dir.'/bin:'.getenv('PATH'),
            'DEPLOY_SHA' => $sha,
            'SITE_ROOT' => $dir.'/site',
            'REPO_URL' => $dir.'/origin.git',
            'STAGE_LOG' => $dir.'/calls.log',
            'STAGE_REAL_PERL' => stageRealPerl(),
        ], $env),
    );

    $process->setTimeout(60);

    return $process;
}

/**
 * Run a deploy to the end.
 *
 * @param  array<string, string|false>  $env
 */
function stageDeploy(string $dir, string $sha, array $env = []): Process
{
    $process = stageProcess($dir, $sha, $env);
    $process->run();

    return $process;
}

/** The release `current` points at, by name, or null when there is no `current`. */
function stageCurrent(string $dir): ?string
{
    $current = $dir.'/site/current';

    return is_link($current) ? basename((string) readlink($current)) : null;
}

/**
 * Everything in releases/, by name.
 *
 * @return list<string>
 */
function stageReleases(string $dir): array
{
    $releases = $dir.'/site/releases';

    return is_dir($releases) ? array_values(array_diff((array) scandir($releases), ['.', '..'])) : [];
}

/**
 * Every call the fake release body and the stubs logged, in order.
 *
 * @return list<string>
 */
function stageLog(string $dir): array
{
    $log = $dir.'/calls.log';

    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
}

/**
 * The logged calls that start with a prefix.
 *
 * @return list<string>
 */
function stageCalls(string $dir, string $prefix): array
{
    return array_values(array_filter(stageLog($dir), fn (string $call): bool => str_starts_with($call, $prefix)));
}

/** Whether this suite itself runs as root, where ownership refusals cannot be provoked. */
function stageRunsAsRoot(): bool
{
    return trim((new Process(['id', '-u']))->mustRun()->getOutput()) === '0';
}

/**
 * Run a deploy that has to succeed, once, and fail the test with its output when it does not.
 *
 * ⚠️ NOT stageDeploy()->mustRun(). stageDeploy() has already run the process, and mustRun() runs it a second time:
 * the same deploy, inside the same second, refused for reusing the release name the first run had just created.
 *
 * @param  array<string, string|false>  $env
 */
function stageMustDeploy(string $dir, string $sha, array $env = []): void
{
    $run = stageDeploy($dir, $sha, $env);

    if (! $run->isSuccessful()) {
        throw new RuntimeException('The deploy failed: '.$run->getErrorOutput());
    }
}

/** Wait until the UTC second changes, so the next deploy's release name, a timestamp to the second, is a new one. */
function stageNextSecond(): void
{
    $now = gmdate('YmdHis');

    while (gmdate('YmdHis') === $now) {
        usleep(20_000);
    }
}

/** A release body that logs what it was given, and fails, waits or sleeps when the environment asks it to. */
function stageFakeRelease(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    set -euo pipefail

    printf 'release pwd=%s SITE_ROOT=%s PHP_BIN=%s COMPOSER_BIN=%s DEPLOY_SHA=%s current=%s sha=%s\n' \
      "$(pwd -P)" "$SITE_ROOT" "$PHP_BIN" "$COMPOSER_BIN" "$DEPLOY_SHA" \
      "$(readlink "$SITE_ROOT/current" || echo none)" "$(git rev-parse HEAD)" >> "$STAGE_LOG"

    if [[ -n "${STAGE_WAIT_FOR:-}" ]]; then
      for _ in $(seq 1 100); do
        [[ ! -e "$STAGE_WAIT_FOR" ]] || break
        sleep 0.1
      done
    fi

    if [[ -n "${STAGE_SLEEP:-}" ]]; then
      sleep "$STAGE_SLEEP"
    fi

    if [[ "${STAGE_FAIL:-}" == 1 ]]; then
      exit 1
    fi

    BASH;
}

/** A stand-in for sudo that logs the command it was asked to run. */
function stageSudoStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    printf 'sudo %s\n' "$*" >> "$STAGE_LOG"
    exit "${STAGE_SUDO_EXIT:-0}"

    BASH;
}

/**
 * A stand-in for perl that logs the rename it was asked for, then runs the real perl. With STAGE_TERM_AFTER_RENAME it
 * sends the deploy a TERM once the rename has succeeded, while perl is still the command the deploy is waiting on.
 */
function stagePerlStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    printf 'perl rename %s %s\n' "$3" "$4" >> "$STAGE_LOG"

    if [[ "${STAGE_TERM_AFTER_RENAME:-}" == 1 ]]; then
      "$STAGE_REAL_PERL" "$@" || exit
      kill -TERM "$PPID"
      exit 0
    fi

    exec "$STAGE_REAL_PERL" "$@"

    BASH;
}

/** A stand-in for id: `id -u` answers STAGE_UID, so root is refused without root, and the suite still runs as root. */
function stageIdStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    if [[ "${1:-}" == -u ]]; then
      echo "${STAGE_UID:-1000}"
    else
      exec /usr/bin/id "$@"
    fi

    BASH;
}

it('is valid bash', function (): void {
    $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/deploy/stage-deploy.sh']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getErrorOutput())->toBe('');
});

it('refuses a DEPLOY_SHA that is not a full hash, and runs nothing it contains', function (): void {
    /*
     * ⚠️ THE SHA REACHES git, AND THE RELEASE NAME REACHES rm -rf. Every input is checked before either, following
     * split-package.sh: nothing an input contains can become shell.
     */
    $marker = $this->dir.'/injected';

    $run = stageDeploy($this->dir, "main;touch {$marker}");

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to deploy')
        ->and(file_exists($marker))->toBeFalse()
        ->and(is_dir($this->site.'/releases'))->toBeFalse()
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('refuses a bad input before cloning', function (array $env, bool $currentIsDirectory): void {
    /*
     * ⚠️ A LEADING ZERO IS OCTAL TO BASH ARITHMETIC: `$((0600))` is 384, and `(( 08 >= 2 ))` is an error. Whole
     * numbers are therefore matched without leading zeros before any arithmetic reads them.
     */
    if ($currentIsDirectory) {
        File::makeDirectory($this->site.'/current');
    }

    $run = stageDeploy($this->dir, $this->sha, $env);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to deploy')
        ->and(is_dir($this->site.'/releases'))->toBeFalse()
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
})->with([
    'KEEP_RELEASES=1' => [['KEEP_RELEASES' => '1'], false],
    'KEEP_RELEASES=x' => [['KEEP_RELEASES' => 'x'], false],
    'KEEP_RELEASES=08' => [['KEEP_RELEASES' => '08'], false],
    'KEEP_RELEASES=02' => [['KEEP_RELEASES' => '02'], false],
    'DEPLOY_TIME_LIMIT=x' => [['DEPLOY_TIME_LIMIT' => 'x'], false],
    'DEPLOY_TIME_LIMIT=0600' => [['DEPLOY_TIME_LIMIT' => '0600'], false],
    'DEPLOY_TIME_LIMIT=08' => [['DEPLOY_TIME_LIMIT' => '08'], false],
    'PHP_FPM_RELOAD=yes' => [['PHP_FPM_RELOAD' => 'yes'], false],
    'PHP_FPM_SERVICE=nginx' => [['PHP_FPM_SERVICE' => 'nginx'], false],
    'current as a real directory' => [[], true],
]);

it('refuses to run as root, before taking the lock', function (): void {
    /*
     * ⚠️ A ROOT DEPLOY WOULD LEAVE ROOT-OWNED RELEASES, which a later deploy as forge could neither prune nor replace,
     * and root-owned files in the shared storage, which PHP-FPM could not write.
     */
    $run = stageDeploy($this->dir, $this->sha, ['STAGE_UID' => '0']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('never run as root')
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse()
        ->and(stageReleases($this->dir))->toBe([]);
});

it('refuses a site root it does not own, before taking the lock', function (): void {
    if (stageRunsAsRoot()) {
        $this->markTestSkipped('Running as root, every directory is this user\'s to use.');
    }

    symlink('/', $this->dir.'/not-mine');

    $run = stageDeploy($this->dir, $this->sha, ['SITE_ROOT' => $this->dir.'/not-mine']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('owns')
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse()
        ->and(stageReleases($this->dir))->toBe([]);
});

it('deploys the named commit, not the branch tip', function (): void {
    $first = $this->sha;
    stageCommit($this->dir, 'The second commit');

    $run = stageDeploy($this->dir, $first);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(stageCalls($this->dir, 'release '))->toHaveCount(1)
        ->and(stageCalls($this->dir, 'release ')[0])->toContain("DEPLOY_SHA={$first}")
        ->and(stageCalls($this->dir, 'release ')[0])->toEndWith("sha={$first}")
        ->and(stageGit($this->site.'/current', 'rev-parse', 'HEAD'))->toBe($first);
});

it('runs release.sh inside the new release, with stage\'s binaries, while current is still the previous release', function (): void {
    stageMustDeploy($this->dir, $this->sha);
    $previous = stageCurrent($this->dir);

    // Release names are UTC timestamps to the second.
    stageNextSecond();

    $second = stageCommit($this->dir, 'The second commit');
    File::delete($this->dir.'/calls.log');

    $run = stageDeploy($this->dir, $second);
    $new = stageCurrent($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($new)->not->toBe($previous)
        ->and(stageCalls($this->dir, 'release '))->toBe([
            "release pwd={$this->site}/releases/{$new} SITE_ROOT={$this->site} PHP_BIN=php8.5 COMPOSER_BIN=/usr/bin/composer"
            ." DEPLOY_SHA={$second} current={$this->site}/releases/{$previous} sha={$second}",
        ]);
});

it('aborts before activation when release.sh fails, and removes what it created', function (bool $firstDeploy): void {
    $before = null;

    if (! $firstDeploy) {
        stageMustDeploy($this->dir, $this->sha);
        $before = stageCurrent($this->dir);
        stageNextSecond();
        File::delete($this->dir.'/calls.log');
    }

    $run = stageDeploy($this->dir, $this->sha, ['STAGE_FAIL' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('current is unchanged')
        ->and(stageCurrent($this->dir))->toBe($before)
        ->and(stageReleases($this->dir))->toBe($before === null ? [] : [$before])
        ->and(stageCalls($this->dir, 'release '))->toHaveCount(1)
        ->and(stageCalls($this->dir, 'perl rename'))->toBe([])
        ->and(stageCalls($this->dir, 'sudo'))->toBe([])
        ->and(file_exists($this->site.'/current.tmp') || is_link($this->site.'/current.tmp'))->toBeFalse()
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
})->with(['on the first deploy' => true, 'over a previous release' => false]);

it('fails the same way for a commit the repository does not have', function (): void {
    $run = stageDeploy($this->dir, str_repeat('0', 40));

    expect($run->isSuccessful())->toBeFalse()
        ->and(stageCurrent($this->dir))->toBeNull()
        ->and(stageReleases($this->dir))->toBe([])
        ->and(stageLog($this->dir))->toBe([])
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('activates with one rename of a new link over current', function (): void {
    /*
     * ⚠️ STAGE'S mv IS NOT GNU mv. Ubuntu 26.04's coreutils defaults to uutils, whose -T nothing here has verified,
     * and BSD mv has no -T at all. A rename(2) through perl replaces the link in one step on every platform, so
     * `current` always resolves to the old release or the new one.
     */
    $run = stageDeploy($this->dir, $this->sha);
    $name = stageCurrent($this->dir);
    $log = stageLog($this->dir);
    $rename = "perl rename {$this->site}/current.tmp {$this->site}/current";

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(stageCalls($this->dir, 'perl rename'))->toBe([$rename])
        ->and(readlink($this->site.'/current'))->toBe("{$this->site}/releases/{$name}")
        ->and(file_exists($this->site.'/current.tmp') || is_link($this->site.'/current.tmp'))->toBeFalse()
        ->and(array_search($rename, $log, true))->toBeGreaterThan(array_search(stageCalls($this->dir, 'release ')[0], $log, true));
});

it('keeps a release a signal interrupted after the rename, because current already names it', function (): void {
    /*
     * ⚠️ BASH RUNS A TRAP ONLY AFTER THE COMMAND IT INTERRUPTED RETURNS. A TERM that arrived while perl renamed ran the
     * cleanup before the line after the rename, so a flag set on that line still said "not activated", and the cleanup
     * removed the release current had just been pointed at: the site was left on a dangling link.
     */
    $run = stageDeploy($this->dir, $this->sha, ['STAGE_TERM_AFTER_RENAME' => '1']);
    $name = stageCurrent($this->dir);

    expect($run->getExitCode())->toBe(143)
        ->and($name)->not->toBeNull()
        ->and(is_file($this->site.'/releases/'.$name.'/commit.txt'))->toBeTrue('the release current names was removed')
        ->and(stageReleases($this->dir))->toBe([$name])
        ->and($run->getErrorOutput())->not->toContain('did not activate')
        ->and($run->getErrorOutput())->toContain("releases/{$name} IS ACTIVE")
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('does not reload PHP-FPM by default, which Forge documents as unnecessary', function (): void {
    $run = stageDeploy($this->dir, $this->sha);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(stageCalls($this->dir, 'sudo'))->toBe([]);
});

it('reloads PHP-FPM through the one sudo right when asked, after activating', function (): void {
    $run = stageDeploy($this->dir, $this->sha, ['PHP_FPM_RELOAD' => '1']);
    $log = stageLog($this->dir);
    $reload = 'sudo -n /usr/sbin/service php8.5-fpm reload';

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(stageCalls($this->dir, 'sudo'))->toBe([$reload])
        ->and(array_search($reload, $log, true))->toBeGreaterThan(array_search(stageCalls($this->dir, 'perl rename')[0], $log, true));
});

it('says a failed reload left the new release active', function (): void {
    $run = stageDeploy($this->dir, $this->sha, ['PHP_FPM_RELOAD' => '1', 'STAGE_SUDO_EXIT' => '1']);
    $name = stageCurrent($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('IS ACTIVE')
        ->and($name)->not->toBeNull()
        ->and(is_dir($this->site.'/releases/'.$name))->toBeTrue()
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('keeps the newest releases and always the active one, and leaves anything else in releases/ alone', function (): void {
    /*
     * ⚠️ THE ACTIVE RELEASE SORTS OLDER HERE, as it would after a clock went backwards. Keeping the newest names alone
     * would prune the release that is serving.
     */
    File::makeDirectory($this->site.'/releases/29990101000000', 0755, true);
    File::makeDirectory($this->site.'/releases/29990101000001');
    File::makeDirectory($this->site.'/releases/20000101000000');
    File::makeDirectory($this->site.'/releases/manual');
    File::put($this->site.'/releases/notes.txt', 'not a release');

    $run = stageDeploy($this->dir, $this->sha, ['KEEP_RELEASES' => '2']);
    $active = stageCurrent($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(stageReleases($this->dir))->toEqualCanonicalizing(['29990101000000', '29990101000001', $active, 'manual', 'notes.txt']);
});

it('counts only real releases when it prunes', function (): void {
    foreach (range(1, 4) as $deploy) {
        if ($deploy > 1) {
            stageNextSecond();
        }

        stageMustDeploy($this->dir, $this->sha, ['KEEP_RELEASES' => '2']);
    }

    $releases = stageReleases($this->dir);
    sort($releases);

    expect($releases)->toHaveCount(2)
        ->and(stageCurrent($this->dir))->toBe(end($releases));
});

it('refuses to overlap a deploy that is running', function (): void {
    $go = $this->dir.'/go';
    $first = stageProcess($this->dir, $this->sha, ['STAGE_WAIT_FOR' => $go]);
    $first->start();

    $deadline = microtime(true) + 10;

    while (stageCalls($this->dir, 'release ') === [] && microtime(true) < $deadline) {
        usleep(50_000);
    }

    $releases = stageReleases($this->dir);
    $second = stageDeploy($this->dir, $this->sha);

    expect(stageCalls($this->dir, 'release '))->toHaveCount(1)
        ->and($second->isSuccessful())->toBeFalse()
        ->and($second->getErrorOutput())->toContain('another deploy holds')
        ->and(stageCurrent($this->dir))->toBeNull()
        ->and(stageReleases($this->dir))->toBe($releases);

    touch($go);
    $first->wait();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and(stageCurrent($this->dir))->toBe($releases[0])
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('refuses a stale lock, and leaves it for the operator', function (): void {
    File::makeDirectory($this->site.'/.deploy-lock');
    File::put($this->site.'/.deploy-lock/owner', "4242 2026-09-15T00:00:00Z\n");

    $run = stageDeploy($this->dir, $this->sha);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('4242 2026-09-15T00:00:00Z')
        ->and($run->getErrorOutput())->toContain('remove that directory')
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeTrue()
        ->and(stageReleases($this->dir))->toBe([]);
});

it('activates a run over the time limit, then reports it', function (): void {
    /*
     * ⚠️ BY THE TIME THE CLOCK IS READ, release.sh HAS MIGRATED. Refusing to activate then would leave the new schema
     * under the old code, so the release goes live and the run fails loudly, which is what surfaces a slow resolve on
     * stage before alpha's 10-minute limit fails it there.
     */
    $run = stageDeploy($this->dir, $this->sha, ['DEPLOY_TIME_LIMIT' => '0', 'STAGE_SLEEP' => '1']);
    $name = stageCurrent($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('IS ACTIVE')
        ->and($run->getErrorOutput())->toContain('longer than 0s')
        ->and($name)->not->toBeNull()
        ->and(is_dir($this->site.'/releases/'.$name))->toBeTrue()
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();
});

it('refuses an empty DEPLOY_SHA and a relative SITE_ROOT, before taking the lock', function (array $env): void {
    $run = stageDeploy($this->dir, $this->sha, $env);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to deploy')
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse()
        ->and(stageReleases($this->dir))->toBe([]);
})->with([
    'an empty DEPLOY_SHA' => [['DEPLOY_SHA' => '']],
    'a relative SITE_ROOT' => [['SITE_ROOT' => 'site']],
]);

it('refuses to reuse a release name, and leaves that release alone', function (): void {
    /*
     * ⚠️ created IS SET ONLY AFTER THE NAME IS CHECKED, so the cleanup of a refused run cannot remove a release that
     * was already there. Names are UTC timestamps to the second, so the next five are taken before the deploy starts.
     */
    $now = time();
    $taken = array_map(fn (int $offset): string => gmdate('YmdHis', $now + $offset), range(0, 4));

    foreach ($taken as $name) {
        File::makeDirectory($this->site.'/releases/'.$name, 0755, true);
        File::put($this->site.'/releases/'.$name.'/marker', "someone else's release\n");
    }

    $run = stageDeploy($this->dir, $this->sha);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('exists')
        ->and(stageCurrent($this->dir))->toBeNull()
        ->and(stageLog($this->dir))->toBe([])
        ->and(is_dir($this->site.'/.deploy-lock'))->toBeFalse();

    foreach ($taken as $name) {
        expect(is_file($this->site.'/releases/'.$name.'/marker'))->toBeTrue("releases/{$name} was touched");
    }
});
