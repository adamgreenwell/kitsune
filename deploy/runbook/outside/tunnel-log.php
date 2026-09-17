<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * What the web server received, for a request that crossed the real edge — issue #111.
 *
 * ADR-034 lets the skeleton trust `127.0.0.1` for `X-Forwarded-For`. This checks that a request made
 * the way a visitor makes one arrives at nginx as that peer, with the address Cloudflare saw as the
 * last forwarded entry and the requested hostname intact.
 *
 * ⚠️ IT RUNS ON THE OPERATOR'S MACHINE, NOT ON THE HOST, AND THAT IS THE POINT. A request made on the
 * server would traverse neither the edge nor the tunnel. So this drives the probe log over ssh, sends
 * the requests itself, and reads back the lines nginx wrote.
 *
 * ⚠️ THE FORGED ENTRY IS WHAT MAKES "THE LAST ENTRY" MEAN ANYTHING. Every request carries a forged
 * left-hand `X-Forwarded-For`, and this requires it to *arrive*: at least two comma-separated tokens
 * whose first is exactly the sentinel. Without that, "the last entry is the requester" is true of a
 * one-entry header — a tautology. Cloudflare's Pseudo IPv4 and its "remove visitor IP headers"
 * transform both rewrite that header, so an absent sentinel is VOID and never a pass.
 *
 * ⚠️ AND "THROUGH THE EDGE" IS NOT A RESPONSE HEADER. `server: cloudflare` and `cf-ray` are writable
 * by any origin. The proof is that `/cdn-cgi/trace` — which the edge serves and an origin cannot —
 * answered on the *same connection* as the request, before and after it, with the same local address
 * and port and no new connection in between.
 *
 * ⚠️ TWO CHECK IDS, NOT ONE PER HOSTNAME. The manifest is static and committed, and a server's
 * hostnames are a property of that server, so a per-hostname id could never be promised in advance.
 * TUN-1 covers the requests across every hostname the server serves — one failure fails it, and each
 * hostname's detail is recorded — and TUN-2 covers the probe log being removed afterwards.
 */

const FAMILY = 'tunnel-log';

/**
 * Every check this family can report, and nothing else — the outside counterpart of a host family's
 * `family` line. verdict() refuses any other id, and RunbookManifestTest holds this list to the rows
 * manifest.txt promises the family.
 */
const CHECKS = ['TUN-1', 'TUN-2'];

/**
 * The topologies this family runs on — the counterpart of a host family's `topologies` line, and the answer to
 * "which rows may manifest.txt hold for it", which RunbookManifestTest holds this file to. Read from the manifest
 * instead, a family's whole block could be deleted and nothing would say it was missing. alpha has no tunnel, so
 * on any other topology this voids its checks rather than skipping, which the gate would read as a family that died.
 */
const TOPOLOGIES = ['tunnel'];

/** RFC 5737 documentation space: a valid address Symfony keeps, and not routable. */
const SENTINEL_XFF = '192.0.2.77';

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

/**
 * One connection: trace, the request, trace again — and the facts that prove it was one connection.
 *
 * ⚠️ EACH BODY GOES TO ITS OWN FILE so stdout carries nothing but the three `-w` lines; with `-o -`
 * the bodies and the statistics interleave and telling them apart is guesswork.
 *
 * @return array{0: int, 1: list<list<string>>, 2: array{before: string, request: string, after: string}, 3: string}
 */
function curlProbe(string $hostname, string $probeId, string $path, string $work): array
{
    $trace = 'https://'.$hostname.'/cdn-cgi/trace';
    $stats = '%{http_code} %{num_connects} %{local_ip} %{local_port} %{remote_ip}\n';
    $paths = [
        'before' => $work.'/trace-before',
        'request' => $work.'/request',
        'after' => $work.'/trace-after',
    ];

    [$status, $out, $err] = run([
        'curl', '-4', '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['before'], $trace,
        '--next', '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['request'],
        '-H', 'X-Kitsune-Probe: '.$probeId,
        '-H', 'X-Forwarded-For: '.SENTINEL_XFF,
        'https://'.$hostname.$path,
        '--next', '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['after'], $trace,
    ], '', 90);

    $rows = [];

    foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $row) {
        if (trim($row) !== '') {
            $rows[] = preg_split('/\s+/', trim($row)) ?: [];
        }
    }

    return [$status, $rows, $paths, $err];
}

