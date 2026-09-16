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
 * The tunnel-hostname family of the ADR-034 runbook — deploy/runbook/outside/tunnel-log.php — run for
 * real with ssh and curl stubbed (issue #111).
 *
 * ⚠️ THIS FAMILY DECIDES WHETHER ADR-034'S TRUST IS SOUND ON A LIVE HOST. It asks what nginx actually
 * received for a request that crossed Cloudflare: the peer it saw, the hostname, the scheme, and whose
 * entry ends the forwarded chain. Every case below is either a way that answer can be wrong, or a way
 * the check could believe it without having measured it.
 *
 * ⚠️ AND MOST OF THE VOIDS MATTER MORE THAN THE FAILS. A one-entry X-Forwarded-For, a trace the edge
 * answered differently before and after, a second connection opened mid-run: each would let "the last
 * entry is the requester" be true of a path that never carried a chain at all. Those are unmeasurable,
 * not passing, and the run exits non-zero for them exactly as for a failure.
 *
 * Needs only PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-tunlog-'.bin2hex(random_bytes(6));
    $this->family = $repo.'/deploy/runbook/outside/tunnel-log.php';

    File::makeDirectory($this->dir.'/bin', 0755, true);
    File::makeDirectory($this->dir.'/runbook/host', 0755, true);

    // The instrument the family sends to the host. Its bytes are only read and piped, and the stubbed
    // ssh discards them, so a marker is enough to prove the family looked for the real files.
    File::put($this->dir.'/runbook/host/common.sh', "# common.sh\n");
    File::put($this->dir.'/runbook/host/probe-log.sh', "# probe-log.sh\n");

    tunnelLogFixture($this->dir, 'hostnames', "stage.kitsune.test\n");
    tunnelLogFixture($this->dir, 'trace-before', "fl=1\nip=203.0.113.50\nts=1\n");
    tunnelLogFixture($this->dir, 'trace-after', "fl=1\nip=203.0.113.50\nts=2\n");
    tunnelLogFixture($this->dir, 'stats', "200 1 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n");
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine()) ?: '{}');
    tunnelLogFixture($this->dir, 'owner', 'ESTAB 0 0 127.0.0.1:443 127.0.0.1:35572 users:(("cloudflared",pid=26476,fd=10))');

    foreach (tunnelLogStubs() as $name => $body) {
        File::put($this->dir.'/bin/'.$name, $body);
        chmod($this->dir.'/bin/'.$name, 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * The probe line as nginx would have written it for a compliant host.
 *
 * @param  array<string, string>  $changes
 * @return array<string, string>
 */
function tunnelLogLine(array $changes = []): array
{
    return array_replace([
        'pid' => '29271',
        'remote_addr' => '127.0.0.1',
        'remote_port' => '35572',
        'realip_remote_addr' => '127.0.0.1',
        'proxy_protocol_addr' => '',
        'host' => 'stage.kitsune.test',
        'http_host' => 'stage.kitsune.test',
        'server_name' => 'stage.kitsune.test',
        'server_port' => '443',
        'https' => 'on',
        'ssl_server_name' => 'stage.kitsune.test',
        'request_method' => 'GET',
        'request_uri' => '/kitsune-runbook-probe',
        'status' => '404',
        'upstream_addr' => 'unix:/run/php/php8.5-fpm.sock',
        'upstream_status' => '404',
        'xff' => '192.0.2.77, 203.0.113.50',
        'cf_connecting_ip' => '203.0.113.50',
        'cf_ray' => 'a3bb0208bab4efad-CMH',
        'probe' => 'probe',
    ], $changes);
}

/** Write one fixture file the stubs read. */
function tunnelLogFixture(string $dir, string $name, string $contents): void
{
    File::put($dir.'/'.$name, $contents);
}

/**
 * The stubs. They answer from fixture files, so a case rewrites a fixture rather than a stub.
 *
 * @return array<string, string>
 */
function tunnelLogStubs(): array
{
    return [
        // ssh carries three shapes: the hostname query (bash -c), and the instrument's start, collect
        // and stop (bash -s -- <action> <nonce> [id]). Marker files make any of them fail.
        'ssh' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        args="$*"
        cat > /dev/null

        case "$args" in
          *"bash -c"*)
            [[ -e "$d/hostnames-fail" ]] && exit 1
            cat "$d/hostnames"
            ;;
          *" start "*)
            [[ -e "$d/start-fail" ]] && { echo "Refusing to check: a probe is already installed" >&2; exit 1; }
            echo "STATE start installed, reloaded, drained in 0s; workers now 29271"
            ;;
          *" collect "*)
            [[ -e "$d/collect-fail" ]] && { echo "Refusing to check: no line carries the id" >&2; exit 1; }
            id=${args##* }
            echo "PROBE $id $(cat "$d/probe")"
            echo "PROBE-OWNER $id $(cat "$d/owner")"
            echo "STATE collect one line"
            ;;
          *" stop "*)
            [[ -e "$d/stop-fail" ]] && { echo "Refusing to check: the configuration does not hash back" >&2; exit 1; }
            echo "STATE stop removed, dead-man cancelled, configuration hashes back to its baseline"
            ;;
        esac
        exit 0
        BASH,
        // curl writes each body to the file it was given and prints the three -w lines, as the real one
        // does with --next.
        'curl' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        [[ -e "$d/curl-fail" ]] && exit 7

        outs=()
        prev=""
        for arg in "$@"; do
          [[ "$prev" == "-o" ]] && outs+=("$arg")
          prev="$arg"
        done

        [[ ${#outs[@]} -ge 1 ]] && cp "$d/trace-before" "${outs[0]}"
        [[ ${#outs[@]} -ge 2 ]] && echo "not found" > "${outs[1]}"
        [[ ${#outs[@]} -ge 3 ]] && cp "$d/trace-after" "${outs[2]}"

        cat "$d/stats"
        BASH,
    ];
}

/**
 * Run the family against the fixture tree.
 *
 * @param  array<string, string|false>  $env
 */
function tunnelLogRun(string $dir, string $family, string $expect = 'tunnel', array $env = []): Process
{
    $process = new Process(
        ['php', $family, '--host', 'forge@fixture', '--expect', $expect, '--runbook', $dir.'/runbook'],
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

/** The verdict line for one check id. */
function tunnelLogVerdict(Process $run, string $id): string
{
    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'VERDICT '.$id.' ')) {
            return $line;
        }
    }

    return '';
}

it('is valid PHP', function (): void {
    $check = new Process(['php', '-l', dirname(__DIR__, 3).'/deploy/runbook/outside/tunnel-log.php']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getOutput())->toContain('No syntax errors');
});

it('passes a hostname whose request arrived as the connector on loopback', function (): void {
    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('PASS')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS')
        ->and($run->getOutput())->toContain('SENTINEL tunnel-log 2 TUN-1 TUN-2');
});

it('says so plainly on a host with no tunnel, rather than skipping', function (): void {
    $run = tunnelLogRun($this->dir, $this->family, 'dns-only');

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('dns-only')
        ->and($run->getOutput())->toContain('SENTINEL tunnel-log 2');
});

it('voids both checks when the probe log could not be installed', function (): void {
    touch($this->dir.'/start-fail');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('could not be installed')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('nothing was installed');
});

it('fails loudly when the probe log was left behind', function (): void {
    /*
     * ⚠️ THE WORST OUTCOME THIS FAMILY CAN HAVE. Everything else is a report about the host; this is
     * the runbook having changed a live server and not changed it back, so it is a FAIL of its own
     * rather than a footnote on another verdict.
     */
    touch($this->dir.'/stop-fail');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('FAIL')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('NOT REMOVED');
});

it('voids a run whose transfers were not one connection through the edge', function (string $stats, string $named): void {
    /*
     * ⚠️ "THROUGH THE EDGE" IS NOT A RESPONSE HEADER. server: cloudflare and cf-ray are writable by any
     * origin; what an origin cannot do is serve /cdn-cgi/trace on the same connection as the request.
     */
    tunnelLogFixture($this->dir, 'stats', $stats);

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain($named);
})->with([
    'a new connection mid-run' => [
        "200 1 192.168.1.5 51000 104.18.0.1\n200 1 192.168.1.5 51002 104.18.0.1\n200 0 192.168.1.5 51002 104.18.0.1\n",
        'opened a new connection',
    ],
    'the local address moved' => [
        "200 1 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.9 51009 104.18.0.1\n",
        'local address moved',
    ],
    'a transfer missing' => [
        "200 1 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n",
        'three transfers did not complete',
    ],
]);

it('voids a run where the edge changed its view of the requester', function (): void {
    tunnelLogFixture($this->dir, 'trace-after', "fl=1\nip=203.0.113.99\nts=2\n");

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('203.0.113.99');
});

it('voids a forwarded chain that does not show whose entry wins', function (string $xff, string $named): void {
    /*
     * ⚠️ THE ARRIVAL ASSERTION. With one entry, "the last entry is the requester" is true of a request
     * that never carried a chain — and Cloudflare's Pseudo IPv4 and its remove-visitor-IP transform
     * both produce exactly that. Unmeasurable, not passing.
     */
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine(['xff' => $xff])) ?: '{}');

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain($named);
})->with([
    'one entry only' => ['203.0.113.50', 'did not survive'],
    'the sentinel rewritten' => ['198.51.100.1, 203.0.113.50', 'rather than the sentinel'],
    'no header at all' => ['', 'did not survive'],
]);

it('fails what the host got wrong', function (array $changes, string $named): void {
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine($changes)) ?: '{}');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('FAIL')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain($named);
})->with([
    // A mapped address means a dual-stack listener, which ADR-034's trusted list does not match.
    'a mapped loopback peer' => [['remote_addr' => '::ffff:127.0.0.1', 'realip_remote_addr' => '::ffff:127.0.0.1'], 'not exactly 127.0.0.1'],
    'realip resolved the peer from a header' => [['realip_remote_addr' => '203.0.113.50'], 'realip_remote_addr'],
    'the PROXY protocol in front' => [['proxy_protocol_addr' => '203.0.113.50'], 'PROXY protocol'],
    'the catch-all answered' => [['server_name' => '_'], 'catch-all'],
    'TLS did not terminate here' => [['https' => ''], 'TLS did not terminate here'],
    'nginx answered, not PHP' => [['upstream_addr' => ''], 'PHP did not answer'],
    'the last entry is not what the edge saw' => [['xff' => '192.0.2.77, 198.51.100.9'], 'the edge saw'],
]);

it('fails a connection owned by something other than a connector', function (): void {
    tunnelLogFixture($this->dir, 'owner', 'ESTAB 0 0 127.0.0.1:443 127.0.0.1:41000 users:(("sshd",pid=99,fd=11))');

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('FAIL')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('rather than a tunnel connector');
});

it('voids a line it could not tie to any socket', function (): void {
    tunnelLogFixture($this->dir, 'owner', 'none');

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('no socket owner');
});

it('voids a host that named no site, and still removes the probe', function (): void {
    tunnelLogFixture($this->dir, 'hostnames', "\n");

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('no site hostname')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS');
});

it('refuses without the instrument it drives, and looks for it where it was told to', function (): void {
    /*
     * ⚠️ THE PATH IN THE MESSAGE IS THE ASSERTION. `--runbook` was declared to getopt with a double
     * colon, which accepts only `--runbook=value`; given the space-separated form it returned false and
     * this fell back to its own directory — so it checked the repository's instrument, found it, and
     * ran the whole family against the wrong tree. Asserting only "the run failed" would have passed
     * the moment anything else voided, which is how that went unnoticed.
     */
    File::delete($this->dir.'/runbook/host/probe-log.sh');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('the instrument is missing')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain($this->dir.'/runbook/host/probe-log.sh')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('nothing was installed')
        ->and($run->getOutput())->not->toContain('PROBE ');
});
