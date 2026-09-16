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
    // run.sh keeps a failed run's streams under TMPDIR, so point it here and the evidence goes with the test.
    File::makeDirectory($this->dir.'/tmp');

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
            'TMPDIR' => $dir.'/tmp',
            'PATH' => $dir.'/bin:'.getenv('PATH'),
        ], $env),
    );

    $process->setTimeout(60);
    $process->run();

    return $process;
}

/**
 * The committed promise, read the way run.sh reads it: `#` comments stripped, blank lines skipped, and
 * each row split on whitespace.
 *
 * @return list<list<string>>
 */
function runbookCommittedRows(): array
{
    $rows = [];

    foreach (explode("\n", File::get(dirname(__DIR__, 3).'/deploy/runbook/manifest.txt')) as $line) {
        $fields = preg_split('/\s+/', trim((string) preg_replace('/#.*/', '', $line)), -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($fields) && $fields !== []) {
            $rows[] = $fields;
        }
    }

    return $rows;
}

/**
 * The topologies the committed manifest promises a family for, sorted.
 *
 * @return list<string>
 */
function runbookTopologiesFor(string $family): array
{
    $topologies = [];

    foreach (runbookCommittedRows() as $row) {
        if (count($row) === 3 && $row[1] === $family) {
            $topologies[$row[0]] = true;
        }
    }

    $topologies = array_keys($topologies);
    sort($topologies);

    return $topologies;
}

/**
 * The check ids the committed manifest promises one family for one topology, sorted.
 *
 * @return list<string>
 */
function runbookPromised(string $family, string $topology): array
{
    $ids = [];

    foreach (runbookCommittedRows() as $row) {
        if (count($row) === 3 && $row[0] === $topology && $row[1] === $family) {
            $ids[] = $row[2];
        }
    }

    sort($ids);

    return $ids;
}

/**
 * The host scripts that are not families: common.sh travels ahead of every family, and probe-log.sh is the
 * instrument tunnel-log.php drives. Every other host script, and every outside script, is a family.
 *
 * @return list<string>
 */
function runbookInstruments(): array
{
    return ['host/common.sh', 'host/probe-log.sh'];
}

/**
 * Every family declaration a runbook script makes, as [name, sorted checks]. A host script declares with
 * `family <name> <id…>`, which common.sh's verdict() enforces; an outside script with `const FAMILY` and
 * `const CHECKS`, which its own verdict() enforces.
 *
 * ⚠️ READ AS IT CAN BE WRITTEN, AND LOUD WHEN IT CANNOT BE READ. This used to match a declaration only at column
 * 0, so a host family calling `family` inside `main()`, or an outside family declaring class constants, was not
 * found at all: unlisted, never dispatched, and every tree check still passed — the drift those checks exist to
 * catch. A host call is found however it is indented, with a trailing comment or a continued line. An outside
 * constant is read from PHP's own tokens, in a class or not, typed or not. A declaration that is not plain
 * words or quoted plain strings throws rather than being skipped.
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function runbookDeclarations(string $script, string $shown): array
{
    $plain = static function (array $words, string $as) use ($shown): array {
        foreach ($words as $word) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $word) !== 1) {
                throw new RuntimeException("{$shown} declares {$as} as [".implode(' ', $words).'], which cannot be read as plain words');
            }
        }

        return $words;
    };

    if (str_ends_with($script, '.sh')) {
        preg_match_all('/^[ \t]*family[ \t]+(.*)$/m', str_replace("\\\n", ' ', File::get($script)), $calls);
        $declarations = [];

        foreach ($calls[1] as $call) {
            $words = $plain(preg_split('/[ \t]+/', trim((string) preg_replace('/(^|[ \t])#.*$/', '', $call)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 'its family');
            $checks = array_slice($words, 1);
            sort($checks);
            $declarations[] = [$words[0] ?? '', $checks];
        }

        return $declarations;
    }

    $tokens = array_values(array_filter(PhpToken::tokenize(File::get($script)), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    $constants = ['FAMILY' => [], 'CHECKS' => []];

    foreach ($tokens as $at => $token) {
        if (! $token->is(T_CONST)) {
            continue;
        }

        // `const [type] NAME = value;` — the name is the last word before `=`, and the value runs to the `;`.
        for ($next = $at + 1, $name = ''; isset($tokens[$next]) && ! in_array($tokens[$next]->text, ['=', ';'], true); $next++) {
            $name = $tokens[$next]->text;
        }

        if (! array_key_exists($name, $constants) || ($tokens[$next]->text ?? '') !== '=') {
            continue;
        }

        $value = [];

        for ($next++; isset($tokens[$next]) && $tokens[$next]->text !== ';'; $next++) {
            $value[] = $tokens[$next];
        }

        $strings = array_values(array_filter($value, static fn (PhpToken $token): bool => $token->is(T_CONSTANT_ENCAPSED_STRING)));
        $shape = implode('', array_map(static fn (PhpToken $token): string => $token->is(T_CONSTANT_ENCAPSED_STRING) ? 's' : $token->text, $value));

        if ($name === 'FAMILY' ? $shape !== 's' : preg_match('/^\[(s(,s)*,?)?\]$/', $shape) !== 1) {
            throw new RuntimeException("{$shown} declares {$name} as [".implode('', array_map(static fn (PhpToken $token): string => $token->text, $value)).'], which cannot be read as quoted plain words');
        }

        $constants[$name][] = $plain(array_map(static fn (PhpToken $token): string => substr($token->text, 1, -1), $strings), $name);
    }

    if ($constants['FAMILY'] === [] && $constants['CHECKS'] === []) {
        return [];
    }

    if (count($constants['FAMILY']) !== 1 || count($constants['CHECKS']) !== 1) {
        throw new RuntimeException("{$shown} declares FAMILY ".count($constants['FAMILY']).' times and CHECKS '.count($constants['CHECKS']).' times, where a family declares each once');
    }

    $checks = $constants['CHECKS'][0];
    sort($checks);

    return [[$constants['FAMILY'][0][0], $checks]];
}

/**
 * The families a runbook tree ships — the committed one unless told otherwise — found by the scripts that declare
 * them. Every script but an instrument must declare exactly one family, and an instrument none: a script this
 * cannot read is an error here, never a family quietly left out.
 *
 * @return array<string, list<string>> each family's name, and every script that declares it
 */
