<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The script that publishes a package to its mirror, run for real against a throwaway repository — issue #8.
 *
 * ⚠️ RUN, NOT READ, because reading is how the previous publisher shipped. The workflow's comment described a
 * history-preserving split and its setup instructions described an empty mirror, and both were wrong about what
 * the marketplace action did: following them made the first release publish nothing. Only running the thing found
 * that, so the replacement is run here, on every pull request, against a real bare repository.
 *
 * Needs only `git`, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/kitsune-split-'.bin2hex(random_bytes(6));
    $this->source = $this->dir.'/source';
    $this->mirror = $this->dir.'/mirror.git';

    File::makeDirectory($this->source, 0755, true);

    splitGit($this->source, 'init', '--quiet', '--initial-branch=main');
    splitGit($this->dir, 'init', '--quiet', '--bare', '--initial-branch=main', $this->mirror);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** Run git in a directory with a fixed identity, and return its trimmed output. */
function splitGit(string $cwd, string ...$args): string
{
    $process = new Process(['git', '-c', 'user.name=Split Test', '-c', 'user.email=split@kitsune.test', ...$args], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

/** Write a file into the source repository and commit it; returns the commit. */
function splitCommit(string $source, string $path, string $contents, string $message): string
{
    File::ensureDirectoryExists(dirname($source.'/'.$path));
    File::put($source.'/'.$path, $contents);

    splitGit($source, 'add', '--all');
    splitGit($source, 'commit', '--quiet', '-m', $message);

    return splitGit($source, 'rev-parse', 'HEAD');
}

/** Run the publisher from the source repository, exactly as the workflow does. */
function splitPublish(string $source, string $mirror, string $tag, string $sha): Process
{
    $process = new Process(
        ['bash', dirname(__DIR__, 3).'/.github/scripts/split-package.sh'],
        $source,
        [
            'TAG' => $tag,
            'SOURCE_SHA' => $sha,
            'PREFIX' => 'packages/core',
            'REMOTE' => $mirror,
            'MAIN_REF' => 'main',
            'RETRY_DELAY' => '0',
        ],
    );

    $process->run();

    return $process;
}

/** A ref in the mirror, or null when it does not exist. */
function mirrorRef(string $mirror, string $ref): ?string
{
    $process = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref], $mirror);
    $process->run();

    return $process->isSuccessful() ? trim($process->getOutput()) : null;
}

it('publishes the first release to an empty mirror, with the package at its root', function (): void {
    /*
     * ⚠️ THE P1 THE MARKETPLACE ACTION HAD. On an empty mirror it tried to push a branch with no commits and
     * failed before any tag was written — so the setup this workflow documented published nothing, ever.
     */
    splitCommit($this->source, 'README.md', 'the monorepo', 'Start the monorepo');
    $release = splitCommit($this->source, 'packages/core/src/Core.php', '<?php // one', 'Add core');

    $run = splitPublish($this->source, $this->mirror, 'v0.1.0', $release);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(mirrorRef($this->mirror, 'refs/tags/v0.1.0'))->not->toBeNull()
        ->and(mirrorRef($this->mirror, 'refs/heads/main'))->toBe(mirrorRef($this->mirror, 'refs/tags/v0.1.0'))
        // The package's own files, at the root, and nothing from outside it.
        ->and(splitGit($this->mirror, 'ls-tree', '-r', '--name-only', 'main'))->toBe('src/Core.php');
});

it('carries the package\'s real history, not a snapshot', function (): void {
    /*
     * ⚠️ THE OLD COMMENT SAID "SPLITS" AND THE ACTION SNAPSHOTTED: one bot commit per tag, carrying the first line
     * of the tagged message. The history is what makes consecutive releases fast-forwards of one another, which
     * is what the rest of this script relies on.
     */
    splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    splitCommit($this->source, 'README.md', 'outside the package', 'Touch the monorepo only');
    $release = splitCommit($this->source, 'packages/core/b.php', '<?php // b', 'Add b');

    splitPublish($this->source, $this->mirror, 'v0.1.0', $release)->mustRun();

    expect(splitGit($this->mirror, 'log', '--format=%s', 'main'))->toBe("Add b\nAdd a");
});

it('moves the mirror\'s main forward for a newer release on main', function (): void {
    $first = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    splitPublish($this->source, $this->mirror, 'v0.1.0', $first)->mustRun();

    $second = splitCommit($this->source, 'packages/core/b.php', '<?php // b', 'Add b');
    splitPublish($this->source, $this->mirror, 'v0.2.0', $second)->mustRun();

    expect(mirrorRef($this->mirror, 'refs/heads/main'))->toBe(mirrorRef($this->mirror, 'refs/tags/v0.2.0'));
});

