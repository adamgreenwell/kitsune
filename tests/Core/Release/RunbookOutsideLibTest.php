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
 * The one copy of the helpers the outside families share — deploy/runbook/outside/lib.php (issue #111).
 *
 * ⚠️ WHAT THIS FILE IS ACTUALLY FOR. CLAUDE.md's rule is one file, one source of truth, and this project
 * has already spent time reconciling exactly the drift that two copies produce. Every outside family needs
 * the same verdict protocol, the same bounded `run()`, the same way of driving an instrument and the same
 * reading of `nginx -T`; a second copy that diverged would make two families disagree about what a refusal
 * or a sentinel is, and the completeness gate would report the difference as a host problem.
 *
 * ⚠️ AND WHY ONE FAMILY CARRIES THE BYTES RATHER THAN REQUIRING THEM. RunbookTunnelLogTest mutates
 * tunnel-log.php's source and runs the copy from outside the runbook tree — from the fixture root, and
 * from a fixture runbook with no outside/lib.php beside it — which is how it proves that family's refusal
 * guards. A sibling `require` cannot resolve there, and that test is not this change's to edit. So
 * tunnel-log.php carries the block inline, throttle.php loads it, and the cases below make the two
 * impossible to drift apart: change a byte in either and this says which file has to follow.
 *
 * Needs only PHP, so it holds invariant 11: no services, no network, no Docker.
 */

const RUNBOOK_LIB_OPEN = '// --- the shared outside helpers ---';
const RUNBOOK_LIB_CLOSE = '// --- end of the shared outside helpers ---';

beforeEach(function (): void {
    $this->runbook = dirname(__DIR__, 3).'/deploy/runbook';
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-lib-'.bin2hex(random_bytes(6));

    File::makeDirectory($this->dir, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * The shared block a file carries, between its markers — or an empty string when it carries none.
 *
 * The markers are matched at the start of a line, so the sentence in the block that names them cannot be
 * mistaken for one.
 */
function runbookSharedBlock(string $source): string
{
    $open = strpos($source, "\n".RUNBOOK_LIB_OPEN);
    $close = strpos($source, "\n".RUNBOOK_LIB_CLOSE);

    if ($open === false || $close === false || $close < $open) {
        return '';
    }

    $end = strpos($source, "\n", $close + 1);

    return substr($source, $open + 1, ($end === false ? strlen($source) : $end) - $open - 1);
}

/**
 * Every outside script that is a family: all of them but the library itself.
 *
 * ⚠️ FOUND HERE RATHER THAN BORROWED FROM RunbookManifestTest, whose helpers exist only when that file is
 * the one being run — a case that passes in the full suite and fails on its own is worse than a second
 * two-line list. What matters is that this one is derived from the directory, so a family added to it is
 * held to the block without anybody remembering to add it here.
 *
 * @return array<string, string>
 */
function runbookOutsideFamilies(): array
{
    $families = [];

    foreach (glob(dirname(__DIR__, 3).'/deploy/runbook/outside/*.php') ?: [] as $script) {
        if (basename($script) !== 'lib.php') {
            $families[pathinfo($script, PATHINFO_FILENAME)] = $script;
        }
    }

    return $families;
}

it('is valid PHP', function (): void {
    foreach (['outside/lib.php', 'outside/throttle.php', 'host/throttle-store.php'] as $script) {
        $check = new Process(['php', '-l', dirname(__DIR__, 3).'/deploy/runbook/'.$script]);
        $check->run();

        expect($check->getExitCode())->toBe(0, $script.': '.$check->getOutput().$check->getErrorOutput());
    }
});

it('holds every family that carries the shared helpers byte-identical to the one copy', function (): void {
    /*
     * ⚠️ BYTE FOR BYTE, NOT "ROUGHLY THE SAME". The point of the block is that a family's idea of a
     * refusal, a sentinel and a bounded command is the runbook's idea of them. A copy that had drifted by a
     * word in a reason, or by a guard, would still look like a copy to any looser comparison — and the two
     * families would then disagree about the protocol run.sh judges them on.
     */
    $lib = File::get(dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php');
    $block = runbookSharedBlock($lib);

    expect($block)->not->toBe('', 'outside/lib.php carries no marked shared block')
        ->and($block)->toContain('function verdict(')
        ->and($block)->toContain('function sentinel(')
        ->and($block)->toContain('function run(')
        // What counts as a site is shared too: two families that answered that differently measured
        // different hosts through the same hostnames and reported the difference as the host's doing.
        ->and($block)->toContain('function siteRoots(')
        ->and($block)->toContain('function servedSites(');

    $loaders = [];
    $carriers = [];

    foreach (runbookOutsideFamilies() as $family => $script) {
        $source = File::get($script);
        $carried = runbookSharedBlock($source);

        if (str_contains($source, "require_once __DIR__.'/lib.php';")) {
            $loaders[] = $family;

            // A family that loads the library must not also carry a copy of it: that copy is the drift.
            expect($carried)->toBe('', "[{$family}] loads lib.php and also carries a copy of the shared block");

            continue;
        }

        $carriers[] = $family;

        expect($carried)->toBe($block, "[{$family}] carries a shared block that is not lib.php's, so the two have drifted");
    }

    // Neither list may be empty, or this passes by checking nothing.
    expect($loaders)->not->toBe([])
        ->and($carriers)->not->toBe([]);
});

it('cannot run a family that loads the shared helpers without them', function (): void {
    /*
     * ⚠️ THE LOAD IS REAL, NOT DECORATIVE. Without this, "throttle.php requires lib.php" is a line of source
     * nobody has ever seen matter: the family could carry its own copies of every helper and the require
     * would be dead. Run from a directory with no lib.php beside it, the family must not run at all.
     */
    File::copy(dirname(__DIR__, 3).'/deploy/runbook/outside/throttle.php', $this->dir.'/throttle.php');

    $run = new Process(
        ['php', $this->dir.'/throttle.php', '--host', 'forge@fixture', '--expect', 'tunnel'],
        $this->dir,
        ['HOME' => (string) getenv('HOME'), 'TMPDIR' => $this->dir],
    );
    $run->setTimeout(60);
    $run->run();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('lib.php')
        ->and($run->getOutput())->not->toContain('VERDICT ')
        ->and($run->getOutput())->not->toContain('SENTINEL ');
});

it('gives both outside families one answer to which of a host\'s hostnames a run is about', function (): void {
    /*
     * ⚠️ server_name IS NOT A LIST OF HOSTNAMES, and both outside families request every name this returns.
     * `_` is the catch-all's own name; `*.x`, `.x` and `~^…$` are patterns. None of them resolves — real
     * curl answers `curl: (3) URL rejected: Bad hostname` — and a wildcard sorts before every letter, so
     * one wildcard vhost made the first request of every run a failure and voided the family while naming
     * the operator's own nginx as the reason. A wildcard subdomain is part of the planned alpha bring-up.
     *
     * The patterns come back rather than being dropped, so a family can say what it skipped instead of
     * reporting that a host naming a wildcard names no site at all — a true verdict with a false reason.
     *
     * ⚠️ AND A HOSTNAME THAT SERVES NO APPLICATION IS THE SAME KIND OF ENTRY. A `www`→apex redirect vhost —
     * which Forge writes from its own UI, so the alpha host will have one — declares no server-level root
     * ending in `/public`. The two families used to meet it separately and get it wrong in two different
     * ways: the throttle family refused to name the release at all, and the tunnel family requested it and
     * voided on the 301 it answers with. Split here, in the one copy, they cannot disagree about which
     * hostnames a run is about; requesting a name is the caller's business, deciding it is a site is not.
     *
     * The roots come back per hostname because a caller needs them — the throttle family names the release
     * from them — and a `root` inside a `location` is that location's, not the site's.
     */
    File::put($this->dir.'/dump', <<<'CONF'
    server {
        server_name _;
        root /var/www/html;
    }
    server {
        listen 80;
        server_name stage.kitsune.test alias.kitsune.test;
        return 301 https://$host$request_uri;
    }
    server {
        listen 443 ssl;
        server_name stage.kitsune.test alias.kitsune.test;
        root /home/kitsune/site/current/public;
        location /assets {
            root /var/www/shared-assets;
        }
    }
    server {
        server_name www.stage.kitsune.test;
        return 301 https://stage.kitsune.test$request_uri;
    }
    server {
        server_name *.kitsune.test .kitsune.test ~^(?<sub>.+)\.kitsune\.test$;
    }
    CONF);

    // `nginx -T` is whatever this answers with; the helper's own parsing is what is under test.
    File::put($this->dir.'/ssh', "#!/bin/sh\ncat ".escapeshellarg($this->dir.'/dump')."\n");
    chmod($this->dir.'/ssh', 0755);

    $harness = $this->dir.'/sites.php';
    File::put($harness, <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once getenv('KITSUNE_LIB');

    const FAMILY = 'harness';
    const CHECKS = ['HRN-1'];

    [$dump, $unreadable] = nginxDump('forge@fixture');
    [$named, $patterns] = siteRoots($dump);
    [$served, $rootless] = servedSites($named);

    echo 'NAMED '.implode(' ', array_keys($named))."\n";
    echo 'SERVED '.implode(' ', array_keys($served))."\n";
    echo 'ROOTS '.implode(' ', $served['stage.kitsune.test'] ?? [])."\n";
    echo 'ROOTLESS '.implode('; ', $rootless)."\n";
    echo 'PATTERNS '.implode(' ', $patterns)."\n";
    echo 'UNREADABLE ['.$unreadable."]\n";
    PHP);

    $run = new Process(['php', $harness], $this->dir, [
        'HOME' => (string) getenv('HOME'),
        'PATH' => $this->dir.':'.getenv('PATH'),
        'KITSUNE_LIB' => dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php',
    ]);
    $run->run();

    expect($run->getOutput())->toBe(implode("\n", [
        'NAMED alias.kitsune.test stage.kitsune.test www.stage.kitsune.test',
        'SERVED alias.kitsune.test stage.kitsune.test',
        'ROOTS /home/kitsune/site/current/public',
        'ROOTLESS [www.stage.kitsune.test] has no server-level root ending in /public in the running '
            .'configuration (it declares none)',
        'PATTERNS *.kitsune.test .kitsune.test ~^(?<sub>.+)\.kitsune\.test$',
        'UNREADABLE []',
        '',
    ]), $run->getErrorOutput());
});

it('keeps the protocol the shared helpers print exactly as the gate reads it', function (): void {
    /*
     * The four lines run.sh and common.sh agree on, printed by the one copy: a verdict starts its line, a
     * refusal names its family, a sentinel names its family and counts its own ids, and a record is never
     * a verdict. A family is judged on these bytes, so they are asserted as bytes rather than as behaviour
     * seen through a whole family.
     */
    $harness = $this->dir.'/harness.php';
    File::put($harness, <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once getenv('KITSUNE_LIB');

    const FAMILY = 'harness';
    const CHECKS = ['HRN-1', 'HRN-2'];

    $verdicts = [];
    record('HRN-1', "a fact\nover two lines");
    verdict('HRN-1', 'PASS', "a reason\nover two lines", $verdicts);
    verdict('HRN-2', 'FAIL', 'a plain reason', $verdicts);
    sentinel(FAMILY, $verdicts);

    try {
        verdict('HRN-2', 'VOID', 'a second verdict', $verdicts);
    } catch (LogicException) {
        echo "held\n";
    }

    try {
        verdict('HRN-9', 'PASS', 'an undeclared id', $verdicts);
    } catch (LogicException) {
        echo "held\n";
    }
    PHP);

    $run = new Process(['php', $harness], $this->dir, [
        'HOME' => (string) getenv('HOME'),
        'KITSUNE_LIB' => dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php',
    ]);
    $run->run();

    expect($run->getOutput())->toBe(implode("\n", [
        'RECORD HRN-1 a fact over two lines',
        'VERDICT HRN-1 PASS a reason over two lines',
        'VERDICT HRN-2 FAIL a plain reason',
        'SENTINEL harness 2 HRN-1 HRN-2',
        'REFUSED harness verdict HRN-2 VOID [a second verdict]: emitted twice',
        'held',
        'REFUSED harness verdict HRN-9 PASS [an undeclared id]: this family did not declare that id',
        'held',
        '',
    ]), $run->getErrorOutput());
});
