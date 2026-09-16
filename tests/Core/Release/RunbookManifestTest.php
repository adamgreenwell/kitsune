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
 * The families the runbook ships, found by the scripts that declare them. A host script opens one with
 * `family <name> <id…>`, and common.sh refuses a verdict for any id outside that list; an outside script
 * names itself with `const FAMILY`. Instruments — common.sh, probe-log.sh — declare neither.
 *
 * @return array<string, list<string>> each family's name, and every script that declares it
 */
function runbookShippedFamilies(): array
{
    $root = dirname(__DIR__, 3).'/deploy/runbook';
    $families = [];

    foreach ([['host/*.sh', '/^family (\S+)/m'], ['outside/*.php', "/^const FAMILY = '([^']+)';/m"]] as [$pattern, $declaration]) {
        foreach (glob($root.'/'.$pattern) ?: [] as $script) {
            preg_match_all($declaration, File::get($script), $matches);

            foreach ($matches[1] as $name) {
                $families[$name][] = $script;
            }
        }
    }

    ksort($families);

    return $families;
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

it('voids a whole family whose sentinel counts more verdicts than arrived', function (): void {
    /*
     * A family that hand-writes its own sentinel — or a stream spliced by something in between — can
     * claim more than it delivered, and then nothing it did deliver is known to be the whole story.
     *
     * ⚠️ THIS USED TO PASS C-1. The discrepancy printed a warning and changed nothing, so the verdict that
     * did arrive stood and only the missing one voided. The family is judged as one stream now.
     */
    runbookManifest($this->runbook, "tunnel claims C-1\ntunnel claims C-2\n");
    runbookFamily($this->runbook, 'claims', <<<'BASH'
    printf 'VERDICT C-1 PASS only one of the two arrived\n'
    printf 'SENTINEL claims 2 C-1 C-2\n'
    BASH);

    $run = runbookRun($this->dir);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain("VOID  C-1 (claims) — the family's sentinel counted 2 verdicts and 1 arrived")
        ->and($run->getOutput())->toContain("VOID  C-2 (claims) — the family's sentinel counted 2 verdicts and 1 arrived")
        ->and($run->getOutput())->not->toContain('PASS  C-1');
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
        ->and($run->getOutput())->toContain('VOID  M-1 (masks) — the check was given 2 verdicts (FAIL, PASS)')
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
        ->and($run->getOutput())->toContain('VOID  X-1 (grown) — the family reports checks the manifest does not promise for a tunnel host (X-2)')
        ->and($run->getOutput())->not->toContain('PASS  X-1');
});

it('counts a verdict only for the family that gave it', function (): void {
    /*
     * ⚠️ VERDICT LINES DO NOT NAME THEIR FAMILY. Family `a` prints a verdict for B-1, which the manifest
     * gives to family `b`, and `b` never checks it. The gate used to find a B-1 line in the stream and pass
     * it, so a check nobody responsible ran counted as held. `a` is voided for reporting a check it was
     * not promised, and B-1 for having no verdict from its own family.
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
        ->and($run->getOutput())->toContain("VOID  B-1 (b) — a verdict arrived that this family's sentinel does not name")
        ->and($run->getOutput())->not->toContain('PASS  B-1')
        ->and($run->getOutput())->not->toContain('PASS  A-1');
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
        expect($row)->toHaveCount(3, 'manifest row ['.implode(' ', $row).']');
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
});

it('promises every check a host family declares, under every topology it runs on', function (): void {
    /*
     * A host family's checks are its `family` line: common.sh refuses a verdict for any other id, so the
     * declaration is exactly what the family can report. It must equal the committed rows under every
     * topology that promises the family — a partial promise leaves a check that runs and counts for nothing.
     */
    $shipped = runbookShippedFamilies();
    $judged = [];
    $pairs = [];

    foreach (runbookCommittedRows() as $row) {
        if (str_contains($shipped[$row[1]][0] ?? '', '/host/')) {
            $pairs["{$row[0]} {$row[1]}"] = true;
        }
    }

    foreach ($shipped as $family => $scripts) {
        if (! str_contains($scripts[0], '/host/')) {
            continue;
        }

        preg_match('/^family '.preg_quote($family, '/').'((?: +\S+)*) *$/m', File::get($scripts[0]), $declaration);
        $declared = preg_split('/ +/', trim($declaration[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($declared);

        expect($declared)->not->toBe([], "[{$family}] declares no checks");

        foreach (runbookTopologiesFor($family) as $topology) {
            expect(runbookPromised($family, $topology))->toBe($declared, "[{$family}] on a {$topology} host");
            $judged[] = "{$topology} {$family}";
        }
    }

    $expected = array_keys($pairs);
    sort($expected);
    sort($judged);

    expect($judged)->toBe($expected)
        ->and($judged)->not->toBe([]);
});

it('promises every check an outside family reports, under every topology it runs on', function (): void {
    /*
     * ⚠️ RUN, NOT READ. An outside family has no declaration to read: its checks are the ones it reports.
     * So each one runs the way run.sh runs it, pointed at a runbook with no instruments, where it must void
     * every check it can report and close its stream — and the ids in that sentinel must be exactly the
     * committed rows for that family and topology. ssh and curl are stubbed to refuse and record, so
     * nothing reaches the network.
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

            expect($reported)->toBe(runbookPromised($family, $topology), $context)
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