function runbookShippedFamilies(?string $root = null): array
{
    $root ??= dirname(__DIR__, 3).'/deploy/runbook';
    $families = [];

    foreach (runbookInstruments() as $instrument) {
        if (! is_file($root.'/'.$instrument)) {
            throw new RuntimeException("{$instrument} is listed as an instrument and does not exist");
        }
    }

    foreach ([...glob($root.'/host/*.sh') ?: [], ...glob($root.'/outside/*.php') ?: []] as $script) {
        $shown = substr($script, strlen($root) + 1);
        $declarations = runbookDeclarations($script, $shown);

        if (in_array($shown, runbookInstruments(), true)) {
            if ($declarations !== []) {
                throw new RuntimeException("{$shown} is an instrument, and declares a family");
            }

            continue;
        }

        if (count($declarations) !== 1) {
            throw new RuntimeException("{$shown} declares ".count($declarations).' families, and every script that is not an instrument is dispatched as exactly one (instruments are listed in runbookInstruments())');
        }

        $families[$declarations[0][0]][] = $script;
    }

    ksort($families);

    return $families;
}

/**
 * The checks a family script declares, sorted, read by the same parser that found the family. Each is enforced
 * by that family's own verdict(), so the declaration is exactly what the family can report.
 *
 * @return list<string>
 */
