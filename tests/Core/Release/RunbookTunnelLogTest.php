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
 *
 * ⚠️ THE ROOTS ARE PART OF THE FIXTURE NOW, BECAUSE THEY ARE WHAT MAKES A HOSTNAME A SITE. A block that
 * declares no server-level root ending in `/public` serves no application — a `www`→apex redirect vhost is
 * exactly that shape — and the family requests only the hostnames that do. A fixture whose site blocks
 * declared none described a host serving nothing, which no real one this family runs against does: the
 * `:80` block redirects and the `:443` block carries the root, and a `location` carries one of its own
 * that is not the site's.
 */
function tunnelLogDump(string $siteNames = 'stage.kitsune.test', string $extra = ''): string
{
    $site = $siteNames === '' ? '' : <<<CONF
        # configuration file /etc/nginx/sites-enabled/stage.kitsune.test:
        server {
            listen 80;
            server_name {$siteNames};
            return 301 https://\$host\$request_uri;
        }
        server {
            listen 443 ssl;
            server_name {$siteNames};
            root /home/kitsune/site/current/public;
            location /assets {
                root /var/www/shared-assets;
            }
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
            root /var/www/html;
        }

        {$site}{$extra}
        CONF;
}

/** A vhost that redirects and declares no application, as Forge writes one from its own UI. */
function tunnelLogRedirectVhost(string $hostname = 'www.stage.kitsune.test'): string
{
    return <<<CONF
        # configuration file /etc/nginx/sites-enabled/{$hostname}:
        server {
            listen 80;
            listen 443 ssl;
            server_name {$hostname};
            return 301 https://stage.kitsune.test\$request_uri;
        }

        CONF;
}

