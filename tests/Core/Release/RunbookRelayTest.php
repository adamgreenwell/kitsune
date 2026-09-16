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
 * The relays family of the ADR-034 runbook — deploy/runbook/host/relays.sh — run for real against a
 * fixture process table and stubbed socket tools (issue #111).
 *
 * ⚠️ THIS IS THE FAMILY ADR-034 RESTS ON. The skeleton trusts whatever `127.0.0.1` puts in
 * X-Forwarded-For, which is sound only while the sole process dialling nginx over loopback is the
 * tunnel's own connector. A second relay — an SSH forward, a proxy, a container's port mapping —
 * would let its client choose the address Laravel believes.
 *
 * ⚠️ AND ITS FAILURE MODE IS SILENCE, WHICH IS WHY THE CASES BELOW ARE MOSTLY ABOUT DYING QUIETLY.
 * Three times on the stage server this family ended early under `set -e` with no verdict and no
 * reason: a grep that matched nothing, a process that exited between listing the table and reading
 * its cgroup, and a driver aimed at a hostname that could not resolve. Each time the sentinel
 * reported fewer ids than the manifest promised, which is how the run stayed honest — and each is
 * pinned here.
 *
 * Needs only bash and PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-relays-'.bin2hex(random_bytes(6));
    $this->family = $repo.'/deploy/runbook/host/relays.sh';
    $this->common = $repo.'/deploy/runbook/host/common.sh';

    File::makeDirectory($this->dir.'/bin', 0755, true);
    File::makeDirectory($this->dir.'/proc', 0755, true);

    relayProcess($this->dir, 4242, '/usr/bin/cloudflared', 'cloudflared', '0::/system.slice/cloudflared.service');
    relayProcess($this->dir, 99, '/usr/sbin/nginx', 'nginx', '0::/system.slice/nginx.service');
    // The pool process that holds PHP's socket in the `unix` fixture below. Without it the family
    // reads the holder as a pid that no longer exists — which is a real condition, covered on purpose
    // further down rather than arrived at by a fixture that forgot to describe its own host.
    relayProcess($this->dir, 23326, '/usr/sbin/php-fpm8.5', 'php-fpm8.5', '0::/system.slice/php8.5-fpm.service');

    /*
     * ⚠️ THE SOCKET PATH IS THE FIXTURE'S OWN, AND IT REALLY EXISTS. RLY-3 asks the filesystem whether
     * the path the configuration names is a socket, which no stub on PATH can answer for `/run/php/…`:
     * the first version of this fixture named the real path, and the family correctly reported that
     * there was no socket to describe. So the config names a socket inside the temporary directory and
     * the test creates one there. It is assigned here, above every fixture that interpolates it.
     */
    $this->socket = $this->dir.'/php-fpm.sock';
    relaySocket($this->socket);

    relayFixture($this->dir, 'listeners', "LISTEN 0 4096 127.0.0.1:20241 0.0.0.0:* users:((\"cloudflared\",pid=4242,fd=9))\n");
    relayFixture($this->dir, 'established', "ESTAB 0 0 127.0.0.1:35572 127.0.0.1:443 users:((\"cloudflared\",pid=4242,fd=10)) ino:1582059 cgroup:/system.slice/cloudflared.service\n");
    relayFixture($this->dir, 'unix', "u_str LISTEN 0 4096 {$this->socket} 56956 * 0 users:((\"php-fpm8.5\",pid=23326,fd=10))\n");
    relayFixture($this->dir, 'listening', "LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:((\"nginx\",pid=99,fd=7))\nLISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=7,fd=3))\n");
    relayFixture($this->dir, 'tunnel', '{"tunnelID":"f9bdc253-a26e-4232-a7ca-139727970799","connectorID":"c0ffee"}');
    relayFixture($this->dir, 'nginxconf', "server {\n    server_name _;\n}\nserver {\n    server_name stage.kitsune.test;\n    location ~ \\.php$ {\n        fastcgi_pass unix:{$this->socket};\n    }\n}\n");
    relayFixture($this->dir, 'ownership', "www-data www-data 660\n");
    relayFixture($this->dir, 'workers', "99\n");
    relayFixture($this->dir, 'workeruser', "www-data\n");

    foreach (['ss', 'nginx', 'curl', 'id', 'ps', 'stat', 'timeout'] as $name) {
        File::put($this->dir.'/bin/'.$name, relayStub($name));
        chmod($this->dir.'/bin/'.$name, 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** Add one process to the fixture table. A null cgroup leaves the file out, as an exited process does. */
function relayProcess(string $dir, int $pid, string $exe, string $comm, ?string $cgroup): void
{
    $path = $dir.'/proc/'.$pid;

    File::makeDirectory($path, 0755, true);
    File::put($path.'/comm', $comm."\n");
    symlink($exe, $path.'/exe');

    if ($cgroup !== null) {
        File::put($path.'/cgroup', $cgroup."\n");
    }
}

/** Write one fixture file the stubs read. */
function relayFixture(string $dir, string $name, string $contents): void
{
    File::put($dir.'/'.$name, $contents);
}

/**
 * Create a real unix socket at a path, because RLY-3 asks the filesystem whether the path the
 * configuration names is a socket — a question no stub on PATH can answer.
 *
 * The listening resource is closed immediately and the socket file stays on disk, which is all the
 * check looks at; afterEach removes the directory that holds it.
 */
function relaySocket(string $path): void
{
    $server = stream_socket_server('unix://'.$path, $code, $message);

    if ($server === false) {
        throw new RuntimeException("could not create a fixture socket at {$path}: {$message} ({$code})");
    }

    fclose($server);
}

/** The stubs: each answers from a fixture file, so a case rewrites the file rather than the stub. */
function relayStub(string $name): string
{
    $stubs = [
        'ss' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        case "$*" in
          *-x*)        cat "$d/unix" ;;
          *Htulnpe*)   cat "$d/listening" ;;
          *dport*)     cat "$d/established" ;;
          *)           cat "$d/listeners" ;;
        esac
        BASH,
        'nginx' => <<<'BASH'
        #!/usr/bin/env bash
        cat "$(dirname "$(dirname "$0")")/nginxconf"
        BASH,
        'curl' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        for arg in "$@"; do
          case "$arg" in *diag/tunnel*) cat "$d/tunnel"; exit 0 ;; esac
        done
        exit 0
        BASH,
        'id' => <<<'BASH'
        #!/usr/bin/env bash
        case "${1:-}" in
          -u)  echo "${STUB_UID:-0}" ;;
          -nG) echo "${STUB_GROUPS:-www-data}" ;;
          *)   exec /usr/bin/id "$@" ;;
        esac
        BASH,
        'ps' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        case "$*" in
          *user=*) cat "$d/workeruser" ;;
          *)       cat "$d/workers" ;;
        esac
        BASH,
        'stat' => <<<'BASH'
        #!/usr/bin/env bash
        cat "$(dirname "$(dirname "$0")")/ownership"
        BASH,
        'timeout' => <<<'BASH'
        #!/usr/bin/env bash
        shift
        exec "$@"
        BASH,
    ];

    return $stubs[$name]."\n";
}

