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

/** RFC 5737 documentation space: a valid address Symfony keeps, and not routable. */
const SENTINEL_XFF = '192.0.2.77';

/**
 * One line, always: a reason that spans lines would reach run.sh as a verdict followed by something
 * no parser recognises.
 */
function oneLine(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * @param  list<array{0: string, 1: string}>  $verdicts
 */
function verdict(string $id, string $outcome, string $reason, array &$verdicts): void
{
    // ⚠️ THE GUARDS common.sh's verdict() APPLIES. An undeclared id or a second verdict for one check is
    // this runbook's bug, and printing it would let the gate judge a check nobody promised or pick between
    // two. A refusal quotes the verdict it refused, for the reason common.sh gives: a FAIL refused here was
    // otherwise reported nowhere.
    $refused = "verdict {$id} {$outcome} [".oneLine($reason).']';

    if (! in_array($id, CHECKS, true)) {
        refuse("{$refused}: this family did not declare that id");
    }

    if (in_array($id, array_column($verdicts, 0), true)) {
        refuse("{$refused}: emitted twice");
    }

    echo 'VERDICT '.$id.' '.$outcome.' '.oneLine($reason)."\n";
    $verdicts[] = [$id, $outcome];
}

/**
 * ⚠️ A REFUSAL IS WRITTEN INTO THE VERDICT STREAM, AND IT THROWS RATHER THAN EXITS. It throws so the
 * `finally` that removes the probe log still runs: an exit would leave the server changed until the
 * dead-man timer fired. But that `finally` used to close the stream too, with a sentinel naming the
 * verdicts already accepted, so a refused verdict after TUN-1's was simply absent: the count added up,
 * and run.sh passed the run and deleted its streams, with the refusal only on stderr, which it does not
 * judge. The exit status could not have said it either — a FAIL exits 1 too. So the stream says
 * `REFUSED`, which voids the whole family, and the main path withholds the sentinel after one.
 */
function refuse(string $reason): never
{
    echo 'REFUSED '.FAMILY.' '.oneLine($reason)."\n";

    throw new LogicException('Refusing to check: '.$reason);
}

function record(string $id, string $fact): void
{
    echo 'RECORD '.$id.' '.oneLine($fact)."\n";
}

/**
 * @param  list<array{0: string, 1: string}>  $verdicts
 */
function sentinel(array $verdicts): void
{
    $ids = array_map(static fn (array $verdict): string => $verdict[0], $verdicts);

    echo 'SENTINEL '.FAMILY.' '.count($verdicts).' '.implode(' ', $ids)."\n";
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
        return [255, '', 'could not start '.($command[0] ?? '?')];
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
 * Drive the probe-log instrument on the host, sending it exactly as run.sh sends a family: common.sh
 * and the instrument concatenated on stdin, under `sudo -n bash -s`.
 *
 * @param  list<string>  $argv
 * @param  list<string>  $files
 * @return array{0: int, 1: string, 2: string}
 */
function instrument(string $host, array $argv, array $files): array
{
    $payload = '';

    foreach ($files as $file) {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return [255, '', "could not read {$file}"];
        }

        $payload .= $contents;
    }

    return run([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ClearAllForwardings=yes', '-o', 'ConnectTimeout=10',
        $host, 'sudo', '-n', 'bash', '-s', '--', ...$argv,
    ], $payload);
}

/**
 * The site hostnames, read from the running configuration rather than supplied to it.
 *
 * ⚠️ `sudo -n nginx -T`, NOT `sudo -n bash -c 'nginx -T | …'`. Wrapped in a shell the dump never
 * arrives: nginx writes nothing to stdout and complains twenty times that it cannot bind, because the
 * running server already holds those listeners. Measured on stage (2026-09-16): the wrapped form gave
 * 0 stdout lines and 21 stderr lines, the direct form 280 lines and exit 0, on the same host in the
 * same minute. The family read the empty stream as "this host serves no site", and voided TUN-1 while
 * naming the host as the reason — a correct verdict with a false explanation.
 *
 * ⚠️ AND THE FILTERING HAPPENS HERE, NOT THERE. A remote `awk … | sort -u` puts the one part that can
 * silently return nothing beyond the reach of any test: the stub answered with an already-clean list,
 * so no case could have seen a parsing defect. Parsed in PHP it is ordinary code with ordinary tests.
 *
 * ⚠️ AND STDERR IS THE EVIDENCE. `2>/dev/null` discarded nginx's only account of itself, and the
 * status came from `sort` at the tail of a pipeline rather than from nginx, so a total failure
 * arrived as a successful empty answer. Both are returned now, and an empty dump is VOID with the
 * complaint quoted — never "the host named no site".
 *
 * @return array{0: list<string>, 1: string} the hostnames, and why there are none when there are none
 */
function hostnames(string $host): array
{
    [$status, $out, $err] = run(['ssh', '-n', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10',
        $host, 'sudo', '-n', 'nginx', '-T'], '', 60);

    if ($status !== 0 || trim($out) === '') {
        $said = oneLine($err !== '' ? $err : $out);

        return [[], $said === ''
            ? "nginx -T exited {$status} and said nothing, so the configuration could not be read"
            : "nginx -T exited {$status} and said: ".substr($said, 0, 200)];
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
            // the site — judge() fails a line the catch-all answered for exactly that reason.
            if ($name !== '' && $name !== '_') {
                $found[$name] = true;
            }
        }
    }

    return [array_keys($found), ''];
}

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
 * @param  list<string>  $files
 * @return array{0: list<string>, 1: list<string>} fails, then voids
 */