/** Every hostname the curl stub was asked for, sorted. */
function tunnelLogRequested(string $dir): array
{
    $asked = array_values(array_filter(explode("\n", trim((string) @file_get_contents($dir.'/requested')))));
    sort($asked);

    return $asked;
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
            # The marker is the probe on the server: `start` writes the snippet and reloads nginx before its
            # drain wait, so a start that fails after that point leaves one for `stop` to find.
            touch "$d/installed"
            if [[ -s "$d/start-installed-then-failed" ]]; then
              cat "$d/start-installed-then-failed" >&2
              exit "$(cat "$d/start-status")"
            fi
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
            echo "$args" >> "$d/stopped"
            [[ -e "$d/stop-fail" ]] && { echo "Refusing to check: the configuration does not hash back" >&2; exit 1; }
            if [[ -e "$d/installed" ]]; then
              rm -f "$d/installed"
              echo "STATE stop removed, dead-man cancelled, configuration hashes back to its baseline"
            else
              echo "STATE stop absent no snippet and no dead-man timer, so nothing of this probe was installed and nginx was not reloaded"
            fi
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

/**
 * Run the family the way an operator does: through the real run.sh, against a fixture runbook whose
 * outside/tunnel-log.php is the given source and whose manifest promises what the committed one does.
 * TMPDIR is the fixture's own, so a kept streams directory is found, and removed, with the test.
 */
function tunnelLogGate(string $dir, string $source): Process
{
    File::copy(dirname(__DIR__, 3).'/deploy/runbook/run.sh', $dir.'/runbook/run.sh');
    File::put($dir.'/runbook/manifest.txt', "tunnel tunnel-log TUN-1\ntunnel tunnel-log TUN-2\n");
    File::ensureDirectoryExists($dir.'/runbook/outside');
    File::put($dir.'/runbook/outside/tunnel-log.php', $source);
    File::ensureDirectoryExists($dir.'/tmp');

    $process = new Process(
        ['bash', $dir.'/runbook/run.sh', '--host', 'forge@fixture', '--expect', 'tunnel'],
        $dir,
        ['HOME' => (string) getenv('HOME'), 'TMPDIR' => $dir.'/tmp', 'PATH' => $dir.'/bin:'.getenv('PATH')],
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

it('voids both checks when the probe log could not be installed, having asked what is on the server', function (): void {
    /*
     * ⚠️ "NOTHING WAS INSTALLED" IS A MEASUREMENT NOW, NOT AN ASSUMPTION. This start refuses before it installs
     * anything, and stop is what says so: it finds no snippet and no dead-man timer for the nonce.
     */
    touch($this->dir.'/start-fail');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('did not start')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('nothing of the probe was installed, so nothing had to be removed: STATE stop absent')
        ->and(File::get($this->dir.'/stopped'))->toContain('stop');
});

it('removes a probe the failed start had installed, rather than reporting that nothing was', function (string $status, string $said, string $named): void {
    /*
     * ⚠️ THE PROBE STAYED ON THE SERVER, AND TUN-2 SAID NOTHING WAS INSTALLED. `start` writes the snippet, arms the
     * dead-man timer and reloads nginx before it waits for the old workers to drain, so it can fail with the probe
     * live: the drain wait expiring, or ssh exiting 255 once the remote start had finished. Any non-zero start was
     * read as "nothing was installed" and stop never ran, so the probe served until the dead-man timer fired
     * fifteen minutes later — and a rerun inside that window refused, because a probe was already installed.
     */
    File::put($this->dir.'/start-installed-then-failed', $said);
    File::put($this->dir.'/start-status', $status);

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain($named)
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS STATE stop removed')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->not->toContain('nothing')
        ->and(is_file($this->dir.'/installed'))->toBeFalse()
        ->and($run->getOutput())->toContain('SENTINEL tunnel-log 2 TUN-1 TUN-2');
})->with([
    'the drain wait expired after the reload' => [
        '1',
        "Refusing to check: after 90s of the runbook's own patience the pre-reload workers 3120 were still serving, so a probe line could not be attributed to this configuration.",
        "the runbook's own patience",
    ],
    'ssh exited 255 once the remote start had finished' => [
        '255',
        'Connection to stage.example closed by remote host.',
        'closed by remote host',
    ],
]);

it('fails TUN-2 when the probe a failed start installed could not be removed', function (): void {
    File::put($this->dir.'/start-installed-then-failed', "Refusing to check: after 90s of the runbook's own patience the pre-reload workers 3120 were still serving");
    File::put($this->dir.'/start-status', '1');
    touch($this->dir.'/stop-fail');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('FAIL')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('THE PROBE LOG WAS NOT REMOVED')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('does not hash back');
});

it('says nothing was installed, and runs no stop, when the instrument never reached the host', function (): void {
    /*
     * ⚠️ THE ONE CASE STOP CANNOT MEASURE. With ssh unreachable, start sent nothing, and a stop that cannot start
     * either could only report a failure of its own — TUN-2 FAIL "THE PROBE LOG WAS NOT REMOVED" against a server
     * that never had one. `run()` reports a command that never started as a status no process can exit with.
     */
    File::makeDirectory($this->dir.'/nossh');
    symlink(PHP_BINARY, $this->dir.'/nossh/php');

    $run = tunnelLogRun($this->dir, $this->family, 'tunnel', ['PATH' => $this->dir.'/nossh']);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('could not start ssh')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('VOID nothing was installed: nothing was sent to the host')
        ->and(is_file($this->dir.'/stopped'))->toBeFalse();
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
    // Both spellings nginx writes when the request never reached an upstream: no variable at all, and
    // the dash its escape=json log format writes for an unset one.
    'nginx answered, not PHP' => [['upstream_addr' => ''], 'PHP did not answer'],
    'nginx answered, and the field is a dash' => [['upstream_addr' => '-'], 'reached no upstream at all'],
    'the last entry is not what the edge saw' => [['xff' => '192.0.2.77, 198.51.100.9'], 'the edge saw'],
]);

it('passes a host whose PHP answers over TCP rather than a unix socket', function (string $upstream): void {
    /*
     * ⚠️ THAT AN UPSTREAM ANSWERED IT, NOT WHICH SOCKET FAMILY REACHED ONE. The rule asked for
     * `unix:/<path>.sock`, so a host whose nginx has `fastcgi_pass 127.0.0.1:9000` — what the official
     * php-fpm container listens on — FAILed with "PHP did not answer" about a request PHP demonstrably
     * answered: the very line being judged carries upstream_status 404, which is Laravel's own fallback for
     * an unrouted path and nothing an nginx short-circuit produces.
     *
     * The socket family is not this family's condition to assert either. ADR-034 does not mention fastcgi,
     * FPM or a socket, and where PHP is, the families that read the configuration already judge — nginx's
     * NGX-3 and relays' RLY-2 and RLY-3. This is the same change the throttle family's finding 10 made to
     * the same assertion.
     */
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine(['upstream_addr' => $upstream])) ?: '{}');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('PASS')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS')
        // The evidence still names what answered, so an operator reading the report can see it was TCP.
        ->and($run->getOutput())->toContain('upstream '.$upstream);
})->with([
    'FPM over TCP on loopback' => ['127.0.0.1:9000'],
    'FPM over TCP on IPv6 loopback' => ['[::1]:9000'],
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
     * ⚠️ THIS IS THE ONE THAT SHIPPED. The family read the dump with `sudo -n bash -c 'nginx -T 2>/dev/null
     * | awk …'`, and wrapped in a shell the dump never arrives: nginx writes nothing to stdout and says it
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

    expect(tunnelLogRequested($this->dir))->toBe(['alias.kitsune.test', 'stage.kitsune.test']);
});

it('requests the hostnames an application answers at, and records the ones that answer for none', function (): void {
    /*
     * ⚠️ A REDIRECT VHOST VOIDED A HOST THAT WAS ANSWERING CORRECTLY. `server_name www.<domain>; return 301
     * …` declares no application: it answers 301 with no upstream and no 404, so the two rules that say the
     * request reached PHP could not hold, and TUN-1 reported the whole host unmeasurable — after installing
     * the probe log and reloading nginx twice — over a hostname that was behaving exactly as intended. Forge
     * writes that block from its own UI, so the alpha host will have one, and the same is true of any
     * co-resident vhost that is not this application: an old-domain redirect, a static docs site, an
     * ACME-only block.
     *
     * The throttle family met the same vhost and got it wrong differently, which is why the split now lives
     * in outside/lib.php and both families read it: a disagreement between two families about which
     * hostnames a run is even about reaches the operator as a property of the host.
     *
     * ⚠️ AND THE ASSERTION IS THE REQUEST LOG. A family that still requested the redirect vhost and merely
     * left it out of the sentence would pass a verdict-substring assertion, and would still be driving a
     * request and a probe-log collect at a hostname that serves nothing.
     */
    tunnelLogFixture($this->dir, 'hostnames', tunnelLogDump('stage.kitsune.test', tunnelLogRedirectVhost()));

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('PASS')
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS')
        ->and(tunnelLogRequested($this->dir))->toBe(['stage.kitsune.test'])
        ->and($run->getOutput())->toContain('RECORD TUN-1 the configuration also serves hostnames that name no application: '
            .'[www.stage.kitsune.test] has no server-level root ending in /public in the running configuration '
            .'(it declares none), so nothing was requested there')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->not->toContain('www.stage.kitsune.test');
});

it('voids a host where every hostname it serves declares no application, and says which', function (): void {
    /*
     * The other end of the split: leaving a redirect vhost out is not the same as having nothing to measure,
     * so a configuration where NO hostname declares an application is still VOID — and names them, rather
     * than reporting that a host serving two vhosts names no site at all.
     */
    tunnelLogFixture($this->dir, 'hostnames', tunnelLogDump('', tunnelLogRedirectVhost().tunnelLogRedirectVhost('old.kitsune.test')));

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('no hostname this server serves declares an application to request')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('[old.kitsune.test] has no server-level root ending in /public')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('[www.stage.kitsune.test] has no server-level root ending in /public')
        ->and(tunnelLogRequested($this->dir))->toBe([])
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS');
});

it('still voids a hostname that declares an application and did not answer 404', function (string $status, string $upstream): void {
    /*
     * ⚠️ THE TRUE POSITIVE THE SPLIT MUST NOT SWALLOW. Leaving out a hostname that declares no application is
     * not the same as excusing a redirect from one that does: the path requested is unrouted, so Laravel's
     * own fallback is what produces the 404, and anything else — a canonical-host redirect in front of the
     * app, a cached or static answer, an error page — means the request did not reach the point this judges.
     * Asserting only that the healthy world passes would be satisfied by a family that stopped judging the
     * status at all.
     */
    tunnelLogFixture($this->dir, 'probe', json_encode(tunnelLogLine(['status' => $status, 'upstream_status' => $upstream])) ?: '{}');

    $run = tunnelLogRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('VOID')
        ->and(tunnelLogVerdict($run, 'TUN-1'))->toContain('rather than 404');
})->with([
    'the application redirected it' => ['301', '301'],
    'nginx answered from its own cache' => ['200', '200'],
]);

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
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('nothing was installed: nothing was sent to the host')
        ->and($run->getOutput())->not->toContain('PROBE ');
});