function runbookDeclaredChecks(string $script): array
{
    $declarations = runbookDeclarations($script, basename($script));

    if (count($declarations) !== 1) {
        throw new RuntimeException(basename($script).' declares '.count($declarations).' families');
    }

    return $declarations[0][1];
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

    /*
     * ⚠️ BOTH PATHS ARE NAMED, because there are two kinds of family and the refusal has to say which
     * one it looked for: host/<family>.sh runs on the server over ssh, and outside/<family>.php runs
     * on the operator's machine and reaches the server the way a visitor does.
     */
    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the manifest promises the family [absent]')
        ->and($run->getErrorOutput())->toContain('host/absent.sh')
        ->and($run->getErrorOutput())->toContain('outside/absent.php');
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
        ->and($run->getOutput())->toContain('Every promised check passed.')
        ->and($run->getErrorOutput())->not->toContain('kept in')
        ->and(glob($this->dir.'/tmp/*') ?: [])->toBe([]);
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

    // ⚠️ A FAILED RUN KEEPS ITS EVIDENCE: each family's own output, in the directory the operator is told.
    $kept = glob($this->dir.'/tmp/kitsune-runbook.*') ?: [];

    expect($kept)->toHaveCount(1)
        ->and($run->getErrorOutput())->toContain("The families' own output is kept in {$kept[0]}")
        ->and(File::get($kept[0].'/mixed.out'))->toContain('VERDICT M-2 FAIL another process holds a loopback socket to 443');
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

it('voids a whole family whose sentinel counts more verdicts than arrived', function (): void {
    /*
     * A family that hand-writes its own sentinel — or a stream spliced by something in between — can
     * claim more than it delivered, and then nothing it did deliver is known to be the whole story.
     *
     * ⚠️ THIS USED TO PASS C-1. The discrepancy printed a warning and changed nothing, so the verdict that
     * did arrive stood and only the missing one voided. The family is judged as one stream now — and what
     * did arrive is still shown, so the measurement is not lost with the verdict.
     *
     * The reason names the check with no line. With exactly one sentinel the transport is not the cause —
     * a cut stream loses its sentinel, and a doubled one has two — so it says what can be.
     */
    runbookManifest($this->runbook, "tunnel claims C-1\ntunnel claims C-2\n");
    runbookFamily($this->runbook, 'claims', <<<'BASH'
    printf 'VERDICT C-1 PASS only one of the two arrived\n'
    printf 'SENTINEL claims 2 C-1 C-2\n'
    BASH);

    $run = runbookRun($this->dir);
    $reason = 'the family printed 1 verdicts and its sentinel counts 2: no verdict line arrived for C-2, which its sentinel counts, so its tally counted what its stream never received — the family was killed between counting a verdict and printing it, printed a verdict somewhere else, or its sentinel was written by hand';

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain("VOID  C-1 (claims) — {$reason}; for this check it reported PASS: only one of the two arrived")
        ->and($run->getOutput())->toContain("VOID  C-2 (claims) — {$reason}\n")
        ->and($run->getOutput())->not->toContain('cut or doubled')
        ->and($run->getOutput())->not->toContain('PASS  C-1');
});

it('names a verdict its tally never saw when the family also gave that check one it did', function (): void {
    /*
     * ⚠️ THE TEXTBOOK SHAPE OF THE SUBSHELL BUG. A flag set inside `cmd | while read` is lost with the subshell,
     * so the fallback runs too: L-2 gets its FAIL from the loop, which common.sh's tally never saw, and a PASS
     * from the main shell, which it did. The sentinel names L-2 once, so the reason for an unnamed verdict
     * cannot fire, and the count used to blame the transport — "its stream was cut or doubled" — for a stream
     * that has exactly one sentinel and was neither.
     */
    runbookManifest($this->runbook, "tunnel loop L-1\ntunnel loop L-2\n");
    runbookFamily($this->runbook, 'loop', <<<'BASH'
    family loop L-1 L-2
    verdict L-1 PASS "counted"
    strangers=0
    printf 'pid 4711 socat\n' | while read -r line; do verdict L-2 FAIL "a relay dials the web server: $line"; strangers=1; done
    if (( strangers == 0 )); then verdict L-2 PASS "no relay dials the web server"; fi
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  L-2 (loop) — the family printed 3 verdicts and its sentinel counts 2: it printed more verdicts than its own tally counted for L-2, so a verdict came from a subshell, a pipeline, a by-value closure or a line printed by hand, which that tally never sees; for this check it reported FAIL: a relay dials the web server: pid 4711 socat | PASS: no relay dials the web server')
        ->and($run->getOutput())->toContain('VOID  L-1 (loop) — the family printed 3 verdicts and its sentinel counts 2: it printed more verdicts than its own tally counted for L-2,')
        ->and($run->getOutput())->not->toContain('cut or doubled')
        ->and($run->getOutput())->not->toContain('PASS  L-1');
});

it('names the checks printed too often and those never printed, when one count hides both', function (): void {
    // One check doubled and two missing: the count is short by one, and saying only that would hide the double.
    runbookManifest($this->runbook, "tunnel both B-1\ntunnel both B-2\ntunnel both B-3\n");
    runbookFamily($this->runbook, 'both', <<<'BASH'
    printf 'VERDICT B-1 FAIL from a subshell\n'
    printf 'VERDICT B-1 PASS from the main shell\n'
    printf 'SENTINEL both 3 B-1 B-2 B-3\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  B-2 (both) — the family printed 2 verdicts and its sentinel counts 3: it printed more verdicts than its own tally counted for B-1, so a verdict came from a subshell, a pipeline, a by-value closure or a line printed by hand, which that tally never sees; and no verdict line arrived for B-2 B-3, which its sentinel counts, so its tally counted what its stream never received')
        ->and($run->getOutput())->not->toContain('PASS  B-1');
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

it('refuses a manifest row whose topology no run selects, rather than dropping its family', function (): void {
    /*
     * ⚠️ A TYPO THAT PASSED THE RUN. `--expect` selects rows by their topology, so `dns_only dropped D-1` was
     * promised to a topology no run can name: a dns-only run never dispatched `dropped`, never saw its FAIL, and
     * passed on `good` alone.
     */
    runbookManifest($this->runbook, "dns-only good G-1\ndns_only dropped D-1\n");
    runbookFamily($this->runbook, 'good', "family good G-1\nverdict G-1 PASS holds\n");
    runbookFamily($this->runbook, 'dropped', "family dropped D-1\nverdict D-1 FAIL \"a relay dials the web server\"\n");

    $run = runbookRun($this->dir, [], 'dns-only');

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the manifest row [dns_only dropped D-1] names the topology [dns_only], which is neither tunnel nor dns-only')
        ->and($run->getOutput())->not->toContain('PASS  G-1');
});

it('refuses a family name that is not one plain path segment', function (string $family, string $script): void {
    /*
     * run.sh finds a family as host/<family>.sh or outside/<family>.php and keeps its output as <family>.out
     * under the streams directory. `sub/fa` reached a script in a subdirectory and then stopped run.sh under
     * `set -e`, with no summary, writing into a directory that did not exist; `../fa` reached a script outside
     * host/, passed, and left its output in TMPDIR, outside the streams directory.
     */
    runbookManifest($this->runbook, "tunnel {$family} A-1\n");
    File::ensureDirectoryExists(dirname($this->runbook.'/host/'.$script));
    File::put($this->runbook.'/host/'.$script, "family {$family} A-1\nverdict A-1 PASS ok\n");

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain("the manifest row [tunnel {$family} A-1] names the family [{$family}], which is not one plain path segment")
        ->and($run->getOutput())->not->toContain('PASS  A-1')
        ->and(glob($this->dir.'/tmp/*') ?: [])->toBe([]);
})->with([
    'in a subdirectory' => ['sub/fa', 'sub/fa.sh'],
    'outside host/' => ['../fa', '../fa.sh'],
]);

it('refuses to run when TMPDIR names a directory it cannot use, and says to fix TMPDIR', function (): void {
    /*
     * ⚠️ REFUSED, NOT WORKED AROUND. A failed run keeps its streams under TMPDIR, where the README tells the
     * operator to look, so falling back to /tmp would keep them somewhere else. The refusal used to say only
     * "could not make a temporary directory", which did not say that TMPDIR was the thing to fix.
     */
    runbookManifest($this->runbook, "tunnel good G-1\n");
    runbookFamily($this->runbook, 'good', "family good G-1\nverdict G-1 PASS holds\n");

    $missing = $this->dir.'/tmp/not-created';
    $run = runbookRun($this->dir, ['TMPDIR' => $missing]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain("Refusing to run: a directory for the families' output could not be made in {$missing}, which TMPDIR names, and a failed run keeps its evidence there.")
        ->and($run->getErrorOutput())->toContain('Create that directory or make it writable, or unset TMPDIR to use /tmp.')
        ->and($run->getOutput())->not->toContain('--- good');
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

it('voids a family that refused a verdict after every promised one, where the run used to pass', function (): void {
    /*
     * ⚠️ EXIT 0, AND THE EVIDENCE DELETED. X-1's second verdict — a FAIL — is refused, and the EXIT trap used to
     * close the stream with a sentinel naming the two verdicts common.sh had accepted. The count added up, every
     * promised check had a PASS, and the refusal existed only on stderr, which run.sh echoes and does not judge:
     * the run printed "Every promised check passed." and removed the streams. A refusal exits 1, as a FAIL does,
     * so the stream has to say it.
     */
    runbookManifest($this->runbook, "tunnel twice X-1\ntunnel twice X-2\n");
    runbookFamily($this->runbook, 'twice', <<<'BASH'
    family twice X-1 X-2
    verdict X-1 PASS "no relay dials the web server"
    verdict X-2 PASS "holds"
    verdict X-1 FAIL "a second look found a relay"
    BASH);

    $run = runbookRun($this->dir);
    $kept = glob($this->dir.'/tmp/kitsune-runbook.*') ?: [];

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  X-1 (twice) — the family refused to check (verdict X-1: emitted twice)')
        ->and($run->getOutput())->toContain('for this check it reported PASS: no relay dials the web server')
        ->and($run->getOutput())->toContain('VOID  X-2 (twice) — the family refused to check (verdict X-1: emitted twice)')
        ->and($run->getOutput())->not->toContain('Every promised check passed.')
        ->and($kept)->toHaveCount(1)
        ->and($run->getErrorOutput())->toContain("The families' own output is kept in {$kept[0]}")
        ->and(File::get($kept[0].'/twice.out'))->toContain("REFUSED twice verdict X-1: emitted twice\n")
        ->and(File::get($kept[0].'/twice.out'))->not->toContain('SENTINEL');
});

it('voids a family whose stream carries a refusal, even when it closes as if nothing were refused', function (): void {
    /*
     * The gate's half of the rule, on its own. A family that prints its refusal and then closes anyway — a PHP
     * family whose `finally` does not know, or a refusal from a subshell whose parent carries on to its EXIT
     * trap — leaves a sentinel that adds up. The refusal line voids it regardless.
     */
    runbookManifest($this->runbook, "tunnel closes R-1\n");
    runbookFamily($this->runbook, 'closes', <<<'BASH'
    printf 'VERDICT R-1 PASS holds\n'
    printf 'REFUSED closes verdict R-2: this family did not declare that id\n'
    printf 'SENTINEL closes 1 R-1\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  R-1 (closes) — the family refused to check (verdict R-2: this family did not declare that id)')
        ->and($run->getOutput())->not->toContain('PASS  R-1');
});

it('voids a check given two verdicts, naming both, rather than letting the last one stand', function (): void {
    /*
     * ⚠️ THE LAST LINE USED TO WIN. The gate took the final verdict line for an id, so a family that
     * printed FAIL and then PASS for one check reported PASS. The count still adds up here — two lines
     * arrive for a sentinel that counts two — because M-2's verdict is missing, which is exactly how a
     * repeat can hide inside an honest-looking total.
     */
    runbookManifest($this->runbook, "tunnel masks M-1\ntunnel masks M-2\n");
    runbookFamily($this->runbook, 'masks', <<<'BASH'
    printf 'VERDICT M-1 FAIL a relay other than the connector dials the web server\n'
    printf 'VERDICT M-1 PASS the only loopback client is the connector\n'
    printf 'SENTINEL masks 2 M-1 M-2\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  M-1 (masks) — the check was given 2 verdicts, so none of them can stand: FAIL: a relay other than the connector dials the web server | PASS: the only loopback client is the connector')
        ->and($run->getOutput())->not->toContain('PASS  M-1');
});

it('voids a family that lists one check twice in its sentinel, which the count cannot see', function (): void {
    /*
     * ⚠️ THE SHAPE A PHP FAMILY PRODUCES. tunnel-log.php's sentinel lists every verdict it printed, so a
     * check printed twice is listed twice, and the count then matches the lines that arrived. Before this,
     * R-1's FAIL followed by its PASS read as PASS, and the run exited 0.
     */
    runbookManifest($this->runbook, "tunnel repeats R-1\n");
    runbookFamily($this->runbook, 'repeats', <<<'BASH'
    printf 'VERDICT R-1 FAIL the forged header reached PHP\n'
    printf 'VERDICT R-1 PASS a second pass over the same check\n'
    printf 'SENTINEL repeats 2 R-1 R-1\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  R-1 (repeats) — the family reported R-1 more than once')
        ->and($run->getOutput())->toContain('for this check it reported FAIL: the forged header reached PHP | PASS: a second pass over the same check')
        ->and($run->getOutput())->not->toContain('PASS  R-1');
});

it('voids a family whose sentinel cannot be trusted', function (string $stream, string $reason): void {
    runbookManifest($this->runbook, "tunnel shaky K-1\n");
    runbookFamily($this->runbook, 'shaky', $stream);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  K-1 (shaky) — '.$reason)
        ->and($run->getOutput())->not->toContain('PASS  K-1');
})->with([
    // A stream replayed or spliced by whatever carried it: which part belongs to this run cannot be told.
    'closed twice' => [<<<'BASH'
        printf 'VERDICT K-1 PASS holds\n'
        printf 'SENTINEL shaky 1 K-1\n'
        printf 'SENTINEL shaky 1 K-1\n'
        BASH, 'the family closed its stream 2 times'],
    'a count that is not a number' => [<<<'BASH'
        printf 'VERDICT K-1 PASS holds\n'
        printf 'SENTINEL shaky one K-1\n'
        BASH, "the family's sentinel is malformed: it counts [one] and names 1 checks"],
    'a count its own list contradicts' => [<<<'BASH'
        printf 'VERDICT K-1 PASS holds\n'
        printf 'SENTINEL shaky 2 K-1\n'
        BASH, "the family's sentinel is malformed: it counts [2] and names 1 checks"],
]);

it('voids a family that reports a check the manifest does not promise', function (): void {
    /*
     * ⚠️ WHAT WAS MEASURED IS NOT WHAT WAS PROMISED. A family gains a check and nobody adds it to the
     * manifest: the run used to judge only the promised ids and exit 0, certifying a family whose own
     * account of itself had moved on — here, past a FAIL nobody was promised.
     */
    runbookManifest($this->runbook, "tunnel grown X-1\n");
    runbookFamily($this->runbook, 'grown', <<<'BASH'
    family grown X-1 X-2
    verdict X-1 PASS "the promised check holds"
    verdict X-2 FAIL "a check nobody promised, and it fails"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  X-1 (grown) — the family reports checks the manifest does not promise for a tunnel host (X-2 FAIL: a check nobody promised, and it fails)')
        ->and($run->getOutput())->not->toContain('PASS  X-1');
});

it('counts a verdict only for the family that gave it', function (): void {
    /*
     * ⚠️ VERDICT LINES DO NOT NAME THEIR FAMILY, SO EACH FAMILY IS JUDGED ON ITS OWN STREAM. Family `a`
     * prints a verdict for B-1, which the manifest gives to family `b`, and `b` never checks it. The gate
     * used to find a B-1 line in the one shared stream and pass it, so a check nobody responsible ran counted
     * as held. In `b`'s own stream there is no B-1 at all; `a` is voided for reporting a check it was not
     * promised, and what it said about B-1 is shown.
     */
    runbookManifest($this->runbook, "tunnel a A-1\ntunnel b B-1\n");
    runbookFamily($this->runbook, 'a', <<<'BASH'
    family a A-1 B-1
    verdict A-1 PASS "the family's own check holds"
    verdict B-1 PASS "a check that belongs to another family"
    BASH);
    runbookFamily($this->runbook, 'b', <<<'BASH'
    family b B-1
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  B-1 (b) — promised by the manifest, and no verdict arrived')
        ->and($run->getOutput())->toContain('VOID  A-1 (a) — the family reports checks the manifest does not promise for a tunnel host (B-1 PASS: a check that belongs to another family)')
        ->and($run->getOutput())->not->toContain('PASS  B-1')
        ->and($run->getOutput())->not->toContain('PASS  A-1');
});

it('voids a family that printed a verdict its sentinel does not name, where the run used to pass', function (): void {
    /*
     * ⚠️ EXIT 0 WITH A FAIL IN THE STREAM. The family's own tally never heard of T-2 — a PHP verdict called
     * through a by-value closure, or a hand-written line — so its sentinel names only T-1, and the manifest
     * promises only T-1. The count looked only at the ids the sentinel names, found one verdict for one, and
     * passed the run with T-2's FAIL printed and never judged. It did so before this change and after the
     * first version of it.
     */
    runbookManifest($this->runbook, "tunnel lone T-1\n");
    runbookFamily($this->runbook, 'lone', <<<'BASH'
    family lone T-1
    verdict T-1 PASS "login is limited"
    printf 'VERDICT T-2 FAIL the API is not throttled at all\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  T-1 (lone) — the family printed verdicts its sentinel does not name (T-2 FAIL: the API is not throttled at all)')
        ->and($run->getOutput())->not->toContain('PASS  T-1');
});

it('traces a verdict printed from a pipeline to the family that printed it, and keeps its FAIL', function (): void {
    /*
     * The realistic way a host family prints a verdict its sentinel cannot count: `cmd | while read …; do
     * verdict …; done` runs the loop in a subshell, where common.sh's tally is a copy that is thrown away.
     * The shared stream blamed "another family" for T-2 and dropped its FAIL; the family's own stream says
     * where the verdict came from and what it said.
     */
    runbookManifest($this->runbook, "tunnel tally T-1\ntunnel tally T-2\n");
    runbookFamily($this->runbook, 'tally', <<<'BASH'
    family tally T-1 T-2
    verdict T-1 PASS "counted"
    printf 'x\n' | while read -r _; do verdict T-2 FAIL "printed from a pipeline"; done
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  T-2 (tally) — the family printed verdicts its sentinel does not name (T-2 FAIL: printed from a pipeline), so they came from a subshell or pipeline its own tally never saw')
        ->and($run->getOutput())->not->toContain('another family')
        ->and($run->getOutput())->not->toContain('PASS  T-1');
});

it('refuses a manifest that promises one check to two families', function (string $rows): void {
    /*
     * ⚠️ A VERDICT LINE NAMES ITS CHECK, NOT ITS FAMILY, so one check promised to two families cannot say
     * whose verdict is whose — the shape a new family copied from an old one takes when it keeps one of its
     * ids. Across topologies it used to exit 0; within one, the stricter count blamed the transport.
     */
    runbookManifest($this->runbook, $rows);
    runbookFamily($this->runbook, 'a', "family a X-1\nverdict X-1 PASS ok\n");
    runbookFamily($this->runbook, 'b', "family b X-1\nverdict X-1 PASS ok\n");

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('the check X-1 is promised to both [a] and [b]')
        ->and($run->getOutput())->not->toContain('PASS  X-1');
})->with([
    'in one topology' => ["tunnel a X-1\ntunnel b X-1\n"],
    'across topologies' => ["tunnel a X-1\ndns-only b X-1\n"],
]);

it('keeps a FAIL whole when the family before it ended its output without a newline', function (): void {
    /*
     * One shared stream glued `b`'s first verdict onto `a`'s unterminated last line, so `a`'s sentinel read
     * as malformed and `b`'s FAIL disappeared into it: both families VOID, and the FAIL never shown.
     */
    runbookManifest($this->runbook, "tunnel a A-1\ntunnel b B-1\n");
    runbookFamily($this->runbook, 'a', <<<'BASH'
    printf 'VERDICT A-1 PASS holds\nSENTINEL a 1 A-1'
    BASH);
    runbookFamily($this->runbook, 'b', <<<'BASH'
    printf 'VERDICT B-1 FAIL a relay dials the web server\nSENTINEL b 1 B-1\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('PASS  A-1 — holds')
        ->and($run->getOutput())->toContain('FAIL  B-1 — a relay dials the web server');
});

it('shows a verdict glued onto the family\'s own unterminated output, and says it was glued', function (string $stream, string $line, string $shown): void {
    /*
     * ⚠️ A FAIL THAT REACHED THE STREAM WAS REPORTED NOWHERE. A command whose output does not end its line — `curl
     * -w '%{http_code}'` printing `403`, left uncaptured — glues the next verdict onto it. The gate accepts a
     * verdict only at the start of a line, because a reason may quote one, so `403VERDICT G-1 FAIL …` counted
     * for nothing: the family was blamed on its transport, and G-1's FAIL appeared in no line of the report.
     * Glued onto the sentinel instead, the stream was said to have no sentinel, as if it had never run.
     */
    runbookManifest($this->runbook, "tunnel glue G-1\ntunnel glue G-2\n");
    runbookFamily($this->runbook, 'glue', $stream);

    $run = runbookRun($this->dir);
    $reason = "the family's stream has a verdict, sentinel or refusal partway through {$line}, after output that did not end its line, and one that does not start its line cannot be told from text quoting one";

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain("VOID  G-1 (glue) — {$reason}; for this check it reported {$shown}")
        ->and($run->getOutput())->toContain("VOID  G-2 (glue) — {$reason}; for this check it reported PASS: login is limited")
        ->and($run->getOutput())->not->toContain('no sentinel')
        ->and($run->getOutput())->not->toContain('FAIL  G-1');
})->with([
    'glued onto a verdict' => [<<<'BASH'
        family glue G-1 G-2
        printf '403'
        verdict G-1 FAIL "the API answered 403 to a forged header"
        verdict G-2 PASS "login is limited"
        BASH, 'line 1', 'FAIL, after other output on its line: the API answered 403 to a forged header'],
    'glued onto the sentinel' => [<<<'BASH'
        family glue G-1 G-2
        verdict G-1 FAIL "the API answered 403 to a forged header"
        verdict G-2 PASS "login is limited"
        printf '200'
        BASH, 'line 3', 'FAIL: the API answered 403 to a forged header'],
]);

it('reads a stream byte by byte, whatever the operator\'s locale', function (string $body, string $shown): void {
    /*
     * ⚠️ macOS grep IN A UTF-8 LOCALE SKIPS SOME LINES HOLDING A BYTE THAT IS NOT UTF-8. Measured: `VERDICT I-1
     * FAIL \377…` matched nothing for `^VERDICT I-1 (PASS|FAIL|VOID) `, so a FAIL whose reason began with a
     * measured command's raw bytes was reported as "no verdict arrived". The same byte before a glued verdict
     * is not a character `.` can match, and `cut` exits 1 on it with "Illegal byte sequence", ending run.sh
     * before its summary. The operator's shell is where this runs, and a UTF-8 locale is its default.
     */
    runbookManifest($this->runbook, "tunnel bytes I-1\n");
    runbookFamily($this->runbook, 'bytes', $body);

    $run = runbookRun($this->dir, ['LC_ALL' => 'en_US.UTF-8']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain($shown)
        ->and($run->getOutput())->toContain('could not be measured.');
})->with([
    'a reason that begins with one' => [
        "family bytes I-1\nverdict I-1 FAIL \"\$(printf '\\377')raw output\"\n",
        "FAIL  I-1 — \xFFraw output",
    ],
    'a verdict glued after one' => [
        "family bytes I-1\nprintf '\\033]0;forge@stage\\007\\377'\nverdict I-1 FAIL \"measured\"\n",
        "VOID  I-1 (bytes) — the family's stream has a verdict, sentinel or refusal partway through line 1",
    ],
]);

it('reads a stream carrying a stray NUL byte as text, rather than as nothing', function (): void {
    /*
     * ⚠️ macOS grep calls a file with a NUL in it binary and matches no line of it. On the shared stream,
     * one family's stray byte blanked every family: the gate before this read "Binary file … matches" as a
     * verdict of no known outcome, counted nothing, and exited 0; the first stricter version voided them
     * all with a temporary file's path as the count.
     */
    runbookManifest($this->runbook, "tunnel a A-1\ntunnel b B-1\n");
    runbookFamily($this->runbook, 'a', <<<'BASH'
    family a A-1
    printf 'RECORD A-1 a stray \000 byte\n'
    verdict A-1 FAIL "measured"
    BASH);
    runbookFamily($this->runbook, 'b', <<<'BASH'
    family b B-1
    verdict B-1 PASS "holds"
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('FAIL  A-1 — measured')
        ->and($run->getOutput())->toContain('PASS  B-1 — holds');
});

it('voids a family whose stream closes as a different family', function (): void {
    // A script copied from another family and never renamed prints that family's sentinel: whatever it
    // reported, it is not the family the manifest promised.
    runbookManifest($this->runbook, "tunnel a A-1\n");
    runbookFamily($this->runbook, 'a', <<<'BASH'
    printf 'VERDICT A-1 PASS holds\nSENTINEL relays 1 A-1\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain('VOID  A-1 (a) — the family closed its stream as [relays], so the script that ran is not the family promised; for this check it reported PASS: holds');
});

it('promises exactly the families the runbook ships, and no other', function (): void {
    /*
     * ⚠️ CLAIMED, AND NOT CHECKED. manifest.txt, README.md and common.sh all said this file asserted that
     * the committed manifest and the families agree in both directions, but every case above uses a
     * fixture manifest and nothing read the committed one. A family that lands unlisted is never
     * dispatched, so a run looks green because a check was never wired in.
     */
    $rows = runbookCommittedRows();

    foreach ($rows as $row) {
        // run.sh refuses any other topology; this catches the typo before a run does.
        expect($row)->toHaveCount(3, 'manifest row ['.implode(' ', $row).']')
            ->and($row[0])->toBeIn(['tunnel', 'dns-only'], 'manifest row ['.implode(' ', $row).']');
    }

    $promised = array_values(array_unique(array_map(static fn (array $row): string => $row[1], $rows)));
    sort($promised);
    $shipped = runbookShippedFamilies();

    expect($promised)->toBe(array_keys($shipped))
        ->and($promised)->not->toBe([]);

    foreach ($shipped as $family => $scripts) {
        // run.sh finds a family by the manifest's name alone, as host/<family>.sh or outside/<family>.php,
        // so each family has one script named for it: a declaration under another filename is never run.
        expect($scripts)->toHaveCount(1, "[{$family}] is declared by ".implode(', ', $scripts))
            ->and(pathinfo($scripts[0], PATHINFO_FILENAME))->toBe($family);
    }

    // ⚠️ ONE CHECK, ONE FAMILY. A verdict line names its check and not its family; run.sh refuses a manifest
    // that gives one check to two families, and this catches it before any run does.
    $owners = [];

    foreach ($rows as $row) {
        $owners[$row[2]][$row[1]] = true;
    }

    foreach ($owners as $id => $claimants) {
        expect(array_keys($claimants))->toHaveCount(1, "[{$id}] is promised to ".implode(' and ', array_keys($claimants)));
    }
});

it('promises every check each family declares, under every topology it runs on', function (): void {
    /*
     * A family's checks are its declaration — a host script's `family` line, an outside script's `const
     * CHECKS` — and its own verdict() refuses any other id, so the declaration is exactly what it can report.
     * It must equal the committed rows under every topology that promises the family: a partial promise leaves
     * a check that runs and counts for nothing.
     */
    $judged = [];
    $pairs = [];

    foreach (runbookCommittedRows() as $row) {
        if (count($row) === 3) {
            $pairs["{$row[0]} {$row[1]}"] = true;
        }
    }

    foreach (runbookShippedFamilies() as $family => $scripts) {
        $declared = runbookDeclaredChecks($scripts[0]);

        expect($declared)->not->toBe([], "[{$family}] declares no checks in {$scripts[0]}");

        foreach (runbookTopologiesFor($family) as $topology) {
            expect(runbookPromised($family, $topology))->toBe($declared, "[{$family}] on a {$topology} host");
            $judged[] = "{$topology} {$family}";
        }
    }

    $expected = array_keys($pairs);
    sort($expected);
    sort($judged);

    // Every family the manifest promises was judged on every topology it is promised for, so an empty loop
    // cannot pass.
    expect($judged)->toBe($expected)
        ->and($judged)->not->toBe([]);
});

it('runs each outside family without its instruments and sees it void every check it declares', function (): void {
    /*
     * ⚠️ RUN, NOT ONLY READ. `const CHECKS` is read above; here each outside family runs the way run.sh runs
     * it, pointed at a runbook with no instruments, where it must void every check it declares and close its
     * stream. The checks a measuring run reports are held by the family's own verdict(), which refuses any id
     * outside CHECKS. ssh and curl are stubbed to refuse and record, so nothing reaches the network.
     */
    foreach (['ssh', 'curl'] as $command) {
        File::put($this->dir.'/bin/'.$command, "#!/usr/bin/env bash\necho {$command} >> ".escapeshellarg($this->dir.'/network')."\nexit 97\n");
        chmod($this->dir.'/bin/'.$command, 0755);
    }

    File::makeDirectory($this->dir.'/bare/host', 0755, true);

    $shipped = runbookShippedFamilies();
    $judged = [];
    $pairs = [];

    foreach (runbookCommittedRows() as $row) {
        if (str_contains($shipped[$row[1]][0] ?? '', '/outside/')) {
            $pairs["{$row[0]} {$row[1]}"] = true;
        }
    }

    foreach ($shipped as $family => $scripts) {
        if (! str_contains($scripts[0], '/outside/')) {
            continue;
        }

        foreach (runbookTopologiesFor($family) as $topology) {
            $run = new Process(
                ['php', $scripts[0], '--host', 'forge@fixture', '--expect', $topology, '--runbook', $this->dir.'/bare'],
                $this->dir,
                ['HOME' => (string) getenv('HOME'), 'TMPDIR' => sys_get_temp_dir(), 'PATH' => $this->dir.'/bin:'.getenv('PATH')],
            );
            $run->setTimeout(60);
            $run->run();

            $lines = array_values(array_filter(explode("\n", $run->getOutput()), static fn (string $line): bool => $line !== ''));
            $sentinels = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'SENTINEL '.$family.' ')));
            $verdicts = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'VERDICT ')));
            $context = "[{$family}] on a {$topology} host: ".$run->getOutput().$run->getErrorOutput();

            expect($run->isSuccessful())->toBeFalse($context)
                ->and($sentinels)->toHaveCount(1, $context);

            $reported = preg_split('/ +/', trim((string) preg_replace('/^SENTINEL \S+ \d+/', '', $sentinels[0])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($reported);

            expect($reported)->toBe(runbookDeclaredChecks($scripts[0]), $context)
                ->and($reported)->toBe(runbookPromised($family, $topology), $context)
                ->and($verdicts)->toHaveCount(count($reported), $context);

            foreach ($verdicts as $verdict) {
                expect(explode(' ', $verdict)[2] ?? '')->toBe('VOID', $verdict);
            }

            $judged[] = "{$topology} {$family}";
        }
    }

    $expected = array_keys($pairs);
    sort($expected);
    sort($judged);

    expect($judged)->toBe($expected)
        ->and($judged)->not->toBe([])
        ->and(is_file($this->dir.'/network'))->toBeFalse();
});

/**
 * A runbook tree holding the real instruments and the given scripts, for the discovery helpers to read.
 *
 * @param  array<string, string>  $scripts  path under the tree => source
 */
function runbookTree(string $dir, array $scripts): string
{
    $root = $dir.'/tree';
    $repo = dirname(__DIR__, 3).'/deploy/runbook';

    foreach (runbookInstruments() as $instrument) {
        File::ensureDirectoryExists(dirname($root.'/'.$instrument));
        File::copy($repo.'/'.$instrument, $root.'/'.$instrument);
    }

    foreach ($scripts as $path => $source) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $source);
    }

    return $root;
}

it('finds a family however its declaration is written', function (): void {
    /*
     * ⚠️ THE TWO FAMILIES NOT YET LANDED, WRITTEN IN STYLES NONE OF THE THREE LANDED ONES USE. Discovery matched only
     * a declaration at column 0, so a host family calling `family` inside `main()` and an outside family declaring
     * class constants were both invisible: with no manifest rows for either, every tree check passed, and a run
     * never dispatched them. The real instruments sit beside them, and must read as declaring nothing.
     */
    $root = runbookTree($this->dir, [
        'host/sshd.sh' => "main() {\n  family sshd SSH-2 \\\n    SSH-1  # forwarding, then the sweep\n  verdict SSH-1 PASS ok\n}\n\nmain \"\$@\"\n",
        'outside/throttle.php' => "<?php\n\nfinal class Throttle\n{\n    public const string FAMILY = 'throttle';\n\n    final public const array CHECKS = [\n        'THR-1',\n        \"THR-2\",\n    ];\n}\n",
    ]);

    $families = runbookShippedFamilies($root);

    expect(array_keys($families))->toBe(['sshd', 'throttle'])
        ->and(runbookDeclaredChecks($families['sshd'][0]))->toBe(['SSH-1', 'SSH-2'])
        ->and(runbookDeclaredChecks($families['throttle'][0]))->toBe(['THR-1', 'THR-2']);
});

it('fails loudly on a script whose family it cannot read, rather than leaving the family out', function (string $path, string $source, string $message): void {
    // Every script but an instrument is a family, so one whose declaration cannot be read is an error, not an absence.
    $root = runbookTree($this->dir, [$path => $source]);

    expect(fn () => runbookShippedFamilies($root))->toThrow(RuntimeException::class, $message);
})->with([
    'a host script that declares nothing' => ['host/sweep.sh', "verdict SSH-9 PASS ok\n", 'host/sweep.sh declares 0 families'],
    'a host script that declares twice' => ['host/sshd.sh', "family sshd SSH-1\nfamily sshd SSH-2\n", 'host/sshd.sh declares 2 families'],
    'a host family named by a variable' => ['host/sshd.sh', "family \"\$name\" SSH-1\n", 'host/sshd.sh declares its family as ["$name" SSH-1], which cannot be read as plain words'],
    'an outside script that declares nothing' => ['outside/throttle.php', "<?php\n\ndefine('FAMILY', 'throttle');\n", 'outside/throttle.php declares 0 families'],
    'checks built from another constant' => ['outside/throttle.php', "<?php\n\nconst FAMILY = 'throttle';\nconst CHECKS = [PREFIX.'-1'];\n", "outside/throttle.php declares CHECKS as [[PREFIX.'-1']], which cannot be read as quoted plain words"],
    'a family with no checks declared' => ['outside/throttle.php', "<?php\n\nconst FAMILY = 'throttle';\n", 'outside/throttle.php declares FAMILY 1 times and CHECKS 0 times'],
    'an instrument that declares a family' => ['host/probe-log.sh', "family probe PRB-1\n", 'host/probe-log.sh is an instrument, and declares a family'],
]);