/**
 * Every rule the probe line must satisfy.
 *
 * @param  array<string, string>  $line
 * @return array{0: list<string>, 1: list<string>} fails, then voids
 */
function judge(array $line, string $hostname, string $edgeAddress, string $owner): array
{
    $fails = [];
    $voids = [];
    $field = static fn (string $key): string => $line[$key] ?? '';

    // The peer nginx saw. `::1` and any `::ffff:` form fail: the connector's service is
    // https://127.0.0.1:443, and a mapped address means a dual-stack listener ADR-034 forbids.
    if ($field('remote_addr') !== '127.0.0.1') {
        $fails[] = "{$hostname}: remote_addr is [{$field('remote_addr')}], not exactly 127.0.0.1";
    }

    if ($field('realip_remote_addr') !== $field('remote_addr')) {
        $fails[] = "{$hostname}: realip_remote_addr [{$field('realip_remote_addr')}] differs from remote_addr "
            ."[{$field('remote_addr')}], so a realip directive resolved the peer from a header";
    }

    if ($field('proxy_protocol_addr') !== '') {
        $fails[] = "{$hostname}: proxy_protocol_addr is [{$field('proxy_protocol_addr')}], so something speaks the PROXY protocol to nginx";
    }

    if ($field('host') !== $hostname || $field('http_host') !== $hostname) {
        $fails[] = "{$hostname}: it arrived for host [{$field('host')}]/[{$field('http_host')}]";
    }

    if (in_array($field('server_name'), ['_', ''], true)) {
        $fails[] = "{$hostname}: the catch-all answered (server_name [{$field('server_name')}]), not the site";
    }

    if ($field('server_port') !== '443' || $field('https') !== 'on') {
        $fails[] = "{$hostname}: it arrived on port [{$field('server_port')}] with https=[{$field('https')}], so TLS did not terminate here";
    }

    // PHP answered rather than nginx short-circuiting: the path is unrouted, so Laravel's own fallback
    // produces the 404 — which only happens if the request reached PHP at all.
    if (preg_match('#^unix:/.*\.sock$#', $field('upstream_addr')) !== 1) {
        $fails[] = "{$hostname}: upstream_addr is [{$field('upstream_addr')}], so PHP did not answer";
    }

    if ($field('status') !== '404' || $field('upstream_status') !== '404') {
        $voids[] = "{$hostname}: it answered {$field('status')}/{$field('upstream_status')} rather than 404, "
            .'so it did not reach the point this judges';
    }

    // ⚠️ THE ARRIVAL ASSERTION. Without it, "the last entry is the requester" is true of a one-entry
    // header, and this would pass over a path that never carried a forwarded chain at all.
    $tokens = array_values(array_filter(array_map('trim', explode(',', $field('xff'))), static fn (string $t): bool => $t !== ''));

    if (count($tokens) < 2) {
        $voids[] = "{$hostname}: X-Forwarded-For arrived as [{$field('xff')}], so the forged entry did not survive "
            .'and this path does not show whose entry wins';
    } elseif ($tokens[0] !== SENTINEL_XFF) {
        $voids[] = "{$hostname}: the first X-Forwarded-For entry is [{$tokens[0]}] rather than the sentinel "
            .SENTINEL_XFF.', so something rewrote the chain';
    } elseif (end($tokens) !== $edgeAddress) {
        $fails[] = "{$hostname}: the last X-Forwarded-For entry is [".end($tokens)."] and the edge saw [{$edgeAddress}]";
    }

    if ($owner === 'none') {
        $voids[] = "{$hostname}: no socket owner was found, so the line cannot be tied to the connector";
    } elseif (! str_contains($owner, 'cloudflared')) {
        $fails[] = "{$hostname}: the connection was owned by [".substr(trim($owner), 0, 120).'] rather than a tunnel connector';
    }

    return [$fails, $voids];
}

