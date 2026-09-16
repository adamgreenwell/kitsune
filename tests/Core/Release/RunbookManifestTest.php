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
 * The runbook's completeness gate — deploy/runbook/run.sh with deploy/runbook/host/common.sh — run for
 * real against a fixture tree, with ssh stubbed on PATH (issue #111).
 *
 * ⚠️ WHAT THIS FILE IS ACTUALLY FOR. Every other check in the runbook reports on a host. This one
 * reports on the runbook: that a family which never runs, or dies halfway, cannot be mistaken for a
 * host with nothing wrong. The gate is the reason the other checks can be trusted, so the cases below
 * are all shapes of silence — a promise with no verdict, a stream cut before its sentinel, a family
 * that was never wired in — and each must end in a non-zero exit.
 *
 * ⚠️ RUN, NOT READ, for the reason ReleaseScriptTest gives: the real run.sh and the real common.sh are
 * copied into the fixture tree byte for byte, so what the tests exercise is what ships.
 *
 * Needs only bash and PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-runbook-'.bin2hex(random_bytes(6));
    $this->runbook = $this->dir.'/runbook';

    File::makeDirectory($this->runbook.'/host', 0755, true);
    File::makeDirectory($this->dir.'/bin');

    // The real files, unchanged: the gate that ships is the gate under test.
    File::copy($repo.'/deploy/runbook/run.sh', $this->runbook.'/run.sh');
    File::copy($repo.'/deploy/runbook/host/common.sh', $this->runbook.'/host/common.sh');

    File::put($this->dir.'/bin/ssh', runbookSshStub());
    chmod($this->dir.'/bin/ssh', 0755);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * A stand-in for ssh that runs the piped stream locally instead of on a host.
 *
 * run.sh sends `common.sh` and the family concatenated on stdin and ends its argument list with the
 * topology, so the stub runs stdin as bash with that last argument. With STUB_SSH_FAIL it consumes the
 * stream and exits 255 instead, which is what a dropped connection looks like from run.sh's side.
 */
function runbookSshStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    if [[ "${STUB_SSH_FAIL:-}" == 1 ]]; then
      cat > /dev/null
      echo "kex_exchange_identification: Connection closed by remote host" >&2
      exit 255
    fi

    exec bash -s -- "${@: -1}"

    BASH;
}

/** Write a manifest of "<topology> <family> <check-id>" rows. */
function runbookManifest(string $runbook, string $rows): void
{
    File::put($runbook.'/manifest.txt', "# a fixture manifest\n".$rows);
}

/** Write a family script that sources nothing: common.sh arrives ahead of it in the same stream. */
function runbookFamily(string $runbook, string $name, string $body): void
{
    File::put($runbook.'/host/'.$name.'.sh', "set -euo pipefail\n".$body);
}

/**
 * Run the gate against the fixture tree.
 *
 * @param  array<string, string|false>  $env
 */
function runbookRun(string $dir, array $env = [], string $expect = 'tunnel'): Process
{
    $process = new Process(
        ['bash', $dir.'/runbook/run.sh', '--host', 'forge@fixture', '--expect', $expect],
        $dir,
        array_replace([
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => $dir.'/bin:'.getenv('PATH'),
        ], $env),
    );

    $process->setTimeout(60);
    $process->run();

    return $process;
}

it('is valid bash', function (): void {
    foreach (['deploy/runbook/run.sh', 'deploy/runbook/host/common.sh'] as $script) {
        $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/'.$script]);
        $check->run();

        expect($check->getExitCode())->toBe(0, $script.': '.$check->getErrorOutput())
            ->and($check->getErrorOutput())->toBe('');
    }
});

it('refuses a manifest that promises nothing, because a run could not prove anything', function (): void {
    /*
     * ⚠️ THE EMPTY PROMISE IS THE VACUOUS RUN. With nothing expected, every check could be missing and
     * the sum over arrived verdicts would still be "no failures". The committed manifest is in exactly
     * this state until a family lands, so this is the shipped behaviour, not a hypothetical.
     */
    runbookManifest($this->runbook, "dns-only other OTHER-1\n");

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('promises no checks for topology [tunnel]');
});

it('refuses a family it promises but does not have', function (): void {
    runbookManifest($this->runbook, "tunnel absent ABS-1\n");

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the manifest promises the family [absent]')
        ->and($run->getErrorOutput())->toContain('absent.sh does not exist');
});

it('passes only when every promised check reports PASS', function (): void {
    runbookManifest($this->runbook, "tunnel good G-1\ntunnel good G-2\n");
    runbookFamily($this->runbook, 'good', <<<'BASH'
    family good G-1 G-2
    verdict G-1 PASS "the first condition holds"
    verdict G-2 PASS "the second condition holds"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getOutput())->toContain('PASS  G-1')
        ->and($run->getOutput())->toContain('PASS  G-2')
        ->and($run->getOutput())->toContain('2 passed, 0 failed, 0 could not be measured')
        ->and($run->getOutput())->toContain('Every promised check passed.');
});

