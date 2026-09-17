<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * .github/scripts/ci-scope.sh decides what one CI run needs, and every matrix in ci.yml consumes it.
 *
 * ⚠️ IT IS TESTED BECAUSE THE FAILURE IS SILENT AND EXPENSIVE IN BOTH DIRECTIONS. Too wide and the
 * narrowing bought nothing; too narrow and a change reaches main having been measured on one engine
 * while the run still reports green. Neither shows up as a failure — the first as a bill, the second
 * as a defect somebody finds later — so the only place this can be caught is here.
 *
 * The script's own reason for existing is the billing: a run is 13 jobs and 32-47 billable minutes,
 * and a merged pull request paid for two of them.
 */

beforeEach(function (): void {
    $this->script = dirname(__DIR__, 3).'/.github/scripts/ci-scope.sh';
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-ci-scope-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** Run the decision with a stubbed file lister, and read what it wrote to GITHUB_OUTPUT. */
function scopeRun(string $dir, string $script, array $env = [], ?array $files = null): array
{
    $output = $dir.'/output';
    File::put($output, '');

    if ($files !== null) {
        // ⚠️ A STUB THAT PRINTS, NOT A MOCK OF THE ANSWER. The script splits the list itself, so the
        // stub hands it the same shape `gh --jq .[].filename` does: one path per line, nothing else.
        $listing = $dir.'/files';
        File::put($listing, implode("\n", $files)."\n");
        $env['FILES_CMD'] = 'cat '.escapeshellarg($listing);
    }

    $process = Process::fromShellCommandline('bash '.escapeshellarg($script), $dir, array_replace([
        'PATH' => getenv('PATH'),
        'EVENT' => 'pull_request',
        'REPO' => 'adamgreenwell/kitsune',
        'NUMBER' => '123',
        'GITHUB_OUTPUT' => $output,
    ], $env));

    $process->setTimeout(30);
    $process->run();

    $parsed = [];

    foreach (explode("\n", (string) File::get($output)) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $parsed[$key] = $value;
        }
    }

    return [$parsed, $process];
}

it('is valid bash', function (): void {
    $check = Process::fromShellCommandline('bash -n '.escapeshellarg($this->script));
    $check->run();

    expect($check->isSuccessful())->toBeTrue($check->getErrorOutput());
});

it('narrows to one lane when every changed file is prose', function (): void {
    [$out] = scopeRun($this->dir, $this->script, [], [
        'docs/decision-log.md',
        'docs/roadmap.md',
        'README.md',
    ]);

    expect($out['docs_only'])->toBe('true')
        ->and($out['php'])->toBe('["8.4"]')
        ->and($out['engine'])->toBe('["sqlite"]');
});

it('runs everything when one changed file is not prose', function (string $file): void {
    // ⚠️ ONE FILE IS ENOUGH, and the prose around it must not dilute that. A pull request that edits
    // an ADR and the code it describes is a code change; reading it the other way is how a schema
    // change would be measured on SQLite alone and still report green.
    [$out] = scopeRun($this->dir, $this->script, [], ['docs/decision-log.md', $file, 'README.md']);

    expect($out['docs_only'])->toBe('false')
        ->and($out['php'])->toBe('["8.4","8.5"]')
        ->and($out['engine'])->toBe('["sqlite","pgsql","mysql","mariadb"]');
})->with([
    'a package source file' => 'packages/core/src/Models/Entry.php',
    'a migration' => 'packages/core/database/migrations/0001_01_01_000001_create_kitsune_schema_tables.php',
    'a test' => 'tests/Core/Tenancy/ScopeDeclarationTest.php',
    'the workflow itself' => '.github/workflows/ci.yml',
    'this very script' => '.github/scripts/ci-scope.sh',
    'a composer manifest' => 'composer.json',
    'a runbook script' => 'deploy/runbook/run.sh',
    'a dotfile with no extension' => '.gitattributes',
    'a markdown-looking directory' => 'src/md/Thing.php',
]);

it('treats a docs path and a markdown file anywhere as prose', function (string $file): void {
    [$out] = scopeRun($this->dir, $this->script, [], [$file]);

    expect($out['docs_only'])->toBe('true');
})->with([
    'the decision log' => 'docs/decision-log.md',
    'a nested docs file' => 'docs/wiki/Project-Status.md',
    'a non-markdown file under docs' => 'docs/brand/palette.txt',
    'a markdown file at the root' => 'CONTRIBUTING.md',
    'a markdown file beside code' => 'deploy/runbook/README.md',
]);

it('runs everything when the file listing fails, rather than guessing', function (): void {
    /*
     * ⚠️ THE FAIL-CLOSED RULE, AND THE ONE THE SCRIPT IS BUILT AROUND. An API that will not answer is
     * not evidence that a change is prose. Under `set -e` a bare `files=$(failing)` would have ended
     * the script and taken the whole run's decision with it, which is why the call is guarded.
     */
    [$out, $process] = scopeRun($this->dir, $this->script, ['FILES_CMD' => 'exit 22']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($out['docs_only'])->toBe('false')
        ->and($out['engine'])->toBe('["sqlite","pgsql","mysql","mariadb"]')
        ->and($process->getErrorOutput())->toContain('could not be listed');
});

it('runs everything when the listing is empty', function (): void {
    [$out] = scopeRun($this->dir, $this->script, ['FILES_CMD' => 'true']);

    expect($out['docs_only'])->toBe('false')
        ->and($out['engine'])->toBe('["sqlite","pgsql","mysql","mariadb"]');
});

it('runs everything when the pull request cannot be identified', function (array $env): void {
    [$out] = scopeRun($this->dir, $this->script, $env, ['docs/decision-log.md']);

    expect($out['docs_only'])->toBe('false')
        ->and($out['engine'])->toBe('["sqlite","pgsql","mysql","mariadb"]');
})->with([
    'no number' => [['NUMBER' => '']],
    'no repository' => [['REPO' => '']],
]);

it('is the merge check on a push to main, not the proof', function (): void {
    // The tree was already proved by the pull request that produced it, on the same content.
    [$out] = scopeRun($this->dir, $this->script, ['EVENT' => 'push']);

    expect($out['docs_only'])->toBe('false')
        ->and($out['php'])->toBe('["8.4"]')
        ->and($out['engine'])->toBe('["sqlite"]');
});

it('refuses to decide without an event', function (): void {
    $process = Process::fromShellCommandline('bash '.escapeshellarg($this->script), $this->dir, [
        'PATH' => getenv('PATH'),
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('EVENT is required');
});

it('is what the workflow actually runs, and every matrix consumes it', function (): void {
    /*
     * ⚠️ THE TEST ABOVE PROVES THE SCRIPT; THIS ONE PROVES IT IS WIRED IN. A correct decision nothing
     * reads is the same as no decision, and that seam is invisible from either side alone.
     */
    $workflow = File::get(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

    expect($workflow)->toContain('bash .github/scripts/ci-scope.sh')
        ->and($workflow)->toContain('php: ${{ fromJSON(needs.scope.outputs.php) }}')
        ->and($workflow)->toContain('engine: ${{ fromJSON(needs.scope.outputs.engine) }}')
        ->and(substr_count($workflow, "if: needs.scope.outputs.docs_only != 'true'"))->toBe(2)
        ->and(substr_count($workflow, 'needs: scope'))->toBe(3);

    // The engine lane that a prose change still runs has to be one the narrow matrix names.
    expect($workflow)->toContain('engine: ${{ fromJSON(needs.scope.outputs.engine) }}');
});
