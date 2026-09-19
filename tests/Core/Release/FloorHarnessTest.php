<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Core\Kitsune;
use Symfony\Component\Process\Process;

/*
 * bin/benchmark-floor.sh reproduces ADR-027's resource-floor measurement under the floor's own limits.
 *
 * ⚠️ IT IS RUN HERE, NOT GREPPED. The first version of this file asserted that the script CONTAINED certain
 * substrings, which a harness with its limits hardcoded — or with no limits at all — could satisfy while
 * measuring the wrong thing entirely. The precedent is CiScopeScriptTest: drive the real script with stub
 * binaries on PATH and judge what it actually does.
 *
 * ⚠️ AND WHAT IT GUARDS IS A NUMBER NOBODY COULD RE-CHECK. docs/roadmap.md recorded a constrained column for
 * eleven days while compose.yaml pinned no limit and no script did either. An unreproducible measurement is
 * not evidence, however careful the run that produced it was.
 */

beforeEach(function (): void {
    $this->harness = dirname(__DIR__, 3).'/bin/benchmark-floor.sh';
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-floor-harness-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir.'/bin', 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * A `docker` stub that records every argv it is given and answers with a canned benchmark run.
 *
 * ⚠️ A STUB THAT ANSWERS LIKE THE REAL COMMAND, not one tidier than it. `docker info`, `image inspect`, the
 * two `php -r` probes and the benchmark itself all come through the same binary, so the stub has to tell them
 * apart the way Docker would — and a stub that answered everything identically would hide the very argv this
 * test exists to read.
 */
function floorStubs(string $dir, string $scope = 'ENTRIES', int $exit = 0, int $seededOnMeasure = 0, bool $platformFails = false): void
{
    $platformFailsFlag = $platformFails ? 1 : 0;

    File::put($dir.'/bin/docker', <<<STUB
    #!/usr/bin/env bash
    printf '%s\\n' "\$*" >> "$dir/argv.log"

    case "\$*" in
      info*) exit 0 ;;
      build*) exit 0 ;;
      *image\\ inspect*) echo 'kitsune-floor@sha256:stubbed' ; exit 0 ;;
    esac

    # The image's PHP version, read before Composer resolves; and Composer's own platform check, run in the image.
    case "\$*" in
      *"PHP_VERSION;"*) echo '8.4.25' ; exit 0 ;;
      *platform_check.php*)
        if [[ "$platformFailsFlag" == 1 ]]; then
          echo 'Composer detected issues in your platform: Your Composer dependencies require the following PHP extensions to be installed: intl, zip.'
          exit 255
        fi
        exit 0
        ;;
    esac

    # The floor the harness must size its container from, and the interpreter line it records.
    case "\$*" in
      *FLOOR_VCPU*) echo '{$dir}' >/dev/null; echo "1 1024" ; exit 0 ;;
      *memory_limit*) echo '8.4.25 memory_limit=128M opcache.enable_cli=0' ; exit 0 ;;
    esac

    case "\$*" in
      *benchmark-floor*)
        entries=\$(printf '%s\\n' "\$*" | sed -n 's/.*--entries=\\([0-9]*\\).*/\\1/p')
        # A --keep run is the harness seeding: it inserts every entry, as the real command's first run does.
        if [[ "\$*" == *--keep* ]]; then
          echo "  content in scope: \$entries entries"
          echo "  seeded by this run: \$entries entries"
          exit 0
        fi
        scope="$scope"
        [[ "\$scope" == ENTRIES ]] && scope=\$entries
        echo "  content in scope: \$scope entries"
        echo "  seeded by this run: $seededOnMeasure entries"
        echo "  peak serving a request       31.5 MB"
        echo "  workers that fit in half the floor: 16"
        exit $exit
        ;;
    esac

    exit 0
    STUB);

    // `artisan` calls go through the same stub; composer only has to leave a real-looking core in place.
    File::put($dir.'/bin/composer', <<<STUB
    #!/usr/bin/env bash
    printf 'composer %s\\n' "\$*" >> "$dir/argv.log"
    for arg in "\$@"; do
      case "\$prev" in -d) app=\$arg ;; esac
      prev=\$arg
    done
    if [[ "\$1" == install ]]; then
      mkdir -p "\$app/vendor/kitsune/core"
      echo '{"name":"kitsune/core"}' > "\$app/vendor/kitsune/core/composer.json"
      # As the real command: install from a lock that is there, and resolve (writing one) when it is not. Which it
      # did is recorded, so a test can tell a lock handed in before the install from one written after it.
      if [[ -f "\$app/composer.lock" ]]; then
        echo "install: from an existing lock" >> "$dir/argv.log"
      else
        echo '{"packages":[{"name":"laravel/framework","version":"v13.0.0-resolved"}]}' > "\$app/composer.lock"
        echo "install: resolved fresh" >> "$dir/argv.log"
      fi
    fi
    exit 0
    STUB);

    chmod($dir.'/bin/docker', 0755);
    chmod($dir.'/bin/composer', 0755);
}