function checkHostname(string $host, string $hostname, string $nonce, int $index, array $files, string $work): array
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

    [$status, $out, $err] = instrument($host, ['collect', $nonce, $probeId], $files);

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
$options = getopt('', ['host:', 'expect:', 'token-file:', 'runbook:']);
$host = is_string($options['host'] ?? null) ? $options['host'] : '';
$expect = is_string($options['expect'] ?? null) ? $options['expect'] : '';
$runbook = is_string($options['runbook'] ?? null) ? $options['runbook'] : dirname(__DIR__);

if ($host === '' || ! in_array($expect, ['tunnel', 'dns-only'], true)) {
    fwrite(STDERR, "Refusing to check: --host and --expect tunnel|dns-only are required\n");
    exit(1);
}

$files = [$runbook.'/host/common.sh', $runbook.'/host/probe-log.sh'];
$verdicts = [];

foreach ($files as $file) {
    if (! is_file($file)) {
        verdict('TUN-1', 'VOID', "the instrument is missing at {$file}", $verdicts);
        verdict('TUN-2', 'VOID', 'nothing was installed, so nothing had to be removed', $verdicts);
        sentinel($verdicts);
        exit(1);
    }
}

if ($expect !== 'tunnel') {
    // alpha's shape is its own family's to judge. Saying so beats a silent skip, which the
    // completeness gate would read as a family that died.
    verdict('TUN-1', 'VOID', 'this family checks what a tunnel delivers, and the topology is dns-only', $verdicts);
    verdict('TUN-2', 'VOID', 'no probe was installed on a host this family does not check', $verdicts);
    sentinel($verdicts);
    exit(1);
}

$nonce = bin2hex(random_bytes(16));
[$status, $out, $err] = instrument($host, ['start', $nonce], $files);

if ($status !== 0) {
    verdict('TUN-1', 'VOID', 'the probe log could not be installed: '.substr(oneLine($err !== '' ? $err : $out), 0, 300), $verdicts);
    verdict('TUN-2', 'VOID', 'nothing was installed, so nothing had to be removed', $verdicts);
    sentinel($verdicts);
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
        [$siteFails, $siteVoids] = checkHostname($host, $hostname, $nonce, $index + 1, $files, $work);
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

    [$status, $out, $err] = instrument($host, ['stop', $nonce], $files);

    if ($status !== 0) {
        // ⚠️ A PROBE LEFT BEHIND IS THE WORST OUTCOME HERE, so it is a FAIL of its own rather than a
        // footnote: the operator has to know the server is not as it was.
        verdict('TUN-2', 'FAIL', 'THE PROBE LOG WAS NOT REMOVED: '.substr(oneLine($err !== '' ? $err : $out), 0, 300), $verdicts);
    } else {
        verdict('TUN-2', 'PASS', $out, $verdicts);
    }

    // ⚠️ NO SENTINEL AFTER A REFUSAL (see refuse()). A refusal here, in TUN-2's own verdict, never reaches
    // this line; one from `try` is held above, and would otherwise be closed over as if it had not happened.
    if ($refusal === null) {
        sentinel($verdicts);
    }
}

if ($refusal !== null) {
    throw $refusal;
}

$passed = array_filter($verdicts, static fn (array $verdict): bool => $verdict[1] === 'PASS');

exit(count($passed) === count($verdicts) ? 0 : 1);
