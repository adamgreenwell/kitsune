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

    tunnelLogFixture($this->dir, 'hostnames', tunnelLogDump());
    tunnelLogFixture($this->dir, 'trace-before', "fl=1\nip=203.0.113.50\nts=1\n");
    tunnelLogFixture($this->dir, 'trace-after', "fl=1\nip=203.0.113.50\nts=2\n");
    tunnelLogFixture($this->dir, 'stats', "200 1 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n200 0 192.168.1.5 51000 104.18.0.1\n");
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine()) ?: '{}');
    // ⚠️ THE DIALER'S ROW, AS THE HOST WRITES IT. This fixture used to read `127.0.0.1:443
    // 127.0.0.1:35572 … cloudflared` — the web server's end of the connection, attributed to the
    // connector. No host emits that: on the `:443` side the owner is nginx. The fiction is what let
    // probe-log.sh pick the wrong end and still pass every test, until stage failed TUN-1 naming nginx.
    tunnelLogFixture($this->dir, 'owner', '0      0      127.0.0.1:35572 127.0.0.1:443 users:(("cloudflared",pid=26476,fd=10))');

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
 * A configuration dump as `nginx -T` writes one: file banners, the catch-all, and the site on both
 * ports. The names repeat across the two blocks because they do on a real host, which is what makes
 * collapsing them part of the measurement rather than an accident of the fixture.
 */
function tunnelLogDump(string $siteNames = 'stage.kitsune.test'): string
{
    $site = $siteNames === '' ? '' : <<<CONF
        # configuration file /etc/nginx/sites-enabled/stage.kitsune.test:
        server {
            listen 80;
            server_name {$siteNames};
        }
        server {
            listen 443 ssl;
            server_name {$siteNames};
        }

        CONF;

    return <<<CONF
        # configuration file /etc/nginx/nginx.conf:
        http {
            include /etc/nginx/mime.types;
        }

        # configuration file /etc/nginx/sites-enabled/000-catch-all:
        server {
            listen 80 default_server;
            listen 443 ssl default_server;
            server_name _;
        }

        {$site}
        CONF;
}

/**
 * The stubs. They answer from fixture files, so a case rewrites a fixture rather than a stub.
 *
 * @return array<string, string>
 */
function tunnelLogStubs(): array
{
    return [
        // ssh carries three shapes: the configuration dump (nginx -T), and the instrument's start,
        // collect and stop (bash -s -- <action> <nonce> [id]). Marker files make any of them fail.
        //
        // ⚠️ THE DUMP IS A DUMP, not a list of hostnames. It used to answer the remote `awk … | sort -u`
        // with names already extracted, which put the one step that can silently return nothing beyond
        // any test's reach: on stage the real command produced no stdout at all and twenty bind
        // complaints on stderr, and the family reported that as a host serving no site. A stub tidier
        // than the tool cannot see the defect, so this one answers as nginx does.
        'ssh' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        args="$*"
        cat > /dev/null

        case "$args" in
          *"nginx -T"*)
            if [[ -e "$d/hostnames-fail" ]]; then
              # How it fails on a live host: nothing on stdout, the reason on stderr.
              echo "nginx: [emerg] bind() to 0.0.0.0:443 failed (98: Address already in use)" >&2
              echo "nginx: [emerg] still could not bind()" >&2
              exit 1
            fi
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

        # Record the hostname of the PROBE request only — the two /cdn-cgi/trace transfers go to the
        # same host, so logging every URL would count each hostname three times and say nothing about
        # how many distinct names the family extracted.
        for arg in "$@"; do
          case "$arg" in
            https://*/kitsune-runbook-*)
              rest=${arg#https://}
              echo "${rest%%/*}" >> "$d/requested"
              ;;
          esac
        done

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
    // A dump that read perfectly well and named only the catch-all: the host serves no site.
    tunnelLogFixture($this->dir, 'hostnames', tunnelLogDump(''));

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('no site hostname')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS');
});

it('voids a dump it could not read, and says what nginx said, rather than blaming the host', function (): void {
    /*
     * ⚠️ THIS IS THE ONE THAT SHIPPED. `hostnames()` ran `sudo -n bash -c 'nginx -T 2>/dev/null | awk …'`,
     * and wrapped in a shell the dump never arrives: nginx writes nothing to stdout and complains that it
     * cannot bind, because the running server holds those listeners. Measured on stage (2026-09-16): the
     * wrapped form 0 stdout lines and 21 on stderr, the direct form 280 and exit 0, same host, same minute.
     * `2>/dev/null` threw away the explanation and the status came from `sort`, so a total failure arrived
     * as a successful empty answer and TUN-1 voided saying the host named no site — a true verdict with a
     * false reason, against a host serving three hostnames correctly.
     *
     * Asserting only VOID would pass on the defect, because the defect voided too. The assertion is that
     * nginx's own complaint reaches the operator.
     */
    tunnelLogFixture($this->dir, 'hostnames-fail', '');

    $run = tunnelLogRun($this->dir, $this->family);

    expect(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('could not be read')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('still could not bind')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->not->toContain('no site hostname')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS');
});

it('reads every name a directive carries, strips the semicolon, and collapses the ports', function (): void {
    /*
     * Two names on one directive, repeated across the :80 and :443 blocks exactly as a real host
     * repeats them. The assertion is the REQUEST LOG, not the verdict text: the curl stub records
     * every hostname it was asked for, so this fails if a name is dropped (parsing), if `;` survives
     * (a request to `alias.kitsune.test;`), if `_` is taken for a site, or if the two port blocks
     * produce the same name twice. A verdict-substring assertion could not tell those apart.
     */
    tunnelLogFixture($this->dir, 'hostnames', tunnelLogDump('stage.kitsune.test alias.kitsune.test'));

    tunnelLogRun($this->dir, $this->family);

    $asked = array_values(array_filter(explode("\n", trim((string) @file_get_contents($this->dir.'/requested')))));
    sort($asked);

    expect($asked)->toBe(['alias.kitsune.test', 'stage.kitsune.test']);
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