it('refuses a verdict for a check it does not declare, and still removes the probe log', function (): void {
    /*
     * ⚠️ WHY verdict() THROWS RATHER THAN EXITS. This refusal fires inside the measuring path, after the
     * probe log is installed; an exit would skip the `finally` that removes it and leave the server changed
     * until the dead-man timer fired. The mutant reports its TUN-1 result under an id it never declared: the
     * refusal reaches stderr and the stream, quoting the verdict it refused, TUN-2 still reports the probe removed,
     * the undeclared id is never printed as a verdict, and the stream is not closed.
     */
    $source = File::get($this->family);
    $mutation = "verdict('TUN-1', 'PASS', 'every one of '";

    // The mutation must land exactly once, or this says nothing about the guard.
    expect(substr_count($source, $mutation))->toBe(1);

    $mutant = $this->dir.'/tunnel-log-undeclared.php';
    File::put($mutant, str_replace($mutation, "verdict('TUN-9', 'PASS', 'every one of '", $source));

    $run = tunnelLogRun($this->dir, $mutant);
    $refused = 'verdict TUN-9 PASS [every one of stage.kitsune.test arrived from 127.0.0.1 with TLS terminated here, answered by PHP, and the last forwarded entry as the edge saw it]: this family did not declare that id';

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain($refused)
        ->and($run->getOutput())->toContain("REFUSED tunnel-log {$refused}\n")
        ->and(tunnelLogVerdict($run, 'TUN-2'))->toContain('PASS STATE stop removed')
        ->and($run->getOutput())->not->toContain('VERDICT TUN-9')
        ->and($run->getOutput())->not->toContain('SENTINEL');
});