function runHarness(string $dir, string $harness, array $args = []): Process
{
    $process = Process::fromShellCommandline(
        'bash '.escapeshellarg($harness).' '.implode(' ', array_map('escapeshellarg', $args)),
        $dir,
        ['PATH' => $dir.'/bin:'.getenv('PATH'), 'TMPDIR' => $dir],
    );

    $process->setTimeout(120);
    $process->run();

    return $process;
}

it('is valid bash', function (): void {
    $check = Process::fromShellCommandline('bash -n '.escapeshellarg($this->harness));
    $check->run();

    expect($check->isSuccessful())->toBeTrue($check->getErrorOutput())
        ->and(is_executable($this->harness))->toBeTrue('bin/benchmark-floor.sh is not executable');
});

it('sizes the container from the floor the application reports, not from a number written in the script', function (): void {
    /*
     * ⚠️ THE POINT OF THE WHOLE EXERCISE. A harness carrying its own copy of the floor is a second floor, free
     * to disagree with Kitsune::FLOOR_* the day either moves — and the disagreement would show up as a
     * measurement against limits the code no longer claims, reported as though it were the floor.
     */
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    $argv = (string) File::get($this->dir.'/argv.log');

    expect($argv)
        ->toContain('--cpus='.Kitsune::FLOOR_VCPU)
        ->toContain('--memory='.Kitsune::FLOOR_MEMORY_MB.'m')
        // Without this Docker grants twice the memory as swap, and the cap the run is named for is not the cap.
        ->toContain('--memory-swap='.Kitsune::FLOOR_MEMORY_MB.'m');
});

it('measures the unconstrained column from the same image, with no limits at all', function (): void {
    // Two columns from two different PHP builds measure the builds as much as the limits — which is what the
    // 2026-09-07 table did, and why its 2 MB "difference" was a confound rather than a finding.
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    $measureRuns = array_values(array_filter(
        explode("\n", (string) File::get($this->dir.'/argv.log')),
        static fn (string $line): bool => str_contains($line, 'benchmark-floor') && ! str_contains($line, '--keep'),
    ));

    expect($measureRuns)->toHaveCount(2)
        ->and($measureRuns[0])->toContain('--cpus=')
        ->and($measureRuns[1])->not->toContain('--cpus=')
        ->and($measureRuns[1])->not->toContain('--memory=')
        ->and($run->getOutput())->toContain('unconstrained');
});

it('seeds each column in a process of its own before measuring in another', function (): void {
    /*
     * ⚠️ PHP KEEPS THE HEAP AN INSERT GREW. `memory_reset_peak_usage()` moves the recorded mark down to what the
     * process holds, not to what a request needs, so measuring in the process that seeded reported the seeding
     * as the request: 40.5 MB after 100 entries, 42.5 MB after 1,000 or 5,000, for requests reading the same 25
     * rows. Codex found it on #126. So every measuring run must follow a seeding run, and seed nothing itself.
     */
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    $benchmarkRuns = array_values(array_filter(
        explode("\n", (string) File::get($this->dir.'/argv.log')),
        static fn (string $line): bool => str_contains($line, 'benchmark-floor'),
    ));

    // Seed, measure constrained; seed, measure unconstrained — each measurement preceded by its own seeding.
    expect($benchmarkRuns)->toHaveCount(4);

    foreach ([0, 2] as $seed) {
        expect($benchmarkRuns[$seed])->toContain('--keep')
            // The seeding is setup, not the measurement, so the floor's limits are not what it runs under.
            ->and($benchmarkRuns[$seed])->not->toContain('--cpus=')
            ->and($benchmarkRuns[$seed + 1])->not->toContain('--keep');
    }
});

