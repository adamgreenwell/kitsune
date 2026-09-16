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
 * The nginx family of the ADR-034 runbook — deploy/runbook/host/nginx.sh — run for real against a
 * configuration dump, with nginx, ss and id stubbed on PATH (issue #111).
 *
 * ⚠️ THE FIXTURE IS SHAPED LIKE THE REAL DUMP, ON PURPOSE. It carries the three things that break a
 * line-based reading of `nginx -T`, all present in stage's own output: a `types { … }` body whose
 * lines begin with words like `application` that are MIME mappings rather than directives, trailing
 * comments after a directive's `;`, and leading tabs. It also carries the `# configuration file`
 * markers, which are themselves comments — a dump filtered for comments has lost its provenance, and
 * the family must call that unmeasurable rather than read it anyway.
 *
 * ⚠️ AND EVERY FAILURE CASE IS A REAL ONE. Each injected line below is a way a host can be
 * configured that makes ADR-034's trust unsafe: a dual-stack listener that hands PHP a `::ffff:`
 * address, the two directives that let a client's `X_Forwarded_For` reach PHP as a second
 * `HTTP_X_FORWARDED_FOR`, a parameter that replaces the header nginx received, and a module whose
 * directive nobody has reviewed.
 *
 * Needs only bash, awk and PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-nginx-'.bin2hex(random_bytes(6));

    File::makeDirectory($this->dir.'/bin', 0755, true);

    $this->family = $repo.'/deploy/runbook/host/nginx.sh';
    $this->common = $repo.'/deploy/runbook/host/common.sh';

    nginxDump($this->dir, nginxCompliantDump());
    nginxSockets($this->dir, nginxCompliantSockets());

    File::put($this->dir.'/bin/nginx', nginxStub());
    File::put($this->dir.'/bin/ss', nginxSsStub());
    File::put($this->dir.'/bin/id', nginxIdStub());

    foreach (['nginx', 'ss', 'id'] as $stub) {
        chmod($this->dir.'/bin/'.$stub, 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** What `nginx -T` will print. A second file, when written, is printed on the second call. */
function nginxDump(string $dir, string $dump, ?string $second = null): void
{
    File::put($dir.'/dump1', $dump);
    File::put($dir.'/dump2', $second ?? $dump);
}

/** What `ss -Htlnpe` will print. */
function nginxSockets(string $dir, string $rows): void
{
    File::put($dir.'/sockets', $rows);
}

/**
 * A stand-in for nginx: -T prints the dump (a different one on the second call), -V the version.
 *
 * ⚠️ IT WRITES TO STDERR THE WAY THE REAL ONE DOES. `nginx -T` prints "the configuration file … syntax
 * is ok" and "… test is successful" to stderr before the dump. An earlier stub printed only the
 * configuration, so the suite was green while the live server failed: those two lines were folded
 * into the dump and tokenized as a directive called `nginx:`, which swallowed the file's first real
 * directive. A stub that is tidier than the real command is a test that cannot see the defect.
 */
function nginxStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    dir=$(dirname "$(dirname "$0")")

    case "$*" in
      *-T*)
        echo "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok" >&2
        echo "nginx: configuration file /etc/nginx/nginx.conf test is successful" >&2
        if [[ -e "$dir/called" ]]; then cat "$dir/dump2"; else : > "$dir/called"; cat "$dir/dump1"; fi
        ;;
      *-V*)
        echo "nginx version: nginx/1.28.3 (Ubuntu)"
        echo "configure arguments: --with-http_realip_module --with-http_ssl_module"
        ;;
      *) exit 0 ;;
    esac

    BASH;
}

/** A stand-in for ss that prints the fixture rows. */
function nginxSsStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    cat "$(dirname "$(dirname "$0")")/sockets"

    BASH;
}

/** A stand-in for id, so the family's root guard passes without the suite running as root. */
function nginxIdStub(): string
{
    return <<<'BASH'
    #!/usr/bin/env bash
    if [[ "${1:-}" == -u ]]; then echo "${STUB_UID:-0}"; else exec /usr/bin/id "$@"; fi

    BASH;
}

/**
 * A dump shaped like stage's: markers, a types block, trailing comments, tabs, and the FastCGI
 * parameter set that decides what PHP receives.
 */
