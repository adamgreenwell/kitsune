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
 * The runbook's probe log — deploy/runbook/host/probe-log.sh — run for real against a temporary
 * nginx tree with nginx, systemd and the process tools stubbed (issue #111).
 *
 * ⚠️ THIS IS THE ONLY PART OF THE RUNBOOK THAT CHANGES A LIVE SERVER: it installs one conf.d snippet,
 * reloads nginx, and must undo both. So most of the cases below are refusals, because everything that
 * can go wrong here goes wrong on somebody's production web server — a probe left installed, a
 * dead-man timer left armed, a configuration that does not come back, or a line attributed to a
 * worker that never read the probe's configuration.
 *
 * ⚠️ AND IT EMITS NO VERDICTS. It is an instrument, not a family: run.sh dispatches one script per
 * family from the manifest, while this is driven three times around a single request. It refuses
 * loudly and prints STATE, PROBE and PROBE-OWNER lines; the tunnel-log family turns a refusal into
 * VOID. A check id here would make the completeness gate demand a verdict from a script run.sh never
 * dispatches.
 *
 * The stubs read marker files rather than environment variables, so a case can change nginx's
 * behaviour *between* start and stop — which is the only way to test that the restoration proof
 * notices a configuration that did not come back.
 *
 * Needs only bash and PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-probe-'.bin2hex(random_bytes(6));
    $this->instrument = $repo.'/deploy/runbook/host/probe-log.sh';
    $this->common = $repo.'/deploy/runbook/host/common.sh';
    $this->nonce = str_repeat('ab12', 8);

    File::makeDirectory($this->dir.'/bin', 0755, true);
    File::makeDirectory($this->dir.'/confd', 0755, true);
    File::makeDirectory($this->dir.'/run', 0755, true);

    File::put($this->dir.'/run/nginx.pid', "1\n");
    File::put($this->dir.'/workers', "4242\n");

    foreach (probeStubs() as $name => $body) {
        File::put($this->dir.'/bin/'.$name, $body);
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    // macOS has no sha256sum; the host does (from coreutils-from-uutils, measured on stage).
    if (shell_exec('command -v sha256sum') === null) {
        File::put($this->dir.'/bin/sha256sum', "#!/usr/bin/env bash\nexec shasum -a 256 \"\$@\"\n");
        chmod($this->dir.'/bin/sha256sum', 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * The stubs. Each reads marker files from the fixture directory, so a case can flip a behaviour
 * between two invocations of the instrument.
 *
 * @return array<string, string>
 */
function probeStubs(): array
{
    return [
        'nginx' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        case "$*" in
          *-T*)
            echo "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok" >&2
            echo "# configuration file /etc/nginx/nginx.conf:"
            echo "user www-data;"
            [[ -e "$d/drift" ]] && echo "client_max_body_size 64m;"
            cat "$d"/confd/*.conf 2>/dev/null || true
            ;;
          *-V*)
            echo "nginx version: nginx/1.28.3 (Ubuntu)" >&2
            if [[ -e "$d/no-realip" ]]; then
              echo "configure arguments: --with-http_ssl_module" >&2
            else
              echo "configure arguments: --with-http_realip_module --with-http_ssl_module" >&2
            fi
            ;;
          *-t*)      [[ -e "$d/t-fail" ]] && { echo "nginx: [emerg] bad snippet" >&2; exit 1; }; exit 0 ;;
          *reload*)  [[ -e "$d/reload-fail" ]] && { echo "nginx: [error] cannot reload" >&2; exit 1; }; exit 0 ;;
        esac
        BASH,
        'systemd-run' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        [[ -e "$d/timer-refuses" ]] && exit 1
        exit 0
        BASH,
        'systemctl' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        if [[ "${1:-}" == list-units && -e "$d/timer-lives" ]]; then
          echo "kitsune-probe-ab12ab12ab12ab12ab12ab12ab12ab12.timer loaded active waiting"
        fi
        exit 0
        BASH,
        'pgrep' => <<<'BASH'
        #!/usr/bin/env bash
        cat "$(dirname "$(dirname "$0")")/workers"
        BASH,
        // ⚠️ BOTH ENDS, AND THE FILTER IS OBEYED. A loopback connection appears twice in `ss`, once from
        // each end, and both rows carry both ports — so an instrument that does not say which end it
        // wants gets whichever sorts first. Until this stub existed `ss` was not stubbed at all: the real
        // binary ran, found nothing on a developer's machine, and `PROBE-OWNER <id> none` satisfied the
        // only assertion there was. The shape is the live one (stage, 2026-09-16): ten fields, no state
        // column under `-H`, the queues first.
        'ss' => <<<'BASH'
        #!/usr/bin/env bash
        d=$(dirname "$(dirname "$0")")
        args="$*"

        client_owner='users:(("cloudflared",pid=1470,fd=10))'
        [[ -e "$d/owner-not-connector" ]] && client_owner='users:(("sshd",pid=99,fd=11))'

        client="0      0      127.0.0.1:35572 127.0.0.1:443 ${client_owner} timer:(keepalive,29sec,0) ino:2605943 sk:5017 cgroup:/system.slice/cloudflared.service <->"
        server='0      0      127.0.0.1:443 127.0.0.1:35572 users:(("nginx",pid=4242,fd=11)) uid:33 ino:2611423 sk:e cgroup:/system.slice/nginx.service <->'

        [[ -e "$d/no-socket" ]] && exit 0

        case "$args" in
          *"dport = :443"*)
            # A second connection from the same source port to another loopback address: legal on Linux
            # when the destination differs, and listed FIRST, which is the order a port-only match takes.
            [[ -e "$d/same-sport-decoy" ]] \
              && echo '0      0      127.0.0.1:35572 127.0.0.2:443 users:(("stranger",pid=777,fd=3)) ino:1 sk:1 <->'
            echo "$client"
            ;;
          *"sport = :443"*) echo "$server" ;;
          *)                echo "$server"; echo "$client" ;;
        esac
        BASH,
        'id' => <<<'BASH'
        #!/usr/bin/env bash
        if [[ "${1:-}" == -u ]]; then echo "${STUB_UID:-0}"; else exec /usr/bin/id "$@"; fi
        BASH,
        // ⚠️ macOS HAS NO `timeout`, AND ITS ABSENCE IS SILENT. Every measurement in the host scripts is
        // wrapped in one — `timeout 5 ss`, `timeout 8 curl` — so on a developer's machine each call died
        // with "command not found", the `|| true` guard swallowed it, and the substitution came back
        // empty: `PROBE-OWNER <id> none`, which the only assertion there was (that the line exists) was
        // happy with. That is why `ss` went unstubbed for the life of this file and why the wrong-end
        // defect reached stage. This drops the duration and runs the command, so the real path is
        // exercised here; on Linux, where `timeout` exists, nothing about the instrument changes.
        'timeout' => <<<'BASH'
        #!/usr/bin/env bash
        shift
        exec "$@"
        BASH,
    ];
}

/**
 * Drive the instrument the way the tunnel-log check will: common.sh and the instrument as one stream.
 *
 * @param  list<string>  $args
 * @param  array<string, string|false>  $env
 */
function probeRun(string $dir, string $common, string $instrument, array $args, array $env = []): Process
{
    $command = 'cat '.escapeshellarg($common).' '.escapeshellarg($instrument).' | bash -s --';

    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }

    $process = Process::fromShellCommandline($command, $dir, array_replace([
        'HOME' => (string) getenv('HOME'),
        'TMPDIR' => sys_get_temp_dir(),
        'PATH' => $dir.'/bin:'.getenv('PATH'),
        'KITSUNE_NGINX_CONF_D' => $dir.'/confd',
        'KITSUNE_PROBE_DIR' => $dir.'/run/probe',
        'KITSUNE_NGINX_PID' => $dir.'/run/nginx.pid',
        'KITSUNE_DRAIN_PATIENCE' => '2',
    ], $env));

    $process->setTimeout(60);
    $process->run();

    return $process;
}

/** Seed a log line the way nginx would have written it for one request id. */
function probeSeedLine(string $dir, string $nonce, string $id, string $pid = '4242'): void
{
    File::ensureDirectoryExists($dir.'/run/probe');
    File::append(
        $dir.'/run/probe/'.$nonce.'.log',
        '{"pid":"'.$pid.'","remote_addr":"127.0.0.1","remote_port":"35572","probe":"'.$id.'"}'."\n",
    );
}

it('is valid bash', function (): void {
    $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/deploy/runbook/host/probe-log.sh']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getErrorOutput())->toBe('');
});

it('installs the probe, reloads, drains, and says which workers serve now', function (): void {
    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getOutput())->toContain('STATE start installed, reloaded, drained')
        ->and($run->getOutput())->toContain('workers now 4242')
        ->and(is_file($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf'))->toBeTrue();
});

it('writes a snippet that logs only its own requests', function (): void {
    /*
     * ⚠️ THE NONCE GATE IS WHAT KEEPS OTHER VISITORS OUT OF THE LOG. Without `if=`, installing a log
     * format on a live server records every request anyone makes while the check runs.
     */
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    $snippet = File::get($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf');

    expect($snippet)->toContain('log_format kitsune_probe_'.$this->nonce)
        ->and($snippet)->toContain('map $http_x_kitsune_probe $kitsune_probe_'.$this->nonce)
        ->and($snippet)->toContain('if=$kitsune_probe_'.$this->nonce.';')
        ->and($snippet)->toContain('"~^'.$this->nonce.'-"')
        ->and($snippet)->toContain('$realip_remote_addr')
        ->and($snippet)->toContain('$proxy_protocol_addr');
});

it('refuses a nonce that did not come from the runbook', function (string $nonce): void {
    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('32 hex characters')
        ->and(File::files($this->dir.'/confd'))->toBe([]);
})->with([
    'too short' => ['abc123'],
    'not hex' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
    'shell in it' => ['ab12ab12ab12ab12ab12ab12ab12ab1;'],
]);

it('refuses to install a second probe over a first', function (): void {
    /*
     * ⚠️ TWO PROBES OVERWRITE EACH OTHER'S IDEA OF "BEFORE", and then neither can prove the
     * configuration was restored.
     */
    File::put($this->dir.'/confd/kitsune-probe-'.str_repeat('cd34', 8).'.conf', "# an earlier run\n");

    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('a probe is already installed')
        ->and(is_file($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf'))->toBeFalse();
});

it('refuses an nginx that cannot tell a resolved address from the peer', function (): void {
    touch($this->dir.'/no-realip');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('no realip module')
        ->and(File::files($this->dir.'/confd'))->toBe([]);
});

it('removes its own snippet when the snippet does not parse', function (): void {
    touch($this->dir.'/t-fail');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('does not parse')
        ->and(is_file($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf'))->toBeFalse();
});

it('removes its own snippet when nginx will not reload', function (): void {
    touch($this->dir.'/reload-fail');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('would not reload')
        ->and(is_file($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf'))->toBeFalse();
});

it('will not install a probe it cannot guarantee to remove', function (): void {
    touch($this->dir.'/timer-refuses');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('dead-man timer')
        ->and(is_file($this->dir.'/confd/kitsune-probe-'.$this->nonce.'.conf'))->toBeFalse();
});

it('prints the line for one request, and who owned its socket', function (): void {
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getOutput())->toContain('PROBE '.$this->nonce.'-1 {"pid":"4242"')
        ->and($run->getOutput())->toContain('PROBE-OWNER '.$this->nonce.'-1')
        ->and($run->getOutput())->toContain('written by worker 4242');
});

it('reports the dialer that owned the socket, not the web server that accepted it', function (): void {
    /*
     * ⚠️ THE ROW IT PICKED WAS THE WRONG END. A loopback connection appears twice in `ss`, once from
     * each end, and both rows carry both ports; unfiltered, the instrument matched `:$port ` and took
     * whichever came first — the `127.0.0.1:443 127.0.0.1:<port>` row, owned by nginx, which can never
     * be a connector. On stage (2026-09-16) TUN-1 therefore FAILED naming nginx as the owner, while
     * RLY-1 passed on the same host in the same run, because relays.sh asks for `dport = :443`.
     *
     * Asserting only that a PROBE-OWNER line exists cannot see this — and could not see an absent
     * measurement either, since `none` contains the prefix too. Until this test the `ss` the instrument
     * ran was the real one, which finds nothing on a developer's machine.
     */
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    $owner = '';

    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'PROBE-OWNER '.$this->nonce.'-1 ')) {
            $owner = $line;
        }
    }

    expect($owner)->toContain('cloudflared')
        ->and($owner)->not->toContain('nginx')
        ->and($owner)->not->toContain('none');
});

it('ties the owner to the whole connection, not to a port another socket can share', function (): void {
    /*
     * ⚠️ A SOURCE PORT IS NOT A CONNECTION. Linux lets one local address and port hold a second
     * established connection when the destination differs, so a loopback socket to 127.0.0.2:443 can
     * share the port nginx recorded for this request. Matched on that port alone, `head -1` took the
     * first row and named its owner (review on #118). The stub lists the stranger's row first — the
     * order that would have fooled it — so only a match on the whole tuple can pass.
     */
    touch($this->dir.'/same-sport-decoy');

    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    $owner = '';

    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'PROBE-OWNER '.$this->nonce.'-1 ')) {
            $owner = $line;
        }
    }

    expect($owner)->toContain('127.0.0.1:35572 127.0.0.1:443')
        ->and($owner)->toContain('cloudflared')
        ->and($owner)->not->toContain('stranger');
});

it('refuses a line written by a worker the reload did not create', function (): void {
    /*
     * ⚠️ A PRE-RELOAD WORKER DESCRIBES A CONFIGURATION THIS RUN DID NOT INSTALL. The connector holds
     * keepalive connections, so a request can be answered by a worker that never read the probe.
     */
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1', '9999');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('not one of the workers the reload created');
});

it('refuses when two lines carry one request id', function (): void {
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('2 lines carry the id');
});

it('refuses when no line carries the request id', function (): void {
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-9');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['collect', $this->nonce, $this->nonce.'-1']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('no line carries the id');
});

it('removes the probe and proves the configuration is back as it was', function (): void {
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    probeSeedLine($this->dir, $this->nonce, $this->nonce.'-1');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['stop', $this->nonce]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and($run->getOutput())->toContain('hashes back to its baseline')
        ->and(File::files($this->dir.'/confd'))->toBe([])
        ->and(is_file($this->dir.'/run/probe/'.$this->nonce.'.log'))->toBeFalse();
});

it('refuses to call the server restored when the configuration changed underneath it', function (): void {
    /*
     * ⚠️ RESTORATION IS PROVEN, NOT ANNOUNCED. The hash is of what nginx itself dumps, so an operator's
     * own edit made while the check ran, or a snippet only half removed, shows up here.
     */
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    touch($this->dir.'/drift');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['stop', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('does not hash back to its baseline');
});

it('refuses to finish while its dead-man timer could still reload nginx', function (): void {
    probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce]);
    touch($this->dir.'/timer-lives');

    $run = probeRun($this->dir, $this->common, $this->instrument, ['stop', $this->nonce]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('dead-man timer')
        ->and($run->getErrorOutput())->toContain('would reload nginx later');
});

it('refuses an action it does not have', function (): void {
    $run = probeRun($this->dir, $this->common, $this->instrument, ['restart', $this->nonce]);

    // An instrument opens no family, so it has no verdict stream to write its refusal into: stderr alone.
    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('it must be start, collect or stop')
        ->and($run->getOutput())->not->toContain('REFUSED');
});

it('refuses to run unprivileged, because it would change a server it cannot read', function (): void {
    $run = probeRun($this->dir, $this->common, $this->instrument, ['start', $this->nonce], ['STUB_UID' => '1000']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('run as root')
        ->and(File::files($this->dir.'/confd'))->toBe([]);
});