it('refuses a measurement taken in a process that seeded, because its peak is the seeding\'s', function (): void {
    floorStubs($this->dir, seededOnMeasure: 25);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    expect($run->isSuccessful())->toBeFalse('a measurement that seeded was reported as a request')
        ->and($run->getErrorOutput())->toContain('its peak includes the seeding');
});

it('refuses a run that died after announcing its scope, rather than printing a blank column', function (): void {
    /*
     * ⚠️ THE FAILURE THAT LOOKED LIKE A MEASUREMENT. The scope line is printed before the first sample, so a
     * container killed by the cgroup — or a PHP fatal at the image's own 128 MB limit — had already said
     * everything the harness checked. Without the exit status the columns came out empty and the run exited 0.
     */
    floorStubs($this->dir, exit: 137);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    expect($run->isSuccessful())->toBeFalse('a killed container was reported as a measurement')
        ->and($run->getErrorOutput())->toContain('exited 137');
});

it('refuses a run whose scope is not the volume it asked for', function (): void {
    // The 2026-09-07 defect: no site context, so SiteScope adds WHERE 1 = 0 and every sample times an empty
    // result set. The command now reports what the scoped model can see, so the harness can compare.
    floorStubs($this->dir, scope: '0');
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('measured nothing');
});

it('refuses an --entries that is not a positive integer', function (string $value): void {
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', $value]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('positive integer');
})->with(['zero' => '0', 'negative' => '-5', 'words' => 'lots', 'empty' => '']);

it('leaves nothing of the disposable install behind', function (): void {
    floorStubs($this->dir);
    runHarness($this->dir, $this->harness, ['--entries', '25']);

    $leftovers = array_values(array_filter(
        (array) scandir($this->dir),
        static fn (string $entry): bool => str_starts_with($entry, 'kitsune-floor-'),
    ));

    expect($leftovers)->toBe([], 'the disposable install survived the run');
});