/**
 * Run the family the way run.sh sends it, against the fixture process table.
 *
 * @param  array<string, string|false>  $env
 */
function relayRun(string $dir, string $common, string $family, array $env = [], string $topology = 'tunnel'): Process
{
    $process = Process::fromShellCommandline(
        'cat '.escapeshellarg($common).' '.escapeshellarg($family).' | bash -s -- '.escapeshellarg($topology),
        $dir,
        array_replace([
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => $dir.'/bin:'.getenv('PATH'),
            'KITSUNE_PROC' => $dir.'/proc',
            'KITSUNE_WINDOW' => '1',
        ], $env),
    );

    $process->setTimeout(60);
    $process->run();

    return $process;
}

/** The verdict line for one check id. */
function relayVerdict(Process $run, string $id): string
{
    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'VERDICT '.$id.' ')) {
            return $line;
        }
    }

    return '';
}

it('is valid bash', function (): void {
    $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/deploy/runbook/host/relays.sh']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getErrorOutput())->toBe('');
});

it('passes a host where only the connector dials the web server', function (): void {
    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-1'))->toContain('PASS')
        ->and(relayVerdict($run, 'RLY-2'))->toContain('PASS')
        ->and(relayVerdict($run, 'RLY-3'))->toContain('PASS')
        ->and(relayVerdict($run, 'RLY-5'))->toContain('PASS')
        ->and($run->getOutput())->toContain('SENTINEL relays 5 RLY-1 RLY-2 RLY-3 RLY-4 RLY-5');
});

it('emits every verdict even when a process exits mid-scan', function (): void {
    /*
     * ⚠️ MEASURED ON STAGE. A process that exits between listing the table and reading its cgroup made
     * `cut` fail, and in an assignment under `set -e` the family ended with no verdict at all — the
     * sentinel reported zero where the manifest promised five. A vanished process is ordinary.
     */
    relayProcess($this->dir, 5150, '/usr/bin/cloudflared', 'cloudflared', null);

    $run = relayRun($this->dir, $this->common, $this->family);

    expect($run->getOutput())->toContain('SENTINEL relays 5 RLY-1 RLY-2 RLY-3 RLY-4 RLY-5')
        ->and(substr_count($run->getOutput(), 'VERDICT '))->toBe(5);
});