it('leaves the mirror\'s main alone when an older release is published late', function (): void {
    /*
     * ⚠️ EVERY TAG USED TO BECOME MAIN. Publishing an older release after a newer one — a rerun, or two tags pushed
     * in the wrong order — moved dev-main backwards to older code.
     */
    $first = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    $second = splitCommit($this->source, 'packages/core/b.php', '<?php // b', 'Add b');

    splitPublish($this->source, $this->mirror, 'v0.2.0', $second)->mustRun();
    $late = splitPublish($this->source, $this->mirror, 'v0.1.0', $first);

    expect($late->isSuccessful())->toBeTrue($late->getErrorOutput())
        ->and(mirrorRef($this->mirror, 'refs/tags/v0.1.0'))->not->toBeNull()
        ->and(mirrorRef($this->mirror, 'refs/heads/main'))->toBe(mirrorRef($this->mirror, 'refs/tags/v0.2.0'));
});

it('publishes a release that is not on main without touching the mirror\'s main', function (): void {
    /*
     * ⚠️ A MANUAL DISPATCH FROM A BRANCH USED TO PUSH THAT BRANCH TO MAIN. Dispatch is gone from the workflow; the
     * script still refuses to move main for anything main does not contain, whatever triggered it.
     */
    $released = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    splitPublish($this->source, $this->mirror, 'v0.1.0', $released)->mustRun();

    splitGit($this->source, 'checkout', '--quiet', '-b', 'maintenance');
    $branchOnly = splitCommit($this->source, 'packages/core/fix.php', '<?php // fix', 'Fix on a branch');

    $run = splitPublish($this->source, $this->mirror, 'v0.1.1', $branchOnly);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(mirrorRef($this->mirror, 'refs/tags/v0.1.1'))->not->toBeNull()
        ->and(mirrorRef($this->mirror, 'refs/heads/main'))->toBe(mirrorRef($this->mirror, 'refs/tags/v0.1.0'));
});

it('refuses a tag name that is not a version, and runs nothing it contains', function (): void {
    /*
     * ⚠️ GIT ACCEPTS `;` AND `$` IN A REF NAME, and the action put the name into a shell command unescaped, in the
     * job holding the write token. The name is checked before anything else runs, and it only ever travels as
     * an environment variable.
     */
    $release = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    $marker = $this->dir.'/injected';

    $run = splitPublish($this->source, $this->mirror, "v1.0.0;touch {$marker}", $release);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to publish')
        ->and(file_exists($marker))->toBeFalse()
        ->and(splitGit($this->mirror, 'for-each-ref'))->toBe('');
});

it('refuses a tag Composer could not resolve, or that is not a canonical release name', function (string $tag): void {
    /*
     * ⚠️ A NAME GIT ACCEPTS IS NOT A VERSION PACKAGIST CAN INSTALL — review found the first pattern accepting
     * `v1.0.0-01` and `v1.0.0-.foo`, which Composer's `VersionParser::normalize()` rejects. Published, such a tag
     * would reach the mirror and could move its main while no host could require the release.
     *
     * The rest are forms Composer tolerates and this refuses on purpose — a leading zero, a missing patch number,
     * no `v`, a lowercase `rc` — so a release has one spelling.
     */
    $release = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');

    $run = splitPublish($this->source, $this->mirror, $tag, $release);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('Refusing to publish')
        ->and(splitGit($this->mirror, 'for-each-ref'))->toBe('');
})->with(['v1.0.0-01', 'v1.0.0-.foo', 'v1.0.0-foo', 'v01.0.0', 'v1.0', '1.0.0', 'v1.0.0-rc.1']);

it('publishes a pre-release in a form Composer resolves', function (string $tag): void {
    /*
     * ⚠️ AND EVERYTHING IT ACCEPTS, COMPOSER ACCEPTS — asserted against Composer's own parser, not a second
     * description of it. The allowed forms are narrower than Composer's; this is what keeps them a subset.
     */
    $release = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');

    $run = splitPublish($this->source, $this->mirror, $tag, $release);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(mirrorRef($this->mirror, "refs/tags/{$tag}"))->not->toBeNull()
        ->and((new VersionParser)->normalize($tag))->toBeString();
})->with(['v1.0.0-alpha.1', 'v1.0.0-beta2', 'v1.0.0-RC.1']);

it('refuses to overwrite a mirror whose main holds history of its own', function (): void {
    /*
     * ⚠️ THE PUSH IS NEVER FORCED, so a mirror created with a README — which the old instructions warned against
     * for the wrong reason — is refused loudly rather than replaced. The tag still lands, because a release is
     * a release; main is what the mirror's own history protects.
     */
    $foreign = $this->dir.'/foreign';
    File::makeDirectory($foreign);
    splitGit($foreign, 'init', '--quiet', '--initial-branch=main');
    splitCommit($foreign, 'README.md', 'created on GitHub', 'Initial commit');
    splitGit($foreign, 'push', '--quiet', $this->mirror, 'main');

    $before = mirrorRef($this->mirror, 'refs/heads/main');

    $release = splitCommit($this->source, 'packages/core/a.php', '<?php // a', 'Add a');
    $run = splitPublish($this->source, $this->mirror, 'v0.1.0', $release);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('must start empty')
        ->and(mirrorRef($this->mirror, 'refs/heads/main'))->toBe($before);
});
