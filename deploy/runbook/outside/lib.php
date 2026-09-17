<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The helpers every outside family of the ADR-034 runbook shares — issue #111.
 *
 * ⚠️ AN INSTRUMENT, NOT A FAMILY. run.sh dispatches one script per family named in the manifest, and
 * RunbookManifestTest reads every other script under outside/ as a family. This declares none and is
 * listed there as an instrument, so promising a check id here would make the completeness gate demand a
 * verdict from a script no run dispatches.
 *
 * ⚠️ WHAT A FAMILY STILL OWNS. The protocol's two named lines take the family as an argument, and
 * verdict() reads the loading file's own `FAMILY` and `CHECKS` constants: a family declares what it may
 * report and where it runs, and this refuses anything else on its behalf. The getopt, the order of the
 * measurements and the judging stay in the family's own file.
 */

// --- the shared outside helpers ---------------------------------------------------------------------
//
// ⚠️ ONE DEFINITION, AND THE TEST SAYS WHO CARRIES IT. Everything between this marker and its closing
// one is the runbook's single copy of the helpers every outside family needs. `throttle.php` loads it
// with `require_once`; `tunnel-log.php` carries the same bytes inline, because RunbookTunnelLogTest
// mutates its source and runs the copy from outside the runbook tree, where a sibling `require` cannot
// resolve. RunbookOutsideLibTest holds every carrier byte-identical to this file, so the two cannot
// drift: edit this block here, and the test names the file that has to follow.

/**
 * The status run() reports when a command never started, which no process can exit with.
 *
 * ⚠️ "ssh NEVER RAN" AND "ssh EXITED 255" DECIDE A REMOVAL DIFFERENTLY. After a start that ran, the
 * instrument may be installed and stop has to say what it found; after one that never reached the host,
 * nothing was installed and running stop could only report a failure of its own.
 */
const NOT_STARTED = -1;

/**
 * One line, always: a reason that spans lines would reach run.sh as a verdict followed by something
 * no parser recognises.
 */