it('emits every verdict when nothing is connected at all', function (): void {
    /*
     * ⚠️ ALSO MEASURED. With no established connection, grep matched nothing, exited 1, and killed the
     * family before RLY-4 and RLY-5 ran. An empty sample is data: it makes RLY-1 unmeasurable, and
     * says so, rather than ending the run.
     */
    relayFixture($this->dir, 'established', '');

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-1'))->toContain('VOID')
        ->and(relayVerdict($run, 'RLY-1'))->toContain('never appeared')
        ->and($run->getOutput())->toContain('SENTINEL relays 5 RLY-1 RLY-2 RLY-3 RLY-4 RLY-5');
});

it('never drives its traffic at the catch-all server name', function (): void {
    /*
     * ⚠️ `server_name _;` TOKENIZES AS `_;`. A guard that compared before stripping the semicolon let
     * the catch-all through, and the driver then asked for https://_/up forty times — each waiting on
     * a lookup that cannot succeed, which turned a fifteen-second check into a five-minute one.
     */
    $run = relayRun($this->dir, $this->common, $this->family);

    expect($run->getOutput())->not->toContain('through _,')
        ->and(relayVerdict($run, 'RLY-1'))->not->toContain('https://_');
});

it('fails a loopback client that is not the connector', function (): void {
    relayFixture($this->dir, 'established',
        "ESTAB 0 0 127.0.0.1:41000 127.0.0.1:443 users:((\"sshd\",pid=99,fd=11)) ino:1 cgroup:/system.slice/ssh.service\n");

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-1'))->toContain('FAIL')
        ->and(relayVerdict($run, 'RLY-1'))->toContain('not this tunnel\'s connector');
});

it('fails a service dialling the web server from a cgroup it did not identify', function (): void {
    relayFixture($this->dir, 'established',
        "ESTAB 0 0 127.0.0.1:35572 127.0.0.1:443 users:((\"cloudflared\",pid=4242,fd=10)) ino:1 cgroup:/system.slice/cloudflared.service\n"
        ."ESTAB 0 0 127.0.0.1:41001 127.0.0.1:443 users:((\"haproxy\",pid=4242,fd=12)) ino:2 cgroup:/system.slice/haproxy.service\n");

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-4'))->toContain('FAIL')
        ->and(relayVerdict($run, 'RLY-4'))->toContain('haproxy.service');
});

it('fails a listening service nobody reviewed', function (): void {
    relayFixture($this->dir, 'listening',
        "LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:((\"nginx\",pid=99,fd=7))\n"
        ."LISTEN 0 128 0.0.0.0:8080 0.0.0.0:* users:((\"socat\",pid=1234,fd=3))\n");

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-5'))->toContain('FAIL')
        ->and(relayVerdict($run, 'RLY-5'))->toContain('socat');
});

it('fails a PHP socket held by something that is neither nginx nor PHP-FPM', function (): void {
    relayFixture($this->dir, 'unix',
        "u_str LISTEN 0 4096 {$this->socket} 56956 * 0 users:((\"php-fpm8.5\",pid=23326,fd=10))\n"
        ."u_str ESTAB 0 0 {$this->socket} 56957 * 0 users:((\"socat\",pid=4242,fd=5))\n");

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-2'))->toContain('FAIL');
});

it('fails a PHP socket whose holder no longer exists', function (): void {
    /*
     * ⚠️ A HOLDER THIS CHECK CANNOT NAME IS NOT A CLEAN ONE. The socket is the one path into the
     * application that carries no address to forge, so a peer whose process has gone — or which this
     * check simply cannot see — must not read as "nothing found here". Found by a fixture that named
     * a pid it had not described, which is the same shape as a process the scan is not privileged to
     * see.
     */
    relayFixture($this->dir, 'unix',
        "u_str LISTEN 0 4096 {$this->socket} 56956 * 0 users:((\"php-fpm8.5\",pid=31337,fd=10))\n");

    $run = relayRun($this->dir, $this->common, $this->family);

    expect(relayVerdict($run, 'RLY-2'))->toContain('FAIL')
        ->and(relayVerdict($run, 'RLY-2'))->toContain('gone');
});

it('expects no loopback client at all on a host with no tunnel', function (): void {
    relayFixture($this->dir, 'established', '');

    $run = relayRun($this->dir, $this->common, $this->family, [], 'dns-only');

    expect(relayVerdict($run, 'RLY-1'))->toContain('PASS')
        ->and(relayVerdict($run, 'RLY-1'))->toContain('no tunnel');
});

it('refuses to run unprivileged, because empty reads look clean', function (): void {
    $run = relayRun($this->dir, $this->common, $this->family, ['STUB_UID' => '1000']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('run as root');
});