function nginxCompliantDump(string $extraServer = '', string $extraHttp = ''): string
{
    return <<<CONF
    # configuration file /etc/nginx/nginx.conf:
    user www-data;
    worker_processes auto;
    pid /run/nginx.pid;

    events {
    	worker_connections 768;
    }

    http {
    	sendfile on;
    	server_tokens build; # Recommended practice is to turn this off
    	include /etc/nginx/mime.types;
    	default_type application/octet-stream;
    {$extraHttp}
    	include /etc/nginx/sites-enabled/*;
    }

    # configuration file /etc/nginx/mime.types:
    types {
        text/html html htm shtml;
        text/css css;
        application/javascript js;
        application/json json;
        video/mp4 mp4;
    }

    # configuration file /etc/nginx/sites-enabled/site:
    server {
        listen 443 ssl;
        listen [::]:443 ssl;
        server_name stage.kitsune.test;

        root /home/forge/site/current/skeleton/public;
        index index.php;
    {$extraServer}
        location = /favicon.ico {
            log_not_found off;
        }

        location ~ ^/index\.php(/|\$) {
            fastcgi_pass unix:/run/php/php8.5-fpm.sock;
            include fastcgi_params;
        }
    }

    # configuration file /etc/nginx/fastcgi_params:
    fastcgi_param  REMOTE_ADDR        \$remote_addr;
    fastcgi_param  SERVER_PORT        \$server_port;
    fastcgi_param  HTTPS              \$https if_not_empty;

    CONF;
}

/** Listener rows in which every IPv6 socket is v6only, as `ss -Htlnpe` prints them. */
function nginxCompliantSockets(): string
{
    return <<<'ROWS'
    LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:(("nginx",pid=29275,fd=7)) ino:86005 sk:4002 cgroup:/system.slice/nginx.service <->
    LISTEN 0 511 [::]:443 [::]:* users:(("nginx",pid=29275,fd=8)) ino:86006 sk:4008 cgroup:/system.slice/nginx.service v6only:1 <->

    ROWS;
}

/**
 * Run the family the way run.sh sends it: common.sh and the family as one stream, with the topology
 * as the only argument.
 *
 * @param  array<string, string|false>  $env
 */
function nginxRun(string $dir, string $common, string $family, array $env = []): Process
{
    $process = Process::fromShellCommandline(
        'cat '.escapeshellarg($common).' '.escapeshellarg($family).' | bash -s -- tunnel',
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
function nginxVerdict(Process $run, string $id): string
{
    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'VERDICT '.$id.' ')) {
            return $line;
        }
    }

    return '';
}

it('is valid bash', function (): void {
    $check = new Process(['bash', '-n', dirname(__DIR__, 3).'/deploy/runbook/host/nginx.sh']);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and($check->getErrorOutput())->toBe('');
});

it('passes every check on a compliant configuration, and closes with a sentinel', function (): void {
    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-1'))->toContain('PASS')
        ->and(nginxVerdict($run, 'NGX-2'))->toContain('PASS')
        ->and(nginxVerdict($run, 'NGX-3'))->toContain('PASS')
        ->and(nginxVerdict($run, 'NGX-4'))->toContain('PASS')
        ->and($run->getOutput())->toContain('SENTINEL nginx 5 NGX-1 NGX-2 NGX-3 NGX-4 NGX-5');
});

it('reads MIME mappings as values, not as directives nobody reviewed', function (): void {
    /*
     * ⚠️ 90-ODD LINES IN STAGE'S DUMP BEGIN WITH `application`, `video`, `image` OR `text`. A check
     * that read the first word of each line would report every one of them as an unreviewed
     * directive, and the family would be abandoned as unusable within a day.
     */
    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-2'))->toContain('PASS')
        ->and($run->getOutput())->not->toContain('application')
        ->and($run->getOutput())->not->toContain('video');
});

it('fails a directive it has never reviewed', function (string $line, string $where): void {
    $dump = $where === 'server'
        ? nginxCompliantDump($line)
        : nginxCompliantDump('', $line);

    nginxDump($this->dir, $dump);

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-2'))->toContain('FAIL')
        ->and(nginxVerdict($run, 'NGX-2'))->toContain('never reviewed');
})->with([
    'a proxy_pass' => ['    proxy_pass http://127.0.0.1:8080;', 'server'],
    'a realip directive' => ['    set_real_ip_from 127.0.0.1;', 'server'],
    'a loaded module' => ['	lua_shared_dict kitsune 1m;', 'http'],
]);

it('fails the values that decide what PHP receives', function (string $line, string $where, string $named): void {
    $dump = $where === 'server'
        ? nginxCompliantDump($line)
        : nginxCompliantDump('', $line);

    nginxDump($this->dir, $dump);

    $run = nginxRun($this->dir, $this->common, $this->family);
    $verdicts = nginxVerdict($run, 'NGX-2').' '.nginxVerdict($run, 'NGX-3');

    expect($verdicts)->toContain('FAIL')
        ->and($verdicts)->toContain($named);
})->with([
    // A dual-stack listener accepts IPv4 on the IPv6 socket and hands PHP a ::ffff: address, which
    // ADR-034's trusted list does not match.
    'ipv6only=off' => ['    listen [::]:8443 ssl ipv6only=off;', 'server', 'ipv6only=off'],
    // Both let a client's X_Forwarded_For reach PHP as a second HTTP_X_FORWARDED_FOR.
    'underscores_in_headers on' => ['	underscores_in_headers on;', 'http', 'underscores_in_headers'],
    'ignore_invalid_headers off' => ['	ignore_invalid_headers off;', 'http', 'ignore_invalid_headers'],
    // A listen address, not a parameter: a rule over parameters alone never sees it.
    'a unix listen address' => ['    listen unix:/run/nginx-extra.sock;', 'server', 'unix socket'],
]);

it('fails a fastcgi_param that replaces the header nginx received', function (): void {
    $dump = str_replace(
        'fastcgi_param  HTTPS              $https if_not_empty;',
        'fastcgi_param  HTTP_X_FORWARDED_FOR "203.0.113.9";',
        nginxCompliantDump(),
    );

    nginxDump($this->dir, $dump);

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-3'))->toContain('FAIL')
        ->and(nginxVerdict($run, 'NGX-3'))->toContain('replacing the header nginx received');
});

it('fails a REMOTE_ADDR that is not the peer nginx sees', function (): void {
    $dump = str_replace(
        'fastcgi_param  REMOTE_ADDR        $remote_addr;',
        'fastcgi_param  REMOTE_ADDR        $http_x_forwarded_for;',
        nginxCompliantDump(),
    );

    nginxDump($this->dir, $dump);

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-3'))->toContain('FAIL')
        ->and(nginxVerdict($run, 'NGX-3'))->toContain('REMOTE_ADDR');
});

it('calls a dump with no provenance markers unmeasurable, rather than reading it anyway', function (): void {
    /*
     * ⚠️ THE MARKERS ARE COMMENTS, so a dump captured through a comment filter has lost which file
     * each directive came from — and an include can no longer be resolved. That is a broken
     * instrument, not a broken host.
     */
    $stripped = preg_replace('/^# configuration file .*$/m', '', nginxCompliantDump()) ?? '';

    nginxDump($this->dir, $stripped);

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-1'))->toContain('VOID')
        ->and(nginxVerdict($run, 'NGX-1'))->toContain('provenance');
});

it('calls a configuration that moved between two reads unmeasurable', function (): void {
    nginxDump($this->dir, nginxCompliantDump(), nginxCompliantDump('    client_max_body_size 64m;'));

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-1'))->toContain('VOID')
        ->and(nginxVerdict($run, 'NGX-1'))->toContain('changed between two reads');
});

it('fails an IPv6 listener that is not v6only, and voids a host whose sockets it cannot see', function (string $rows, string $outcome, string $named): void {
    nginxSockets($this->dir, $rows);

    $run = nginxRun($this->dir, $this->common, $this->family);

    expect(nginxVerdict($run, 'NGX-4'))->toContain($outcome)
        ->and(nginxVerdict($run, 'NGX-4'))->toContain($named);
})->with([
    'a dual-stack listener' => [
        "LISTEN 0 511 [::]:443 [::]:* users:((\"nginx\",pid=1,fd=8)) ino:1 sk:1 cgroup:/system.slice/nginx.service <->\n",
        'FAIL',
        'not v6only',
    ],
    'no nginx socket at all' => [
        "LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=2,fd=3)) ino:2 sk:2 cgroup:/system.slice/ssh.socket <->\n",
        'VOID',
        'no LISTEN socket is owned by nginx',
    ],
]);

it('refuses to run unprivileged, because empty reads look clean', function (): void {
    $run = nginxRun($this->dir, $this->common, $this->family, ['STUB_UID' => '1000']);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('run as root')
        ->and($run->getOutput())->not->toContain('VERDICT NGX-1 PASS');
});