it('installs a recorded dependency graph from --lock, before composer resolves anything', function (): void {
    /*
     * ⚠️ THE IMAGE DIGEST PINS THE INTERPRETER, NOT THE APPLICATION. The skeleton commits no lock, so a plain run
     * resolves whatever satisfies its constraints that day — right for the floor, which is a claim about what an
     * operator installs today, and wrong for re-checking a recorded figure, which the next compatible release
     * changes (Codex, #126). --lock puts the recorded graph in place BEFORE the install, so the install uses it.
     */
    floorStubs($this->dir);
    $lock = $this->dir.'/recorded.lock';
    File::put($lock, (string) json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.32.0']]]));

    $run = runHarness($this->dir, $this->harness, ['--entries', '25', '--lock', $lock]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and((string) File::get($this->dir.'/argv.log'))->toContain('install: from an existing lock')
        ->and($run->getOutput())->toContain('laravel/framework v13.32.0')
        ->and($run->getOutput())->toContain('installed from '.$lock);
});

it('keeps the graph it measured when asked, and names it either way', function (): void {
    floorStubs($this->dir);
    $saved = $this->dir.'/measured.lock';

    $run = runHarness($this->dir, $this->harness, ['--entries', '25', '--save-lock', $saved]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and((string) File::get($this->dir.'/argv.log'))->toContain('install: resolved fresh')
        ->and($saved)->toBeReadableFile()
        ->and((string) File::get($saved))->toContain('v13.0.0-resolved')
        // Named in the header whether or not it was saved, so a pasted result still says what it measured.
        ->and($run->getOutput())->toContain('laravel/framework v13.0.0-resolved');
});

it('refuses a --lock that is not a file, rather than resolving fresh and calling it a re-check', function (): void {
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25', '--lock', $this->dir.'/missing.lock']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('which is not a file');
});

it('resolves fresh when told nothing, even if the checkout holds a stale skeleton lock', function (): void {
    /*
     * ⚠️ A LOCK LEFT IN THE CHECKOUT WAS INSTALLED UNDER THE LABEL "RESOLVED FRESH". `skeleton/composer.lock` is
     * ignored, so running Composer in the skeleton leaves one, and `cp -R` carried it into the disposable install
     * — where a run without --lock installed that graph while its header said it had resolved one, and
     * --save-lock preserved it as new. Codex found it on #126. Run from a scratch copy of the repository, so the
     * stale lock is planted there and never in the real checkout.
     */
    floorStubs($this->dir);

    $repo = $this->dir.'/repo';
    foreach (['bin', 'skeleton/database', 'skeleton/bootstrap/cache', 'packages/core'] as $path) {
        File::makeDirectory($repo.'/'.$path, 0755, true);
    }
    File::copy($this->harness, $repo.'/bin/benchmark-floor.sh');
    File::copy(dirname($this->harness).'/benchmark-floor.Dockerfile', $repo.'/bin/benchmark-floor.Dockerfile');
    chmod($repo.'/bin/benchmark-floor.sh', 0755);
    File::put($repo.'/skeleton/composer.json', '{"name":"kitsune/kitsune"}');
    File::put($repo.'/packages/core/composer.json', '{"name":"kitsune/core"}');
    File::put($repo.'/skeleton/composer.lock', (string) json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v1.0.0-stale']]]));

    $run = runHarness($this->dir, $repo.'/bin/benchmark-floor.sh', ['--entries', '25']);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and((string) File::get($this->dir.'/argv.log'))->toContain('install: resolved fresh')
        ->and($run->getOutput())->not->toContain('v1.0.0-stale');
});

it('builds the floor interpreter from the committed Dockerfile when no image is named', function (): void {
    /*
     * ⚠️ THE OFFICIAL IMAGE CANNOT RUN THE APPLICATION. `php:8.4-cli` loads neither ext-intl (filament/support) nor
     * ext-zip (openspout/openspout), so the default is now the floor's own interpreter, built from
     * bin/benchmark-floor.Dockerfile — and every measuring run uses the image that build tagged.
     */
    floorStubs($this->dir);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25']);

    $argv = (string) File::get($this->dir.'/argv.log');

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($argv)->toContain('build -q -t kitsune-floor:php8.4 -')
        ->and($argv)->toMatch('/benchmark-floor.*kitsune-floor:php8\.4|kitsune-floor:php8\.4.*benchmark-floor/');
});

it('resolves the graph for the image\'s PHP, not this host\'s, before the install', function (): void {
    /*
     * ⚠️ COMPOSER RUNS ON THE HOST. Left alone it resolves for the host's PHP, so a host on 8.5 measuring an 8.4 image
     * could install a release the image cannot run (Codex, #126). The image's version is read first and handed to
     * Composer as its platform, and `platform-check` turned on, both before `composer install` runs.
     */
    floorStubs($this->dir);
    runHarness($this->dir, $this->harness, ['--entries', '25']);

    $lines = explode("\n", (string) File::get($this->dir.'/argv.log'));
    $at = static fn (string $needle): int|false => array_key_first(array_filter($lines, static fn (string $line): bool => str_contains($line, $needle)));

    expect($at('platform.php 8.4.25'))->not->toBeNull()
        ->and($at('platform-check true'))->not->toBeNull()
        ->and($at('platform.php 8.4.25'))->toBeLessThan($at('composer install'))
        ->and($at('platform-check true'))->toBeLessThan($at('composer install'));
});

it('refuses an image that cannot run what was installed, naming what it lacks', function (): void {
    // The floor was measured for eleven days on such an image, because the benchmark's samples never call intl or zip.
    floorStubs($this->dir, platformFails: true);
    $run = runHarness($this->dir, $this->harness, ['--entries', '25', '--image', 'php:8.4-cli']);

    expect($run->isSuccessful())->toBeFalse('an image missing required extensions was measured')
        ->and($run->getErrorOutput())->toContain('cannot run the installed application')
        ->and($run->getErrorOutput())->toContain('intl, zip');
});