it('refuses a second verdict for one check', function (): void {
    // A check reported twice leaves the gate unable to tell which verdict stands. Mutated on the dns-only
    // path, which voids TUN-1 and then TUN-2: the second becomes TUN-1 again.
    $source = File::get($this->family);
    $mutation = "verdict('TUN-2', 'VOID', 'no probe was installed on a host this family does not check'";

    expect(substr_count($source, $mutation))->toBe(1);

    $mutant = $this->dir.'/tunnel-log-twice.php';
    File::put($mutant, str_replace($mutation, "verdict('TUN-1', 'VOID', 'no probe was installed on a host this family does not check'", $source));

    $run = tunnelLogRun($this->dir, $mutant, 'dns-only');

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('verdict TUN-1 VOID [no probe was installed on a host this family does not check]: emitted twice')
        ->and($run->getOutput())->toContain("REFUSED tunnel-log verdict TUN-1 VOID [no probe was installed on a host this family does not check]: emitted twice\n")
        ->and(substr_count($run->getOutput(), 'VERDICT TUN-1 '))->toBe(1)
        ->and($run->getOutput())->not->toContain('SENTINEL');
});

it('voids the whole family through run.sh when it refuses a verdict after every promised one, and still removes the probe', function (string $refused, string $reason): void {
    /*
     * ⚠️ THE REFUSAL USED TO VANISH, AND THE RUN PASSED. verdict() throws inside `try`, and `finally` printed
     * TUN-2 and a sentinel built from the verdicts it had accepted — so the stream added up without the refused
     * one. run.sh printed "Every promised check passed.", exited 0 and deleted the streams, with the refusal
     * only on stderr. Both mutants add their verdict at the end of `try`, after TUN-1's: a second hostname's
     * FAIL reported as TUN-1 again, and a new check written without declaring it.
     *
     * ⚠️ AND THE REFUSED FAIL IS QUOTED, in the report and in the kept stream. The refusal named only the check, so
     * the FAIL appeared nowhere, and the report's one measurement of TUN-1 was the PASS before it.
     */
    $source = File::get($this->family);
    $mutation = "the last forwarded entry as the edge saw it', \$verdicts);\n    }\n";

    expect(substr_count($source, $mutation))->toBe(1);

    $run = tunnelLogGate($this->dir, str_replace($mutation, "{$mutation}\n    {$refused}\n", $source));
    $kept = glob($this->dir.'/tmp/kitsune-runbook.*') ?: [];

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getOutput())->toContain("VOID  TUN-1 (tunnel-log) — the family refused to check ({$reason})")
        ->and($run->getOutput())->toContain("VOID  TUN-2 (tunnel-log) — the family refused to check ({$reason})")
        // The probe was still removed: TUN-2's own verdict, reported from `finally`, is shown with the VOID.
        ->and($run->getOutput())->toContain('for this check it reported PASS: STATE stop removed')
        ->and($run->getOutput())->not->toContain('after other output on its line')
        ->and($run->getOutput())->not->toContain('Every promised check passed.')
        ->and($kept)->toHaveCount(1)
        ->and($run->getErrorOutput())->toContain("The families' own output is kept in {$kept[0]}")
        ->and(File::get($kept[0].'/tunnel-log.out'))->toContain("REFUSED tunnel-log {$reason}\n")
        ->and(File::get($kept[0].'/tunnel-log.out'))->not->toContain('SENTINEL');
})->with([
    'a second verdict for TUN-1' => [
        "verdict('TUN-1', 'FAIL', 'other.kitsune.test: it arrived for host [stage.kitsune.test]', \$verdicts);",
        'verdict TUN-1 FAIL [other.kitsune.test: it arrived for host [stage.kitsune.test]]: emitted twice',
    ],
    'a check it never declared' => [
        "verdict('TUN-3', 'FAIL', 'a new check that found a problem', \$verdicts);",
        'verdict TUN-3 FAIL [a new check that found a problem]: this family did not declare that id',
    ],
]);