/**
 * One hostname: request it through the edge, collect its line, judge it.
 *
 * @return array{0: list<string>, 1: list<string>} fails, then voids
 */
function checkHostname(string $host, string $hostname, string $nonce, int $index, string $payload, string $work): array
{
    $probeId = $nonce.'-'.$index;
    [$status, $rows, $paths, $curlError] = curlProbe($hostname, $probeId, '/kitsune-runbook-'.$probeId, $work);

    if ($status !== 0 || count($rows) !== 3) {
        return [[], ["{$hostname}: the three transfers did not complete ({$status}): ".substr(oneLine($curlError), 0, 200)]];
    }

    foreach ([$rows[1], $rows[2]] as $row) {
        if (($row[1] ?? '') !== '0') {
            return [[], ["{$hostname}: a transfer after the first opened a new connection, so these were not one connection through the edge"]];
        }
    }

    if (array_slice($rows[0], 2, 2) !== array_slice($rows[2], 2, 2)) {
        return [[], ["{$hostname}: the local address moved between the first and last transfer, so these were not one connection"]];
    }

    $before = traceAddress($paths['before']);
    $after = traceAddress($paths['after']);

    if ($before === '' || $before !== $after) {
        return [[], ["{$hostname}: the edge reported the requester as [{$before}] then [{$after}]"]];
    }

    [$status, $out, $err] = instrument($host, ['collect', $nonce, $probeId], $payload);

    if ($status !== 0) {
        return [[], ["{$hostname}: the probe line could not be collected: ".substr(oneLine($err !== '' ? $err : $out), 0, 200)]];
    }

    $line = [];
    $owner = 'none';

    foreach (preg_split('/\r?\n/', $out) ?: [] as $row) {
        if (str_starts_with($row, 'PROBE '.$probeId.' ')) {
            $decoded = json_decode(substr($row, strlen('PROBE '.$probeId.' ')), true);
            $line = is_array($decoded) ? array_map('strval', $decoded) : [];
        } elseif (str_starts_with($row, 'PROBE-OWNER '.$probeId.' ')) {
            $owner = substr($row, strlen('PROBE-OWNER '.$probeId.' '));
        }
    }

    if ($line === []) {
        return [[], ["{$hostname}: the instrument printed no line for {$probeId}"]];
    }

    record('TUN-1', "{$hostname}: edge saw {$before}, nginx logged remote_addr ".($line['remote_addr'] ?? '?')
        .' xff ['.($line['xff'] ?? '').'] status '.($line['status'] ?? '?').' upstream '.($line['upstream_addr'] ?? '?'));

    return judge($line, $hostname, $before, $owner);
}

// --- the run ---------------------------------------------------------------------------------------

// stdout is the verdict stream run.sh judges; an uncaught refusal belongs with the other complaints.
ini_set('display_errors', 'stderr');

// ⚠️ ONE COLON, NOT TWO, EVEN FOR AN OPTIONAL OPTION. A double colon means the VALUE is optional, and
// getopt then accepts only `--runbook=value`: given `--runbook /path` it returns false, and this fell
// back to its own directory, ran against the repository's instrument instead of the one it was told
// to use, and reported a pass. One colon keeps the option optional while letting its value be
// space-separated, which is how run.sh and the tests pass it.
$options = getopt('', ['host:', 'expect:', 'token-file:', 'egress-trace:', 'runbook:']);
$host = is_string($options['host'] ?? null) ? $options['host'] : '';
$expect = is_string($options['expect'] ?? null) ? $options['expect'] : '';
$runbook = is_string($options['runbook'] ?? null) ? $options['runbook'] : dirname(__DIR__);

if ($host === '' || ! in_array($expect, ['tunnel', 'dns-only'], true)) {
    fwrite(STDERR, "Refusing to check: --host and --expect tunnel|dns-only are required\n");
    exit(1);
}