it('fails the run on a FAIL, and says the host was not shown to hold the conditions', function (): void {
    runbookManifest($this->runbook, "tunnel mixed M-1\ntunnel mixed M-2\n");
    runbookFamily($this->runbook, 'mixed', <<<'BASH'
    family mixed M-1 M-2
    verdict M-1 PASS "this one holds"
    verdict M-2 FAIL "another process holds a loopback socket to 443"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('FAIL  M-2 — another process holds a loopback socket to 443')
        ->and($run->getOutput())->toContain('1 passed, 1 failed, 0 could not be measured')
        ->and($run->getErrorOutput())->toContain('has NOT been shown to hold');
});

it('voids a promised check that never reports, and exits non-zero for it', function (): void {
    /*
     * ⚠️ THE CHECK THAT WAS NEVER WIRED IN. The family runs, succeeds, and simply never mentions Q-2 —
     * which without the manifest would be indistinguishable from Q-2 having nothing to report.
     */
    runbookManifest($this->runbook, "tunnel quiet Q-1\ntunnel quiet Q-2\n");
    runbookFamily($this->runbook, 'quiet', <<<'BASH'
    family quiet Q-1 Q-2
    verdict Q-1 PASS "the only condition this family got round to"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  Q-2 (quiet) — promised by the manifest, and no verdict arrived')
        ->and($run->getOutput())->toContain('1 passed, 0 failed, 1 could not be measured');
});

it('voids a whole family whose stream was cut before its sentinel', function (): void {
    /*
     * ⚠️ SIGKILL SKIPS THE EXIT TRAP, which is exactly what a dropped connection or an OOM kill does to
     * a family halfway through: the verdicts it already emitted arrive, and nothing says the rest never
     * will. Without the sentinel rule, T-1's PASS would be the whole story and the run would exit 0.
     */
    runbookManifest($this->runbook, "tunnel cut T-1\ntunnel cut T-2\n");
    runbookFamily($this->runbook, 'cut', <<<'BASH'
    family cut T-1 T-2
    verdict T-1 PASS "measured before the stream was cut"
    kill -KILL $$
    verdict T-2 PASS "never reached"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  T-1 (cut) — the family produced no sentinel')
        ->and($run->getOutput())->toContain('VOID  T-2 (cut) — the family produced no sentinel')
        ->and($run->getOutput())->toContain('0 passed, 0 failed, 2 could not be measured');
});

it('voids every promised check when the family could not be reached at all', function (): void {
    runbookManifest($this->runbook, "tunnel good G-1\n");
    runbookFamily($this->runbook, 'good', <<<'BASH'
    family good G-1
    verdict G-1 PASS "never runs: ssh fails first"
    BASH);

    $run = runbookRun($this->dir, ['STUB_SSH_FAIL' => '1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  G-1 (good) — the family produced no sentinel')
        ->and($run->getErrorOutput())->toContain('the family did not finish')
        ->and($run->getErrorOutput())->toContain('Connection closed by remote host');
});

it('reports a sentinel that counts more verdicts than arrived', function (): void {
    /*
     * A family that hand-writes its own sentinel — or a stream spliced by something in between — can
     * claim more than it delivered. The gate still voids the missing id; this asserts the discrepancy
     * is named rather than passed over.
     */
    runbookManifest($this->runbook, "tunnel claims C-1\ntunnel claims C-2\n");
    runbookFamily($this->runbook, 'claims', <<<'BASH'
    printf 'VERDICT C-1 PASS only one of the two arrived\n'
    printf 'SENTINEL claims 2 C-1 C-2\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('its sentinel counted 2 verdicts and 1 arrived')
        ->and($run->getOutput())->toContain('VOID  C-2');
});

it('refuses a manifest row carrying a fourth field', function (): void {
    /*
     * ⚠️ FOUND BY WRITING THIS FILE. A stray word in a fixture row — `tunnel good G-1 GOOD` — made
     * `read` fold it into the id, promising "G-1 GOOD", which no verdict could match. The run reported
     * the host unmeasurable for a typo in the promise, so the row is refused instead.
     */
    runbookManifest($this->runbook, "tunnel good G-1 GOOD\n");
    runbookFamily($this->runbook, 'good', <<<'BASH'
    family good G-1
    verdict G-1 PASS "never reached: the manifest is refused first"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('more than three fields')
        ->and($run->getOutput())->not->toContain('PASS  G-1');
});

it('refuses a verdict for an id the family never declared', function (): void {
    /*
     * common.sh's own guard: a check id that is not in the family's declared list cannot be emitted,
     * so the manifest, the family's promise and the sentinel cannot drift apart silently.
     */
    runbookManifest($this->runbook, "tunnel stray S-1\n");
    runbookFamily($this->runbook, 'stray', <<<'BASH'
    family stray S-1
    verdict S-9 PASS "an id this family never promised"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('this family did not declare that id')
        ->and($run->getOutput())->toContain('VOID  S-1');
});