function oneLine(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * ⚠️ A REFUSAL IS WRITTEN INTO THE VERDICT STREAM, AND IT THROWS RATHER THAN EXITS. It throws so the
 * `finally` that removes whatever the family installed on the host still runs: an exit would leave the
 * server changed until the dead-man timer fired. But that `finally` used to close the stream too, with a
 * sentinel naming the verdicts already accepted, so a refused verdict after an accepted one was simply
 * absent: the count added up, and run.sh passed the run and deleted its streams, with the refusal only on
 * stderr, which it does not judge. The exit status could not have said it either — a FAIL exits 1 too. So
 * the stream says `REFUSED`, which voids the whole family, and the main path withholds the sentinel after
 * one.
 */
function refuse(string $family, string $reason): never
{
    echo 'REFUSED '.$family.' '.oneLine($reason)."\n";

    throw new LogicException('Refusing to check: '.$reason);
}

/**
 * One verdict, guarded as common.sh's verdict() guards a host family's.
 *
 * ⚠️ THE FAMILY AND ITS CHECKS ARE READ FROM THE LOADING FILE'S OWN CONSTANTS, not passed in. A verdict
 * line does not name its family, so this prints nothing that needs it — and the call shape belongs to the
 * family: `verdict('TUN-1', 'PASS', …)` is what its source says and what its tests mutate. The two lines
 * that do name a family, REFUSED and SENTINEL, take it as an argument.
 *
 * An undeclared id or a second verdict for one check is this runbook's bug, and printing it would let the
 * gate judge a check nobody promised or pick between two. A refusal quotes the verdict it refused, for the
 * reason common.sh gives: a FAIL refused here was otherwise reported nowhere.
 *
 * @param  list<array{0: string, 1: string}>  $verdicts
 */
function verdict(string $id, string $outcome, string $reason, array &$verdicts): void
{
    $refused = "verdict {$id} {$outcome} [".oneLine($reason).']';

    if (! in_array($id, CHECKS, true)) {
        refuse(FAMILY, "{$refused}: this family did not declare that id");
    }

    if (in_array($id, array_column($verdicts, 0), true)) {
        refuse(FAMILY, "{$refused}: emitted twice");
    }

    echo 'VERDICT '.$id.' '.$outcome.' '.oneLine($reason)."\n";
    $verdicts[] = [$id, $outcome];
}

function record(string $id, string $fact): void
{
    echo 'RECORD '.$id.' '.oneLine($fact)."\n";
}

/**
 * The last line of a family that did not refuse, carrying its own verdict count.
 *
 * @param  list<array{0: string, 1: string}>  $verdicts
 */
function sentinel(string $family, array $verdicts): void
{
    $ids = array_map(static fn (array $verdict): string => $verdict[0], $verdicts);

    echo 'SENTINEL '.$family.' '.count($verdicts).' '.implode(' ', $ids)."\n";
}

/**
 * Run a command, with an optional stdin, and return its status, stdout and stderr.
 *
 * @param  list<string>  $command
 * @return array{0: int, 1: string, 2: string}
 */
function run(array $command, string $input = '', int $timeout = 180): array
{
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        return [NOT_STARTED, '', 'could not start '.($command[0] ?? '?')];
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($process);

        if (! $status['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return [(int) $status['exitcode'], $out, $err];
        }

        usleep(20_000);
    }

    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [124, $out, $err."\ntimed out after {$timeout}s"];
}

/**
 * Drive an instrument on the host, sending it exactly as run.sh sends a family: the bytes on stdin,
 * under `sudo -n bash -s`.
 *
 * ⚠️ THE BYTES ARE READ ONCE, BEFORE ANY OF THIS RUNS. A file that could not be read here would fail `start` and
 * `stop` alike, and a stop that never ran cannot say whether the instrument is on the server — so its check would
 * report something left behind that was never installed. Read up front, an unreadable instrument is measured before
 * anything is sent, and stop sends exactly the bytes start did.
 *
 * @param  list<string>  $argv
 * @return array{0: int, 1: string, 2: string}
 */
function instrument(string $host, array $argv, string $payload): array
{
    return run([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ClearAllForwardings=yes', '-o', 'ConnectTimeout=10',
        $host, 'sudo', '-n', 'bash', '-s', '--', ...$argv,
    ], $payload);
}

/**
 * Remove the probe log, and say what the instrument found and removed.
 *
 * ⚠️ STOP RUNS WHENEVER START WAS ATTEMPTED, WHATEVER START RETURNED. `start` can fail after it has written the
 * snippet, armed the dead-man timer and reloaded nginx: its drain wait can expire, and ssh can exit 255 once the
 * remote start has finished. Both were reported as VOID "nothing was installed", and stop never ran — so the
 * probe served on until the dead-man timer fired fifteen minutes later, and a rerun inside that window refused
 * because a probe was already installed. Nothing measured any of it.
 *
 * ⚠️ AND THE VERDICT IS WHAT STOP FOUND. `removed` is the instrument's own proof that the configuration hashes back
 * to its baseline, and `absent` is its answer for a nonce that installed nothing — which is a VOID, since nothing was
 * there to remove. Anything else is a probe that may still be on the server, and a probe left behind is the worst
 * outcome a family that installs one can have, so it is a FAIL of its own rather than a footnote on another verdict.
 *
 * @return array{0: string, 1: string} the outcome, and its reason
 */
function removal(string $host, string $nonce, string $payload): array
{
    [$status, $out, $err] = instrument($host, ['stop', $nonce], $payload);
    $said = [];

    if ($status === 0 && preg_match('/^STATE stop (removed|absent)\b/m', $out, $said) === 1) {
        return $said[1] === 'removed'
            ? ['PASS', $out]
            : ['VOID', 'nothing of the probe was installed, so nothing had to be removed: '.oneLine($out)];
    }

    return ['FAIL', 'THE PROBE LOG WAS NOT REMOVED: '.substr(oneLine($err !== '' ? $err : $out), 0, 300)];
}

/**
 * The running configuration, read from the server rather than supplied to it.
 *
 * ⚠️ `sudo -n nginx -T`, NOT `sudo -n bash -c 'nginx -T | …'`. Wrapped in a shell the dump never
 * arrives: nginx writes nothing to stdout and complains twenty times that it cannot bind, because the
 * running server already holds those listeners. Measured on stage (2026-09-16): the wrapped form gave
 * 0 stdout lines and 21 stderr lines, the direct form 280 lines and exit 0, on the same host in the
 * same minute. The family read the empty stream as "this host serves no site", and voided while naming
 * the host as the reason — a correct verdict with a false explanation.
 *
 * ⚠️ AND THE PARSING HAPPENS HERE, NOT THERE. A remote `awk … | sort -u` puts the one part that can
 * silently return nothing beyond the reach of any test: the stub answered with an already-clean list,
 * so no case could have seen a parsing defect. Parsed in PHP it is ordinary code with ordinary tests.
 *
 * ⚠️ AND STDERR IS THE EVIDENCE. `2>/dev/null` discarded nginx's only account of itself, and the
 * status came from `sort` at the tail of a pipeline rather than from nginx, so a total failure
 * arrived as a successful empty answer. Both are returned now, and an empty dump is VOID with the
 * complaint quoted — never "the host named no site".
 *
 * @return array{0: string, 1: string} the dump, and why there is none when there is none
 */
function nginxDump(string $host): array
{
    [$status, $out, $err] = run(['ssh', '-n', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10',
        $host, 'sudo', '-n', 'nginx', '-T'], '', 60);

    if ($status !== 0 || trim($out) === '') {
        $said = oneLine($err !== '' ? $err : $out);

        return ['', $said === ''
            ? "nginx -T exited {$status} and said nothing, so the configuration could not be read"
            : "nginx -T exited {$status} and said: ".substr($said, 0, 200)];
    }

    return [$out, ''];
}

/**
 * The site hostnames the running configuration names.
 *
 * @return array{0: list<string>, 1: string} the hostnames, and why there are none when there are none
 */
function hostnames(string $host): array
{
    [$out, $unreadable] = nginxDump($host);

    if ($unreadable !== '') {
        return [[], $unreadable];
    }

    $found = [];

    foreach (preg_split('/\R/', $out) ?: [] as $line) {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        if (($fields[0] ?? '') !== 'server_name') {
            continue;
        }

        foreach (array_slice($fields, 1) as $name) {
            $name = rtrim($name, ';');

            // `_` is the catch-all's own name, not a site's, and a request to it proves nothing about
            // the site — the tunnel family fails a line the catch-all answered for exactly that reason.
            if ($name !== '' && $name !== '_') {
                $found[$name] = true;
            }
        }
    }

    return [array_keys($found), ''];
}

/** The address the edge says it saw, from a /cdn-cgi/trace body. */
function traceAddress(string $path): string
{
    $body = @file_get_contents($path);

    if ($body === false) {
        return '';
    }

    foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
        if (str_starts_with($line, 'ip=')) {
            return trim(substr($line, 3));
        }
    }

    return '';
}

// --- end of the shared outside helpers ----------------------------------------------------------------