$files = [$runbook.'/host/common.sh', $runbook.'/host/probe-log.sh'];
$verdicts = [];
$payload = '';

foreach ($files as $file) {
    $contents = @file_get_contents($file);

    if ($contents === false) {
        verdict('TUN-1', 'VOID', is_file($file)
            ? "the instrument at {$file} could not be read"
            : "the instrument is missing at {$file}", $verdicts);
        verdict('TUN-2', 'VOID', 'nothing was installed: nothing was sent to the host', $verdicts);
        sentinel(FAMILY, $verdicts);
        exit(1);
    }

    $payload .= $contents;
}

if (! in_array($expect, TOPOLOGIES, true)) {
    // alpha's shape is its own family's to judge. Saying so beats a silent skip, which the
    // completeness gate would read as a family that died.
    verdict('TUN-1', 'VOID', "this family checks what a tunnel delivers, and the topology is {$expect}", $verdicts);
    verdict('TUN-2', 'VOID', 'no probe was installed on a host this family does not check', $verdicts);
    sentinel(FAMILY, $verdicts);
    exit(1);
}

$nonce = bin2hex(random_bytes(16));
[$status, $out, $err] = instrument($host, ['start', $nonce], $payload);

if ($status !== 0) {
    // The start may have installed the probe before it failed, so TUN-2 is what stop finds (see removal).
    verdict('TUN-1', 'VOID', 'the probe log did not start: '.substr(oneLine($err !== '' ? $err : $out), 0, 300), $verdicts);
    [$outcome, $reason] = $status === NOT_STARTED
        ? ['VOID', 'nothing was installed: nothing was sent to the host']
        : removal($host, $nonce, $payload);
    verdict('TUN-2', $outcome, $reason, $verdicts);
    sentinel(FAMILY, $verdicts);
    exit(1);
}

record('TUN-1', $out);

$work = sys_get_temp_dir().'/kitsune-tunnel-log-'.$nonce;
mkdir($work, 0700, true);

$fails = [];
$voids = [];
$refusal = null;

try {
    [$sites, $unreadable] = hostnames($host);

    if ($unreadable !== '') {
        $voids[] = 'the running configuration could not be read, so there was nothing to request: '.$unreadable;
    } elseif ($sites === []) {
        $voids[] = 'no site hostname was found in the running configuration, so there was nothing to request';
    }

    foreach ($sites as $index => $hostname) {
        [$siteFails, $siteVoids] = checkHostname($host, $hostname, $nonce, $index + 1, $payload, $work);
        $fails = [...$fails, ...$siteFails];
        $voids = [...$voids, ...$siteVoids];
    }

    if ($fails !== []) {
        verdict('TUN-1', 'FAIL', implode('; ', $fails), $verdicts);
    } elseif ($voids !== []) {
        verdict('TUN-1', 'VOID', implode('; ', $voids), $verdicts);
    } else {
        verdict('TUN-1', 'PASS', 'every one of '.implode(', ', $sites).' arrived from 127.0.0.1 with TLS terminated here, '
            .'answered by PHP, and the last forwarded entry as the edge saw it', $verdicts);
    }
} catch (LogicException $refused) {
    // Held, not lost: the probe is still removed and TUN-2 still reported, and then it is thrown again.
    $refusal = $refused;
} finally {
    foreach (glob($work.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($work);

    [$outcome, $reason] = removal($host, $nonce, $payload);
    verdict('TUN-2', $outcome, $reason, $verdicts);

    // ⚠️ NO SENTINEL AFTER A REFUSAL (see refuse()). A refusal here, in TUN-2's own verdict, never reaches
    // this line; one from `try` is held above, and would otherwise be closed over as if it had not happened.
    if ($refusal === null) {
        sentinel(FAMILY, $verdicts);
    }
}

if ($refusal !== null) {
    throw $refusal;
}

$passed = array_filter($verdicts, static fn (array $verdict): bool => $verdict[1] === 'PASS');

exit(count($passed) === count($verdicts) ? 0 : 1);
