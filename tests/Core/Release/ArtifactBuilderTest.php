<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/kitsune-artifact-build-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir.'/source/packages/core', 0700, true);
    File::makeDirectory($this->dir.'/source/packages/person', 0700, true);
    File::makeDirectory($this->dir.'/source/skeleton', 0700, true);
    File::makeDirectory($this->dir.'/source/.github/workflows', 0700, true);
    foreach (['core', 'person'] as $package) {
        File::put($this->dir.'/source/packages/'.$package.'/composer.json', json_encode(['name' => 'kitsune/'.$package], JSON_THROW_ON_ERROR));
    }
    File::put($this->dir.'/source/skeleton/composer.json', '{"name":"kitsune/kitsune","require":{"kitsune/person":"@dev"}}');
    File::put($this->dir.'/source/.github/workflows/split-packages.yml', "matrix:\n  include:\n    - package: core\n      repository: core\n");
    foreach ([['init', '--quiet'], ['add', '--all'], ['-c', 'user.name=Artifact Test', '-c', 'user.email=artifact@kitsune.test', 'commit', '--quiet', '-m', 'Test source.']] as $arguments) {
        (new Process(['git', ...$arguments], $this->dir.'/source'))->mustRun();
    }
    $git = new Process(['git', 'rev-parse', 'HEAD'], $this->dir.'/source');
    $git->mustRun();
    $this->commit = trim($git->getOutput());
    File::put($this->dir.'/fixture.tar', 'fixture');
    $this->plan = ['artifacts' => [['path' => $this->dir.'/fixture.tar', 'role' => 'provenance', 'platform' => 'any']], 'packages' => []];
    foreach (['core' => 'split', 'person' => 'bundled', 'kitsune' => 'bundled'] as $package => $publication) {
        $this->plan['packages']['kitsune/'.$package] = ['version' => 'dev-'.$this->commit, 'publication' => $publication];
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function artifactBuildCommand(string $directory, string $commit, array $plan): Process
{
    File::put($directory.'/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/release-artifacts.php', 'build',
        '--source='.$directory.'/source', '--commit='.$commit, '--version=v1.0.0-test.213', '--channel=test', '--plan='.$directory.'/plan.json']);
    $process->run();

    return $process;
}

it('builds explicit artifact and complete package provenance from Git rather than a dirty checkout', function (): void {
    File::put($this->dir.'/source/packages/person/composer.json', '{"name":"substituted/working-copy"}');
    $process = artifactBuildCommand($this->dir, $this->commit, $this->plan);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain("source\t".$this->commit)
        ->and($process->getOutput())->toContain("package\tkitsune/person\tdev-".$this->commit)
        ->and($process->getOutput())->not->toContain('substituted/working-copy')
        ->and(substr_count($process->getOutput(), "\npackage\t"))->toBe(3)
        ->and($process->getOutput())->toContain(hash('sha256', 'fixture'));
});

it('refuses incomplete, invented or implicitly published package inputs', function (string $change): void {
    $commit = $this->commit;
    match ($change) {
        'missing package' => (function (): void {
            unset($this->plan['packages']['kitsune/person']);
        })(),
        'invented package' => $this->plan['packages']['kitsune/ghost'] = ['version' => '1.0.0', 'publication' => 'excluded'],
        'unwired publisher' => $this->plan['packages']['kitsune/person']['publication'] = 'split',
        'floating source' => $commit = 'main',
        'missing artifact' => unlink($this->dir.'/fixture.tar'),
    };
    $process = artifactBuildCommand($this->dir, $commit, $this->plan);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('');
})->with(['missing package', 'invented package', 'unwired publisher', 'floating source', 'missing artifact']);

it('does not silently omit a package because its directory spelling differs from its Composer name', function (): void {
    rename($this->dir.'/source/packages/person', $this->dir.'/source/packages/PersonFiles');
    (new Process(['git', 'add', '--all'], $this->dir.'/source'))->mustRun();
    (new Process(['git', '-c', 'user.name=Artifact Test', '-c', 'user.email=artifact@kitsune.test', 'commit', '--quiet', '-m', 'Move package directory.'], $this->dir.'/source'))->mustRun();
    $commit = new Process(['git', 'rev-parse', 'HEAD'], $this->dir.'/source');
    $commit->mustRun();
    foreach ($this->plan['packages'] as &$package) {
        $package['version'] = 'dev-'.trim($commit->getOutput());
    }
    unset($package);
    $process = artifactBuildCommand($this->dir, trim($commit->getOutput()), $this->plan);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain("package\tkitsune/person\t");
});

it('does not let local Git replacement refs substitute a tree or package manifest under the requested commit', function (string $replacement): void {
    $source = $this->dir.'/source';
    $git = function (array $arguments) use ($source): string {
        $process = new Process(['git', ...$arguments], $source);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $tree = $git(['rev-parse', $this->commit.'^{tree}']);
    $original = File::get($source.'/packages/person/composer.json');
    $blob = $git(['rev-parse', $this->commit.':packages/person/composer.json']);
    $substitute = '{"name":"kitsune/person","description":"Local replacement must not enter release provenance"}';
    File::put($source.'/packages/person/composer.json', $substitute);
    $git(['add', '--all']);
    $git(['-c', 'user.name=Artifact Test', '-c', 'user.email=artifact@kitsune.test', 'commit', '--quiet', '-m', 'Local replacement source.']);
    $new = $git(['rev-parse', $replacement === 'commit' ? 'HEAD' : 'HEAD:packages/person/composer.json']);
    $git(['replace', $replacement === 'commit' ? $this->commit : $blob, $new]);

    $process = artifactBuildCommand($this->dir, $this->commit, $this->plan);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain("tree\t".$tree."\n", hash('sha256', $original))
        ->and($process->getOutput())->not->toContain(hash('sha256', $substitute));
})->with(['commit', 'blob']);
