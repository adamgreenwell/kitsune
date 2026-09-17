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
 * The throttle family of the ADR-034 runbook — deploy/runbook/outside/throttle.php — run for real with
 * ssh and curl stubbed (issue #111).
 *
 * ⚠️ THIS FAMILY ANSWERS THE QUESTION ADR-034's TRUST TURNS ON: what does the login throttle count by? If
 * a forged `X-Forwarded-For` reached `request()->ip()`, five attempts under one forged address and five
 * under the next would never trip it, and every response along the way would look exactly right.
 *
 * ⚠️ SO THE CASES BELOW ARE WORLDS, NOT INPUTS. The curl stub is a model of the server with the bucket key
 * as its one free choice: the edge's address (a sound host), the leftmost forwarded entry (trust-all), the
 * loopback address (every visitor is one), a constant per address family, the session, the email, the
 * hostname. Four of those produce the SAME eight responses a sound host produces — five rejections, a
 * throttle, a rejection from the second address, a throttle for a new session — which is why the family
 * reads the host's own store as well, and why a stub that simply scripted the answers would let every one
 * of these pass.
 *
 * ⚠️ AND THE VOIDS MATTER MORE THAN THE FAILS. A bucket somebody else had already filled, a guard that
 * answered instead of the throttle, a store read that holds nothing this run wrote: each would let a PASS
 * be about nothing at all. They are unmeasurable, not passing, and the run exits non-zero for them exactly
 * as for a failure.
 *
 * Needs only PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-throttle-'.bin2hex(random_bytes(6));
    $this->family = $repo.'/deploy/runbook/outside/throttle.php';
    $this->state = $this->dir.'/state';

    File::makeDirectory($this->dir.'/bin', 0755, true);
    File::makeDirectory($this->state, 0755, true);
    File::makeDirectory($this->dir.'/runbook/host', 0755, true);
    File::makeDirectory($this->dir.'/tmp', 0755, true);

    // The instruments the family sends to the host. The stubbed ssh discards the bytes, so a marker is
    // enough to prove the family read the real files from the path it was told to use — except the store
    // instrument, whose own behaviour has its own test.
    File::put($this->dir.'/runbook/host/common.sh', "# common.sh\n");
    File::put($this->dir.'/runbook/host/probe-log.sh', "# probe-log.sh\n");
    File::put($this->dir.'/runbook/host/throttle-store.php', "<?php // throttle-store.php\n");

    throttleDump($this->state, throttleSites());
    throttleCase($this->state, []);

    foreach (throttleStubs() as $name => $body) {
        File::put($this->dir.'/bin/'.$name, $body);
        chmod($this->dir.'/bin/'.$name, 0755);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/** The hostnames the fixture server serves, and the roots its blocks declare for them. */
function throttleSites(): array
{
    return [
        'stage.kitsune.test' => ['/home/kitsune/site/current/public'],
        'stage-fr.kitsune.test' => ['/home/kitsune/site/current/public'],
        'stage-he.kitsune.test' => ['/home/kitsune/site/current/public'],
    ];
}

/**
 * A configuration dump as `nginx -T` writes one, with the probe log's own snippet in it.
 *
 * ⚠️ THE SNIPPET IS THE POINT OF THE FIXTURE. The family reads the dump AFTER installing the probe log, so
 * what it parses contains a `log_format` holding braces, semicolons and double quotes inside single-quoted
 * strings, and a `map` block with a quoted regex. A brace counter that did not know about quotes would
 * close the `http` block in the middle of that string and read every server after it as nested — and the
 * family would find no site, on a host serving three.
 */
function throttleDump(string $state, array $sites, string $extra = ''): void
{
    $blocks = '';

    foreach ($sites as $hostname => $roots) {
        $declared = implode('', array_map(static fn (string $root): string => "        root {$root};\n", $roots));
        $blocks .= <<<CONF
            server {
                listen 80;
                server_name {$hostname};
                return 301 https://\$host\$request_uri;
            }
            server {
                listen 443 ssl;
                server_name {$hostname};
        {$declared}        location /assets {
                    root /var/www/shared-assets;
                }
            }

        CONF;
    }

    File::put($state.'/dump', <<<CONF
        # configuration file /etc/nginx/nginx.conf:
        user www-data;

        http {
            include /etc/nginx/mime.types;

            # configuration file /etc/nginx/conf.d/kitsune-probe-0123456789abcdef0123456789abcdef.conf:
            log_format kitsune_probe_0123 escape=json
              '{"pid":"\$pid","remote_addr":"\$remote_addr","host":"\$host"'
              ',"request_uri":"\$request_uri","status":"\$status";}';

            map \$http_x_kitsune_probe \$kitsune_probe_0123 {
                default              0;
                "~^0123456789abcdef" 1;
            }

            access_log /run/kitsune-probe/0123.log kitsune_probe_0123 if=\$kitsune_probe_0123;

            # configuration file /etc/nginx/sites-enabled/000-catch-all:
            server {
                listen 80 default_server;
                listen 443 ssl default_server;
                server_name _;
                root /var/www/html;
            }

        {$blocks}{$extra}
        }

        CONF);
}

/** The world this case is in, as the stubs read it. */
function throttleCase(string $state, array $case): void
{
    File::put($state.'/case.json', (string) json_encode($case, JSON_PRETTY_PRINT));
}

/** Seed a bucket that this run did not fill, as an earlier run or a co-tenant of the egress would. */
function throttleBucket(string $state, string $address, int $attempts, int $expiresIn): void
{
    $buckets = json_decode((string) @file_get_contents($state.'/buckets.json'), true) ?: [];
    $buckets[$address] = ['attempts' => $attempts, 'timer' => time() + $expiresIn];

    File::put($state.'/buckets.json', (string) json_encode($buckets, JSON_PRETTY_PRINT));
}

/**
 * Run the family against the fixture tree.
 *
 * @param  array<string, string|false>  $env
 */
function throttleRun(string $dir, string $family, string $expect = 'tunnel', array $env = []): Process
{
    $process = new Process(
        ['php', $family, '--host', 'forge@fixture', '--expect', $expect, '--runbook', $dir.'/runbook'],
        $dir,
        array_replace([
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => $dir.'/tmp',
            'PATH' => $dir.'/bin:'.getenv('PATH'),
        ], $env),
    );

    $process->setTimeout(120);
    $process->run();

    return $process;
}

/** The verdict line for one check id. */
function throttleVerdict(Process $run, string $id): string
{
    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'VERDICT '.$id.' ')) {
            return $line;
        }
    }

    return '';
}

/** Every update request the stub answered, in order. */
function throttleAnswers(string $state): array
{
    $answers = [];

    foreach (preg_split('/\R/', (string) @file_get_contents($state.'/answers.jsonl')) ?: [] as $row) {
        $answer = json_decode($row, true);

        if (is_array($answer)) {
            $answers[] = $answer;
        }
    }

    return $answers;
}

/**
 * The stubs. They are models of the host and the server, not scripts of the answers.
 *
 * ⚠️ A STUB TIDIER THAN THE TOOL HIDES THE DEFECT. The curl stub keeps a real bucket and decides which one
 * a request falls in by a key function each case selects, so a family that assumed rather than measured
 * would pass every case; the ssh stub answers `nginx -T` with a dump rather than a list of hostnames, and
 * builds each probe line from the request that was actually made rather than from the case.
 *
 * @return array<string, string>
 */
function throttleStubs(): array
{
    return [
        'curl' => <<<'CURL_STUB'
#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * A stand-in for curl that answers as the server does, not as a passing run would like it to.
 *
 * ⚠️ IT IS A MODEL OF THE SERVER, NOT A SCRIPT OF THE ANSWERS. A stub that replied "five rejections and a
 * throttle" could not tell a family that measures from one that assumes: every case below would pass. So
 * this keeps a bucket, decides WHICH bucket each request falls in by a key function the case selects, and
 * applies the limiter's own rule — five hits fill it, a sixth is thrown while the timer lives, and a
 * throttled request is not counted. The worlds the design is afraid of are then key functions: the edge's
 * address (a sound host), the leftmost forwarded entry (trust-all), the loopback address (every visitor is
 * one), a constant per address family, the session, the email.
 *
 * ⚠️ AND EVERY ANSWER CARRIES THE RENDERED FORM, as a real one does — so a family that classified by body
 * text would read `data.email` out of a throttled answer and call it a rejection.
 */

$dir = dirname(__DIR__);
$state = $dir.'/state';

/** @return array<string, mixed> */
function readJson(string $path, array $fallback = []): array
{
    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);

    return is_array($decoded) ? $decoded : $fallback;
}

function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$case = readJson($state.'/case.json', []);

// The attempt this invocation is making, read from the probe id every attempt carries. The preflight
// traces and the login-page fetches carry none, so they are attempt 0.
$attempt = 0;

foreach ($argv as $argument) {
    if (preg_match('/^X-Kitsune-Probe: [0-9a-f]+-(\d+)$/', $argument, $said) === 1) {
        $attempt = (int) $said[1];
    }
}

/*
 * ⚠️ THIS MACHINE'S OWN ADDRESS CAN MOVE MID-RUN, AND THE HOST IS NOT WHAT CHANGED. curl opens a fresh
 * connection per attempt, so a NAT/SNAT pool, a multi-homed or load-balanced egress, a CI runner or an
 * RFC 4941 temporary IPv6 address can map a later attempt to a second public address. `moved_from` is the
 * attempt at which this machine starts leaving from `moved4`/`moved6`: the trace, the bucket key and the
 * entry the edge appends all move together, exactly as a real remapping does.
 */
$moved = isset($case['moved_from']) && $attempt >= (int) $case['moved_from'];
$edges = [
    '-4' => ($moved ? ($case['moved4'] ?? '') : '') ?: ($case['edge4'] ?? '203.0.113.50'),
    '-6' => ($moved ? ($case['moved6'] ?? '') : '') ?: ($case['edge6'] ?? '2001:db8::50'),
];

// Split the argument list into transfer groups exactly as --next does.
$groups = [[]];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--next') {
        $groups[] = [];

        continue;
    }

    $groups[count($groups) - 1][] = $argument;
}

$lines = [];
$connects = 1;
$port = 51000 + (int) (@file_get_contents($state.'/port') ?: 0);
@file_put_contents($state.'/port', (string) ($port - 51000 + 1));

foreach ($groups as $group) {
    $family = '-4';
    $out = '';
    $jar = '';
    $url = '';
    $headers = [];
    $data = '';
    $format = '';

    for ($at = 0; $at < count($group); $at++) {
        $argument = $group[$at];

        match (true) {
            $argument === '-4', $argument === '-6' => $family = $argument,
            $argument === '-o' => $out = $group[++$at] ?? '',
            $argument === '-c', $argument === '-b' => $jar = $group[++$at] ?? '',
            $argument === '-H' => $headers[] = $group[++$at] ?? '',
            $argument === '-w' => $format = $group[++$at] ?? '',
            $argument === '--data-binary' => $data = (string) @file_get_contents(ltrim($group[++$at] ?? '', '@')),
            str_starts_with($argument, 'https://') => $url = $argument,
            default => null,
        };
    }

    if ($url === '') {
        continue;
    }

    // A case can make one address family unreachable, as a machine with no IPv6 route is.
    if (($case['no'.$family] ?? false) === true) {
        fwrite(STDERR, "curl: (7) Couldn't connect to server\n");

        exit(7);
    }

    $header = static function (string $name) use ($headers): string {
        foreach ($headers as $line) {
            if (stripos($line, $name.':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return '';
    };

    $path = (string) parse_url($url, PHP_URL_PATH);
    $hostname = (string) parse_url($url, PHP_URL_HOST);
    $edge = $edges[$family];
    $code = '200';
    $type = 'text/html';
    $body = '';

    if ($path === '/cdn-cgi/trace') {
        $body = "fl=1\nip=".($case['trace'.$family] ?? $edge)."\nts=".microtime(true)."\nvisit_scheme=https\n";
    } elseif ($path === '/admin/login') {
        $session = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(10));
        $memo = 'memo'.bin2hex(random_bytes(6));
        $snapshot = json_encode([
            'data' => [['email' => null, 'password' => null], []],
            // The panel's own login page class, which a case can change: it is part of the throttle key.
            'memo' => ['id' => $memo, 'name' => $case['components'][$hostname] ?? $case['component'] ?? 'Filament\\Auth\\Pages\\Login', 'path' => 'admin/login', 'method' => 'GET', 'children' => [], 'scripts' => [], 'assets' => [], 'errors' => [], 'locale' => 'en'],
            'checksum' => bin2hex(random_bytes(16)),
        ], JSON_UNESCAPED_SLASHES);

        $sessions = readJson($state.'/sessions.json');
        $sessions[$session] = ['token' => $token, 'memo' => $memo, 'snapshot' => $snapshot, 'hostname' => $hostname];
        writeJson($state.'/sessions.json', $sessions);

        // curl owns the jar, and the session line begins `#HttpOnly_` exactly as a real one does.
        file_put_contents($jar, "# Netscape HTTP Cookie File\n"
            ."#HttpOnly_{$hostname}\tFALSE\t/\tTRUE\t0\tkitsune_session\t{$session}\n"
            ."{$hostname}\tFALSE\t/\tTRUE\t0\tXSRF-TOKEN\t{$token}\n");

        $prefix = $case['prefix'] ?? 'b8bf0447';
        $body = loginPage($hostname, $token, $memo, $snapshot, $prefix);
    } elseif (preg_match('#^/livewire-[0-9a-f]{8}/update$#', $path) === 1) {
        [$code, $type, $body] = update($state, $case, $hostname, $path, $family, $edge, $jar, $headers, $header, $data);
    } else {
        $code = '404';
        $body = 'Not Found';
    }

    if ($out !== '') {
        file_put_contents($out, $body);
    }

    if ($format !== '') {
        $lines[] = strtr($format, [
            '%{http_code}' => $code,
            '%{num_connects}' => (string) $connects,
            '%{local_ip}' => $family === '-6'
                ? (string) ($case['local6'] ?? '2001:db8:1::9')
                : '192.168.1.9',
            '%{local_port}' => (string) $port,
            '%{remote_ip}' => $family === '-6' ? '2606:4700::1' : '104.18.0.1',
            '%{content_type}' => $type,
            '\n' => "\n",
        ]);
    }

    $connects = 0;
}

echo implode('', $lines);

exit(0);

/**
 * The login page, as Filament renders one: the token in two places, the notifications component's
 * snapshot BEFORE the login page's, and the snapshot HTML-escaped in a double-quoted attribute.
 */
function loginPage(string $hostname, string $token, string $memo, string $snapshot, string $prefix): string
{
    $notifications = json_encode([
        'data' => [],
        'memo' => ['id' => 'notif'.substr($memo, 4), 'name' => 'Filament\\Livewire\\Notifications', 'errors' => []],
        'checksum' => 'notachecksum',
    ], JSON_UNESCAPED_SLASHES);

    return '<!DOCTYPE html><html><head><meta name="csrf-token" content="'.$token.'"></head><body>'
        .'<div wire:snapshot="'.htmlspecialchars((string) $notifications, ENT_QUOTES).'" wire:effects="[]"></div>'
        .'<div wire:snapshot="'.htmlspecialchars($snapshot, ENT_QUOTES).'" wire:effects="[]">'
        .'<form wire:submit="authenticate">'
        .'<input wire:model="data.email" type="email"><input wire:model="data.password" type="password">'
        .'</form></div>'
        .'<script src="/livewire-'.$prefix.'/livewire.js" data-csrf="'.$token.'" '
        .'data-update-uri="https://'.$hostname.'/livewire-'.$prefix.'/update"></script>'
        .'</body></html>';
}

/**
 * The update endpoint: the guards a real one applies, and then the limiter's own rule.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function update(string $state, array $case, string $hostname, string $path, string $family, string $edge, string $jar, array $headers, callable $header, string $data): array
{
    // ⚠️ THE GUARDS COME FIRST, AS THEY DO ON THE HOST. Each one answers with a status the family must
    // read as unmeasurable rather than as a rejected sign-in.
    if ($header('X-Livewire') !== '1' || ! str_contains(strtolower($header('Content-Type')), 'json')) {
        return ['404', 'application/json', '{"message":"Not Found"}'];
    }

    // The same-origin bypass a real PreventRequestForgery grants: a family that sent Sec-Fetch-Site would
    // be waved past the token check, and a broken jar would still answer 200.
    $sameOrigin = strtolower($header('Sec-Fetch-Site')) === 'same-origin';

    $session = '';

    foreach (preg_split('/\R/', (string) @file_get_contents($jar)) ?: [] as $line) {
        $fields = preg_split('/\t/', $line) ?: [];

        if (($fields[5] ?? '') === 'kitsune_session') {
            $session = $fields[6] ?? '';
        }
    }

    $sessions = readJson($state.'/sessions.json');
    $known = $sessions[$session] ?? null;
    $sent = json_decode($data, true);
    $component = is_array($sent) ? ($sent['components'][0] ?? []) : [];

    if (! is_array($known) || (! $sameOrigin && (string) ($sent['_token'] ?? '') !== $known['token'])) {
        return ['419', 'application/json', '{"message":"CSRF token mismatch."}'];
    }

    if (($component['snapshot'] ?? '') !== $known['snapshot']) {
        // A corrupted payload renders an EMPTY body, which is the shape that must not read as JSON.
        return ['419', '', ''];
    }

    $forced = $case['answer'] ?? '';

    if ($forced !== '') {
        $answers = [
            '419-csrf' => ['419', 'application/json', '{"message":"CSRF token mismatch."}'],
            '419-empty' => ['419', '', ''],
            '404' => ['404', 'application/json', '{"message":"Not Found"}'],
            '429' => ['429', 'application/json', '{"message":"Too many invalid Livewire requests."}'],
            '429-edge' => ['429', 'text/html', '<html><body>error code: 1015</body></html>'],
            '403' => ['403', 'text/html', '<html><body>cf-mitigated</body></html>'],
            '500' => ['500', 'text/html', '<html><body>Server Error</body></html>'],
            '302' => ['302', 'text/html', ''],
            '200-html' => ['200', 'text/html', '<html><body>data.email</body></html>'],
        ];

        if (isset($answers[$forced])) {
            probe($state, $case, $header, $hostname, $path, $edge, $answers[$forced][0]);

            return $answers[$forced];
        }
    }

    $email = (string) ($component['updates']['data.email'] ?? '');
    $forwarded = $header('X-Forwarded-For');
    $underscore = $header('X_Forwarded_For');
    $number = (int) (substr($header('X-Kitsune-Probe'), 33) ?: 0);

    // ⚠️ THE WORLD THIS CASE IS IN, AS A KEY FUNCTION. Which bucket a request falls in is the whole
    // question the family exists to answer, so it is the one thing a case chooses.
    $key = match ($case['key'] ?? 'edge') {
        'leftmost' => $forwarded === '' ? $edge : trim(explode(',', $forwarded)[0]),
        'underscore' => in_array($number, $case['underscore_on'] ?? [], true) && $underscore !== '' ? $underscore : $edge,
        'loopback' => '127.0.0.1',
        'address-family' => 'constant-'.($family === '-6' ? 'v6' : 'v4'),
        'session' => 'session-'.$session,
        'email' => 'email-'.$email,
        'address+session' => $edge.'|'.$session,
        'address+email' => $edge.'|'.$email,
        'address+hostname' => $edge.'|'.$hostname,
        default => $edge,
    };

    $buckets = readJson($state.'/buckets.json');
    $now = time();
    $held = $buckets[$key] ?? ['attempts' => 0, 'timer' => null];
    $alive = is_int($held['timer']) && $held['timer'] > $now;

    if (! $alive) {
        $held = ['attempts' => 0, 'timer' => null];
    }

    // ⚠️ A HOST WITH NO WORKING LOGIN THROTTLE AT ALL. `rateLimit()` dropped from a customised login page,
    // or a limiter store whose writes silently go nowhere — a file cache on an unwritable path, a cache the
    // release cannot reach. Nothing is counted and nothing is ever refused, while `config` still calls the
    // store `file`, so the store-that-forgets FAIL cannot fire. This is the single most dangerous host
    // state this family exists to find.
    $counting = ($case['no_limiter'] ?? false) !== true;
    $throttled = $counting && $held['attempts'] >= 5 && $alive;

    if (! $throttled) {
        // The limiter arms the timer on the bucket's FIRST hit and never refreshes it.
        $held['timer'] = $alive ? $held['timer'] : $now + 60;
        $held['attempts']++;

        // A store that forgets between requests can never reach the limit, and a limiter that was never
        // called writes nothing at all.
        if ($counting && ($case['driver'] ?? 'file') !== 'array') {
            $buckets[$key] = $held;
            writeJson($state.'/buckets.json', $buckets);
        }
    }

    probe($state, $case, $header, $hostname, $path, $edge, '200');
    $body = answer($known, $throttled);

    // Kept so a case can show what a family that read the body as text would have seen.
    file_put_contents($state.'/answers.jsonl', json_encode([
        'probe' => $header('X-Kitsune-Probe'),
        'throttled' => $throttled,
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

    return ['200', 'application/json', $body];
}

/** The line nginx would have written for this request, for the probe-log instrument to hand back. */
function probe(string $state, array $case, callable $header, string $hostname, string $path, string $edge, string $status): void
{
    $forwarded = $header('X-Forwarded-For');
    $id = $header('X-Kitsune-Probe');

    $line = [
        'pid' => '29271',
        'remote_addr' => $case['remote_addr'] ?? '127.0.0.1',
        'remote_port' => '35572',
        'realip_remote_addr' => $case['remote_addr'] ?? '127.0.0.1',
        'proxy_protocol_addr' => '',
        'host' => $hostname,
        'http_host' => $hostname,
        'server_name' => $hostname,
        'server_port' => '443',
        'https' => 'on',
        'request_method' => 'POST',
        'request_uri' => $path,
        'status' => $status,
        'upstream_addr' => $case['upstream'] ?? 'unix:/run/php/php8.5-fpm.sock',
        'upstream_status' => $status,
        // The edge appends what it saw to whatever arrived, which is what makes "the last entry" mean
        // something. A case can rewrite it, as Cloudflare's own transforms do, and `hop` is a forwarding
        // hop on the OPERATOR's side of the edge — a corporate egress proxy, a CI runner's outbound proxy
        // — which is in every chain that reaches nginx and is not a forgery this run planted.
        'xff' => $case['xff'] ?? trim(($forwarded === '' ? '' : $forwarded.', ')
            .(($case['hop'] ?? '') !== '' ? $case['hop'].', ' : '').$edge),
        'cf_connecting_ip' => $edge,
        'cf_ray' => 'a3bb0208bab4efad-CMH',
        'probe' => $id,
    ];

    file_put_contents($state.'/probes.jsonl', json_encode($line, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

/** The two answers a real sign-in attempt produces, both carrying the re-rendered form. */
function answer(array $known, bool $throttled): string
{
    $snapshot = json_decode($known['snapshot'], true);
    $snapshot['memo']['errors'] = $throttled ? [] : ['data.email' => ['These credentials do not match our records.']];

    return (string) json_encode([
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            'effects' => [
                'dispatches' => $throttled ? [['name' => 'notificationsSent', 'params' => []]] : [],
                // ⚠️ EVERY ANSWER RE-RENDERS THE FORM, so the string `data.email` is in the throttled one
                // too. A family that classified by body text would read this as a rejected sign-in.
                'html' => '<form wire:submit="authenticate"><input wire:model="data.email"></form>',
            ],
        ]],
    ], JSON_UNESCAPED_SLASHES);
}
CURL_STUB,
        'ssh' => <<<'SSH_STUB'
#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * A stand-in for ssh that answers as the host does: the running configuration, the probe log, the owner of
 * the release, and the rate-limiter store.
 *
 * ⚠️ THE DUMP IS A DUMP, AND THE PROBE LINE COMES FROM THE REQUEST THAT WAS MADE. Answering with a clean
 * list of hostnames, or with a probe line written by the case rather than by the request, would put the
 * two things that can silently return nothing — the configuration parser and the arrival assertion —
 * beyond any test's reach.
 */

$dir = dirname(__DIR__);
$state = $dir.'/state';
$arguments = implode(' ', array_slice($argv, 1));

// The payload the runbook pipes in is read and dropped, as a host would read it.
stream_get_contents(STDIN);

/** @return array<string, mixed> */
function readJson(string $path, array $fallback = []): array
{
    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);

    return is_array($decoded) ? $decoded : $fallback;
}

$case = readJson($state.'/case.json', []);

if (str_contains($arguments, 'nginx -T')) {
    if (($case['dump_fails'] ?? false) === true) {
        fwrite(STDERR, "nginx: [emerg] bind() to 0.0.0.0:443 failed (98: Address already in use)\n");
        fwrite(STDERR, "nginx: [emerg] still could not bind()\n");

        exit(1);
    }

    echo (string) @file_get_contents($state.'/dump');

    exit(0);
}

if (str_contains($arguments, 'stat -c %U')) {
    if (($case['owner_fails'] ?? false) === true) {
        fwrite(STDERR, "stat: cannot stat: No such file or directory\n");

        exit(1);
    }

    echo ($case['owner'] ?? 'kitsune')."\n";

    exit(0);
}

// sudo -n -u <owner> php -d display_errors=stderr -- <hex of the request>
if (str_contains($arguments, 'php -d display_errors=stderr')) {
    if (($case['store_fails'] ?? false) === true) {
        fwrite(STDERR, "sudo: a password is required\n");

        exit(1);
    }

    $request = json_decode((string) @hex2bin((string) array_slice($argv, -1)[0]), true);
    $labels = is_array($request) ? ($request['labels'] ?? []) : [];
    $buckets = readJson($state.'/buckets.json');
    $reads = (int) (@file_get_contents($state.'/store-reads') ?: 0);
    file_put_contents($state.'/store-reads', (string) ($reads + 1));

    echo 'STORE '.json_encode([
        'base' => $request['base'] ?? '',
        'driver' => $case['driver'] ?? 'file',
        'prefix' => 'kitsune-cache-',
        'livewire_prefix' => $case['store_prefix'] ?? '/livewire-'.($case['prefix'] ?? 'b8bf0447'),
        'component' => $request['component'] ?? '',
        'method' => $request['method'] ?? '',
        'now' => time(),
    ], JSON_UNESCAPED_SLASHES)."\n";

    // ⚠️ THE KEY HOLDS THE COMPONENT, SO A READ FOR ANOTHER CLASS FINDS NOTHING. A stub that answered by
    // address alone could not tell a family that asks about the login page the host actually named from
    // one that asks about a class it knew in advance.
    $asked = (string) ($request['component'] ?? '');
    $serves = $case['component'] ?? 'Filament\\Auth\\Pages\\Login';

    foreach ($labels as $label => $address) {
        // ⚠️ A STORE THIS INSTRUMENT CANNOT SEE READS AS EMPTY, WHICH IS THE POINT OF THE CASE. A wrong
        // store, a wrong prefix or a read after the window all look exactly like this from outside.
        $held = (($case['store_blind'] ?? false) === true || $asked !== $serves) ? null : ($buckets[$address] ?? null);

        // An entry whose timer has passed is gone: a cache with a TTL forgets it, and a store that kept
        // handing it back would make the family's bounded wait one it could never come out of.
        if (is_array($held) && is_int($held['timer'] ?? null) && $held['timer'] <= time()) {
            $held = null;
        }

        echo 'BUCKET '.$label.' '.json_encode([
            'address' => $address,
            'key' => 'livewire-rate-limiter:'.sha1(($request['component'] ?? '').'|authenticate|'.$address),
            'attempts' => is_array($held) ? (int) $held['attempts'] : 0,
            'timer' => is_array($held) && is_int($held['timer'] ?? null) ? $held['timer'] : null,
        ], JSON_UNESCAPED_SLASHES)."\n";

        echo 'CHECKSUM '.$label.' '.json_encode([
            'key' => 'livewire-checksum-failures:'.$address,
            'attempts' => (int) ($case['checksum'][$label] ?? 0),
        ], JSON_UNESCAPED_SLASHES)."\n";
    }

    echo 'STATE store read '.count($labels)." labels\n";

    exit(0);
}

$words = preg_split('/\s+/', trim($arguments)) ?: [];
$at = array_search('--', $words, true);
$action = $at === false ? '' : ($words[$at + 1] ?? '');

if ($action === 'start') {
    if (($case['start_fails'] ?? false) === true) {
        fwrite(STDERR, "Refusing to check: a probe is already installed\n");

        exit(1);
    }

    touch($state.'/installed');

    if (($case['start_installed_then_failed'] ?? '') !== '') {
        fwrite(STDERR, $case['start_installed_then_failed']."\n");

        exit(1);
    }

    echo "STATE start installed, reloaded, drained in 0s; workers now 29271\n";

    exit(0);
}

if ($action === 'collect') {
    $id = $words[$at + 3] ?? '';

    $number = (int) substr($id, (int) strrpos($id, '-') + 1);

    if (in_array($number, $case['probe_missing'] ?? [], true)) {
        fwrite(STDERR, "Refusing to check: no line carries the id {$id}\n");

        exit(1);
    }

    foreach (preg_split('/\R/', (string) @file_get_contents($state.'/probes.jsonl')) ?: [] as $row) {
        $line = json_decode($row, true);

        if (is_array($line) && ($line['probe'] ?? '') === $id) {
            echo 'PROBE '.$id.' '.json_encode($line, JSON_UNESCAPED_SLASHES)."\n";
            echo "STATE collect one line for {$id}\n";

            exit(0);
        }
    }

    fwrite(STDERR, "Refusing to check: no line carries the id {$id}, so that request never reached this server\n");

    exit(1);
}

if ($action === 'stop') {
    file_put_contents($state.'/stopped', $arguments."\n", FILE_APPEND);

    if (($case['stop_fails'] ?? false) === true) {
        fwrite(STDERR, "Refusing to check: the configuration does not hash back to its baseline\n");

        exit(1);
    }

    if (is_file($state.'/installed')) {
        unlink($state.'/installed');
        echo "STATE stop removed, dead-man cancelled, configuration hashes back to its baseline\n";
    } else {
        echo "STATE stop absent no snippet and no dead-man timer, so nothing of this probe was installed and nginx was not reloaded\n";
    }

    exit(0);
}

fwrite(STDERR, "stub ssh: nothing answers [{$arguments}]\n");

exit(1);
SSH_STUB,
    ];
}

it('passes a host where the throttle counts by the requester and nothing else', function (): void {
    $run = throttleRun($this->dir, $this->family);
    $answers = throttleAnswers($this->state);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('PASS')
        ->and($run->getOutput())->toContain('SENTINEL throttle 3 THR-1 THR-2 THR-3');

    /*
     * ⚠️ THE SHAPE OF THE RUN, NOT JUST ITS VERDICT. Five rejections and a throttle on the first hostname,
     * one already-throttled probe on each of the others, then the second address and the fresh session:
     * a family that sent fewer would be proving less, and one that sent five fresh attempts per hostname
     * would be contaminating its own window.
     */
    $throttled = array_values(array_filter($answers, static fn (array $answer): bool => $answer['throttled']));

    expect($answers)->toHaveCount(10)
        ->and($throttled)->toHaveCount(4);

    // ⚠️ AND NOTHING PRIVATE REACHES THE STREAM. The records carry addresses and statuses; the session
    // cookies and CSRF tokens the run held stay in the work directory, which is removed.
    $sessions = array_keys(json_decode((string) File::get($this->state.'/sessions.json'), true) ?: []);

    expect($sessions)->not->toBe([]);

    foreach ($sessions as $session) {
        expect($run->getOutput())->not->toContain((string) $session);
    }

    // The work directory, jars and payloads included, is gone.
    expect(glob($this->dir.'/tmp/kitsune-throttle-*') ?: [])->toBe([]);
});

it('states the same sign-in count in all three places it tells the operator, and sends exactly that many', function (): void {
    /*
     * ⚠️ THE ONE NUMBER THAT SIZES THE MAINTENANCE WINDOW. A run sends LIMIT+1 attempts on the first
     * hostname, one already-throttled probe on each of the others, and two more for THR-2: seven plus one
     * per served hostname. All three places that tell an operator what a run costs a live server said
     * "eight times", which is only true of a host serving one — and this fixture's own host serves three.
     *
     * ⚠️ AND THE PROSE IS HELD TO THE MEASUREMENT, NOT JUST TO ITSELF. Three copies of one sentence are
     * exactly the drift CLAUDE.md warns about, so they are held to each other AND to what the family
     * actually sends: a count that changed in the code and not in the docs fails here.
     */
    $repo = dirname(__DIR__, 3);
    $stated = 'seven times plus once more for every hostname the server serves';

    // ⚠️ str_contains RATHER THAN toContain, BECAUSE toContain IS VARIADIC: a second argument meant as a
    // failure message becomes a second needle, and the assertion then demands its own message be in the
    // file. toBeTrue and toBeFalse take the message the way this needs.
    foreach (['deploy/runbook/README.md', 'deploy/runbook/manifest.txt', 'deploy/runbook/outside/throttle.php'] as $file) {
        $source = File::get($repo.'/'.$file);

        expect(str_contains($source, $stated))->toBeTrue($file.' does not state the sign-in count the other two do')
            ->and(str_contains($source, 'eight times'))->toBeFalse($file.' still states the old count');
    }

    throttleRun($this->dir, $this->family);

    expect(throttleAnswers($this->state))->toHaveCount(7 + count(throttleSites()));
});

it('claims of the underscore spelling only what it could see, and names what it could not', function (): void {
    /*
     * ⚠️ HALF THE FORGERY IS UNOBSERVABLE, AND THE PASS TEXT SAID IT HAD BEEN OBSERVED. Every forged
     * attempt carries both spellings — `X-Forwarded-For` and `X_Forwarded_For`, which FPM maps onto the
     * same PHP variable — and the arrival assertion reads only the dashed one out of the probe line,
     * because nginx's default `underscores_in_headers off` drops an underscored header before any log sees
     * it. On a default host an underscore bucket reading 0 is therefore exactly as consistent with "nginx
     * discarded it before PHP could see it" as with "the host ignored the forgery" — the very tautology
     * the probe log exists to prevent for the dashed spelling, and the family's own comment says so.
     *
     * A correct verdict with a false explanation is the defect class this runbook treats as its own, and
     * the design had already settled which way to resolve it: the underscore spelling's PASS claims only
     * that it filled no bucket.
     */
    $run = throttleRun($this->dir, $this->family);
    $verdict = throttleVerdict($run, 'THR-1');

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and($verdict)->toContain('PASS')
        // What it may claim: the dashed spelling arrived, and neither spelling filled a bucket.
        ->and($verdict)->toContain('every forged X-Forwarded-For arrived at nginx and filled nothing')
        ->and($verdict)->toContain('the X_Forwarded_For sent beside it filled nothing either')
        ->and($verdict)->toContain('underscores_in_headers')
        // What it may not claim: that both spellings were seen to arrive.
        ->and($verdict)->not->toContain('X-Forwarded-For and X_Forwarded_For arrived');

    /*
     * ⚠️ AND THE CASE IS LIVE. The stub builds the chain nginx logs from the dashed header alone, exactly
     * as an `underscores_in_headers off` host does, so the underscore value really is in no line nginx
     * wrote — while the dashed one is in every one of them. Without this the assertions above could pass
     * against a fixture where both spellings happened to be visible.
     */
    $probes = (string) File::get($this->state.'/probes.jsonl');

    expect($probes)->toContain('192.0.2.1')
        ->and($probes)->not->toContain('198.51.100.');
});

it('reads the throttle out of the snapshot, where the body text says the opposite', function (): void {
    /*
     * ⚠️ THE TRAP THE #111 DRAFT WALKED INTO. Every 200 re-renders the login form, and the form contains
     * `data.email` — so "the answer mentions data.email" is true of the throttled answer as well. A family
     * that classified by body text would read the throttle as a sixth rejection and report that the host
     * does not throttle at all.
     *
     * This asserts BOTH halves: the text really is in the throttled answer (so the trap was live), and the
     * family still called it a throttle.
     */
    $run = throttleRun($this->dir, $this->family);
    $throttled = array_values(array_filter(throttleAnswers($this->state), static fn (array $answer): bool => $answer['throttled']));

    expect($throttled)->not->toBe([])
        ->and($throttled[0]['body'])->toContain('data.email')
        ->and($throttled[0]['body'])->toContain('notificationsSent')
        ->and($run->getOutput())->toContain('attempt 6 over IPv4')
        ->and($run->getOutput())->toContain('— THROTTLED')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS');
});

it('fails a forged forwarding header that reached the address the throttle counts by', function (string $key, array $case, string $named): void {
    /*
     * ⚠️ THE WHOLE REASON ADR-034 NEEDS CHECKING. With the leftmost forwarded entry winning, each of the six
     * attempts lands in a bucket of its own, the sixth is never throttled, and nothing in the responses says
     * why. The forged buckets in the store say it.
     */
    throttleCase($this->state, array_replace(['key' => $key], $case));

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('a forged forwarding header reached request()->ip()')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($named);
})->with([
    'every attempt keyed on the leftmost entry' => ['leftmost', [], '192.0.2.1'],
    // ⚠️ THE UNDERSCORE SPELLING, WINNING ON SOME ATTEMPTS ONLY. FPM maps `X_Forwarded_For` onto the same
    // variable, so a host that keeps the later of the two leaks it — and a leak on two attempts out of six
    // still leaves four in the requester's bucket, which throttles at six and looks entirely healthy.
    'the underscore spelling on two attempts' => ['underscore', ['underscore_on' => [2, 4]], '198.51.100.2'],
]);

it('fails a host where every request is counted as the loopback address', function (): void {
    /*
     * ⚠️ THE WORLD THE RESPONSES CANNOT SEE. If `ip()` is `127.0.0.1` for everybody, the six attempts still
     * fill one bucket and the sixth is still throttled: the responses are identical to a sound host's. What
     * differs is whose bucket filled — and on a live server this state locks every visitor out of signing
     * in, not just the operator.
     */
    throttleCase($this->state, ['key' => 'loopback']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('every request is being counted as the loopback address')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('127.0.0.1');
});

it('voids, and never fails, when the store holds nothing this run could have written', function (string $key, array $case): void {
    /*
     * ⚠️ A STORE FAIL MUST PROVE THE RUN'S WRITES ARE IN THE STORE THAT WAS READ. Both cases here read back
     * zero for every labelled address: one because the host keys on something no label names, the other
     * because the instrument read a different store from the one the site writes to. From outside they are
     * the same observation, and calling either a FAIL would be accusing a host of a fault the check cannot
     * see. It is unmeasurable — which still exits non-zero, and still is not a pass.
     */
    throttleCase($this->state, array_replace(['key' => $key], $case));

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('no bucket this run could have written holds anything')
        ->and(throttleVerdict($run, 'THR-1'))->not->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID');
})->with([
    'one bucket per address family' => ['address-family', []],
    'a store the instrument cannot see' => ['edge', ['store_blind' => true]],
]);

it('fails a throttle that never engages, and names the store that forgets', function (): void {
    /*
     * A limiter on an `array` store forgets between requests, so the bucket never reaches five and the
     * sixth attempt is answered like the first. The bucket read is empty here too — but the STORE line is
     * positive evidence of its own, so this is a FAIL naming the driver rather than an unmeasurable run.
     */
    throttleCase($this->state, ['driver' => 'array']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('[array] store, which cannot hold a bucket')
        // ⚠️ AND IT LEFT THE OTHER HOSTNAMES ALONE. With no full bucket to meet, a probe against them would
        // measure nothing and lock another hostname's sign-in out for it.
        ->and(throttleVerdict($run, 'THR-1'))->not->toContain('answered a sign-in normally');
});

it('fails a host whose login throttle never engages, though its store persists', function (): void {
    /*
     * ⚠️ THE LOUDEST FINDING THIS FAMILY EXISTS TO MAKE, AND IT USED TO READ AS A FAULT OF THE RUNBOOK.
     * `rateLimit()` dropped from a customised login page, or a limiter store whose writes silently go
     * nowhere, answers every attempt like the first and leaves every labelled bucket at 0 — while the
     * driver is an ordinary `file`, so the store-that-forgets FAIL cannot fire either.
     *
     * Judged store-first, that produced "the writes went somewhere this instrument did not look", which
     * points the operator at the runbook's own instrument rather than at a host with no login throttle at
     * all. The two observations are not ambiguous together: a blind reader cannot explain an unthrottled
     * sixth attempt, because a host that throttled would have answered THROTTLED whichever store was read.
     */
    throttleCase($this->state, ['no_limiter' => true]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('the sixth attempt from one address was not throttled')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('the login throttle does not hold on this host')
        // ⚠️ AND NOT A WORD ABOUT THE INSTRUMENT. The blind-store reason is true of this run's store read
        // and says nothing about why the host answered six attempts in a row.
        ->and(throttleVerdict($run, 'THR-1'))->not->toContain('this instrument did not look')
        // The five and the sixth were answered, and nothing was sent to the other hostnames: with no full
        // bucket to meet, a probe against them would measure nothing and lock their sign-in out for it.
        ->and(throttleAnswers($this->state))->toHaveCount(6);
});

it('fails a key that holds the session or the email as well as the address', function (string $key): void {
    // One hostname, because every hostname gets a session of its own: with three, a per-session key is
    // caught one step earlier, as hostnames that answer normally while the shared bucket is full.
    throttleDump($this->state, ['stage.kitsune.test' => ['/home/kitsune/site/current/public']]);

    /*
     * ⚠️ WHAT THE EIGHTH ATTEMPT IS FOR. A key of address-and-session throttles the sixth attempt, lets the
     * second address through, and fills a bucket of its own — every response a sound host gives. Only an
     * attempt from a NEW session with a NEW email, from the address that is already throttled, tells them
     * apart: on a sound host it is throttled, and here it is not.
     */
    throttleCase($this->state, ['key' => $key]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-2'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('holds the session or the email rather than the address alone');
})->with([
    'the session' => ['address+session'],
    'the email' => ['address+email'],
]);

it('fails a hostname that answers a sign-in while the shared bucket is full', function (): void {
    /*
     * The throttle key holds no hostname, so every hostname of one app shares a bucket per address. A
     * hostname that answered normally while that bucket was full is counting under a different key — a
     * forged or host-derived value — and it is named.
     */
    throttleCase($this->state, ['key' => 'address+hostname']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('FAIL')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('stage-he.kitsune.test answered a sign-in normally while the shared bucket was full');
});

it('voids a run a guard answered, rather than reading it as the throttle', function (string $answer, string $named): void {
    /*
     * ⚠️ A 404, 419 OR 429 MEANS THE REQUEST NEVER REACHED THE THROTTLE. Each of these is a guard in front
     * of it — the Livewire headers, the CSRF token, the payload checksum, Livewire's own failure limiter,
     * the edge — and the throttle itself is NEVER a 429: Filament catches the limiter's exception and
     * answers 200 with a notification. A family that read any of these as a rejected sign-in, or as a
     * throttle, would be reporting on something it never measured.
     */
    throttleCase($this->state, ['answer' => $answer]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($named)
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID');
})->with([
    'the CSRF guard' => ['419-csrf', 'it answered 419'],
    'a corrupted payload, whose body is empty' => ['419-empty', 'it answered 419'],
    'the Livewire headers' => ['404', 'it answered 404'],
    "Livewire's own failure limiter" => ['429', 'it answered 429'],
    'the edge rate-limiting the run' => ['429-edge', 'it answered 429'],
    'the edge challenging the run' => ['403', 'it answered 403'],
    'the application erroring' => ['500', 'it answered 500'],
    'a redirect' => ['302', 'it answered 302'],
    'HTML that mentions the field a classifier would match' => ['200-html', 'rather than JSON'],
]);

it('voids a bucket somebody else had already filled, rather than measuring through it', function (): void {
    /*
     * ⚠️ THE FALSE FAIL THAT WOULD HAVE SUNK THIS CHECK. The 60 seconds are armed by the bucket's FIRST hit
     * and never refreshed, and the key has no hostname — so an earlier run, another hostname's run, or the
     * operator's own mistyped sign-in leaves a bucket that is already part-full. Measuring through it would
     * produce a throttle at the third attempt and a FAIL against a host that is fine.
     */
    throttleBucket($this->state, '203.0.113.50', 2, 600);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('already filled before this run began')
        ->and(throttleVerdict($run, 'THR-1'))->not->toContain('FAIL')
        // Nothing was sent: the window was never opened against a bucket that could not be told apart.
        ->and(throttleAnswers($this->state))->toBe([]);
});

it('waits out a bucket that is about to expire, and then measures', function (): void {
    // The same seam, from the other side: a bucket whose timer falls inside the bound is waited out once,
    // and the run then measures a window it owns. The store is read three times, not two.
    // Live when the pre-read finds it, and gone within the bound — the wait is measured, not asserted to
    // the second: how long the run takes to reach the pre-read is the machine's business, not the check's.
    throttleBucket($this->state, '203.0.113.50', 5, 4);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain('for a bucket this run did not fill to expire (v4 at 5)')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and((int) File::get($this->state.'/store-reads'))->toBe(3);
});

it('voids a window that had already gone before the store could be read', function (): void {
    // The guard that stops a slow run reporting on a window that closed underneath it.
    $run = throttleRun($this->dir, $this->family, 'tunnel', ['KITSUNE_THROTTLE_WINDOW_GUARD' => '0']);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('of the 60-second window had already gone');
});

it('voids the second address when this machine has no route to one, and never fails it', function (): void {
    /*
     * ⚠️ NOT A SILENT SKIP, AND NOT A FAILURE OF THE HOST. Without a second egress there is nothing to show
     * that the bucket is the address rather than everything — but that is this machine's limitation. A skip
     * would reach the completeness gate as a family that died; a FAIL would blame the server.
     */
    throttleCase($this->state, ['no-6' => true]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('no second address to try')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('PASS');
});

it('voids an attempt that did not leave over the address family it was meant to', function (string $local, string $named): void {
    /*
     * ⚠️ THE FLAG IS NOT THE MEASUREMENT. curl resets the local options at every `--next`, so `-6` given to
     * one group binds nothing for the rest; and a mapped `::ffff:` address is the IPv4 one wearing a
     * different spelling. Either would put the "second address" attempt in the very bucket it is supposed
     * to be separate from, and a throttle there would read as a sound host's rejection.
     */
    throttleCase($this->state, ['local6' => $local]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-2'))->toContain($named);
})->with([
    'an IPv4 local address on the -6 transfer' => ['192.168.1.9', 'did not leave over the family it was meant to'],
    'a mapped address' => ['::ffff:192.168.1.9', 'did not leave over the family it was meant to'],
]);

it('signs in to the names a request can be made to, and records the patterns it skipped', function (): void {
    /*
     * ⚠️ A WILDCARD SORTS BEFORE EVERY LETTER, so `*.kitsune.test` became the hostname the two preflight
     * traces and the whole five-and-a-sixth window ran against — and real curl rejects it outright
     * (`curl: (3) URL rejected: Bad hostname`, checked locally), so both checks voided naming the
     * operator's own nginx as the reason. `*.x`, `.x` and `~^…$` are all legal server_name values, and a
     * wildcard subdomain is part of the planned alpha bring-up: this is a configuration a real run meets.
     */
    throttleDump($this->state, [
        '*.kitsune.test' => ['/home/kitsune/site/current/public'],
        'stage.kitsune.test' => ['/home/kitsune/site/current/public'],
        'stage-fr.kitsune.test' => ['/home/kitsune/site/current/public'],
    ], "    server {\n        listen 443 ssl;\n        server_name .kitsune.test ~^(?<sub>.+)\\.kitsune\\.test\$;\n"
        ."        root /home/kitsune/site/current/public;\n    }\n");

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('across stage-fr.kitsune.test, stage.kitsune.test')
        // ⚠️ AND NOTHING WAS EVER REQUESTED FROM ONE. Asserting only the verdict would pass a family that
        // still signed in to `*.kitsune.test` and merely left it out of the sentence.
        ->and($run->getOutput())->not->toContain('https://*.kitsune.test')
        ->and($run->getOutput())->toContain('RECORD THR-1 the configuration also names *.kitsune.test, .kitsune.test, '
            .'~^(?<sub>.+)\.kitsune\.test$, which no request can be made to');
});

it('asks the store about the login component the page named, not one it knew in advance', function (): void {
    /*
     * ⚠️ THE CLASS IS PART OF THE BUCKET'S NAME. The key is sha1($component.'|'.$method.'|'.ip()) with
     * $component the login page's own class, so a panel with a login page of its own — `->login(App\…)`,
     * routine for branding — or a host on a Filament major where the class was `Filament\Pages\Auth\Login`
     * has a bucket a hardcoded class never names. Every label then read 0 on a host that throttled
     * correctly six times in the same run, and the run reported that the writes went somewhere the
     * instrument did not look.
     *
     * The family already parses memo.name off the login component's own snapshot and refuses any answer
     * that disagrees with it; only the store read ignored it.
     */
    throttleCase($this->state, ['component' => 'App\\Filament\\Auth\\Login']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('PASS')
        ->and($run->getOutput())->not->toContain('this instrument did not look');
});

it('refuses to name one bucket for hostnames whose login pages are different components', function (): void {
    /*
     * The store is read once for every hostname, and two panels with login pages of their own have two
     * different buckets per address. Which one a read belongs to could not be said, so it is unmeasurable
     * rather than a guess — the rule releaseBase() already holds for the release itself.
     */
    throttleCase($this->state, ['components' => ['stage-he.kitsune.test' => 'App\\Filament\\Auth\\Login']]);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('name different login components')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID')
        // Nothing was signed in to: a window against a bucket that could not be named measures nothing.
        ->and(throttleAnswers($this->state))->toBe([]);
});

it('passes a host reached through a forwarding hop on the operator\'s own side of the edge', function (): void {
    /*
     * ⚠️ A HOP IN THE OPERATOR'S OWN PATH IS NOT A FORGERY THIS RUN PLANTED. A corporate egress proxy or a
     * CI runner's outbound proxy puts a second entry in every chain that reaches nginx. The rule "an
     * attempt sent with no forged entry must arrive with no more than one entry" read those as this run's
     * own forgeries — and voided THR-1, whose six attempts were untouched by it, for the two attempts that
     * were THR-2's. What has to hold is that none of the values THIS RUN plants arrived, and that the
     * edge's own entry is still the last one.
     */
    throttleCase($this->state, ['hop' => '198.51.100.200']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeTrue($run->getOutput().$run->getErrorOutput())
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('PASS')
        // ⚠️ AND THE HOP REALLY WAS THERE. Without this the case could pass by not happening: the chain
        // nginx logged carries it on a forged attempt and on an unforged one alike.
        ->and($run->getOutput())->toContain('xff [192.0.2.1, 198.51.100.200, 203.0.113.50]')
        ->and($run->getOutput())->toContain('xff [198.51.100.200, 203.0.113.50]');
});

it('voids the second address, and leaves THR-1 alone, when the address that arrived is not the one the edge named', function (): void {
    /*
     * ⚠️ CLOUDFLARE'S PSEUDO IPv4 IS EXACTLY THIS, and this family's own live-verification list names it as
     * something that must be off without ever measuring it: the edge's trace reports the IPv6 client while
     * the address that reaches the origin is a rewritten one. The unforged branch of the arrival audit
     * never checked what arrived, so the bucket was read for an address nothing had written to and THR-2
     * FAILed that a correctly behaving host had not counted the attempt under itself.
     *
     * ⚠️ AND IT IS THR-2's ATTEMPT, SO IT IS THR-2's VOID. THR-1's own six are untouched and still pass.
     */
    throttleCase($this->state, ['trace-6' => '2001:db8::99']);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('PASS')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-2'))->toContain('as the last forwarded entry')
        ->and(throttleVerdict($run, 'THR-2'))->not->toContain('FAIL')
        ->and($run->getOutput())->not->toContain('was not counted under its own address');
});

it('voids, and never fails, an attempt that left from an address other than the one its bucket is read under', function (array $case, string $check, string $named): void {
    /*
     * ⚠️ A LIMITATION OF THE OPERATOR'S MACHINE, REPORTED AS A FAULT OF THE SERVER — the one thing this
     * family's own doctrine forbids. The labels are fixed before the window from two preflight traces, and
     * curl opens a fresh connection per attempt: a NAT/SNAT pool, a multi-homed or load-balanced egress, a
     * CI runner, or a second global or RFC 4941 temporary IPv6 address gives a later attempt a second
     * public address. The host is sound in both worlds below.
     *
     * Over IPv4 the five-and-a-sixth split across two buckets, the sixth came back unthrottled, the bucket
     * that WAS read still held exactly five — so neither the blind-store VOID nor the exactly-LIMIT VOID
     * fired — and THR-1 FAILed "the login throttle does not hold on this host". Over IPv6 the second
     * address's bucket read 0 and THR-2 FAILed "was not counted under its own address".
     */
    throttleCase($this->state, $case);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, $check))->toContain('VOID')
        ->and(throttleVerdict($run, $check))->toContain($named)
        ->and(throttleVerdict($run, $check))->not->toContain('FAIL')
        // ⚠️ AND NO CHECK MAY BLAME THE HOST FOR IT. Asserting only this check's own verdict would pass a
        // family that moved the accusation to the other one.
        ->and($run->getOutput())->not->toContain('the login throttle does not hold on this host')
        ->and($run->getOutput())->not->toContain('was not counted under its own address');
})->with([
    'this machine takes a second IPv4 address before the sixth attempt' => [
        ['moved_from' => 6, 'moved4' => '203.0.113.77'], 'THR-1', 'attempt 6 left from [203.0.113.77]',
    ],
    'a second IPv6 source address answers for the second-address attempt' => [
        ['moved_from' => 9, 'moved6' => '2001:db8::99'], 'THR-2', 'attempt 9 left from [2001:db8::99]',
    ],
]);

it('voids a forged entry that never arrived at nginx', function (array $case, string $named): void {
    /*
     * ⚠️ WITHOUT THIS, THE CHECK PROVES NOTHING. If the edge strips the forged entry — Cloudflare's
     * "remove visitor IP headers" transform does exactly that — then no attempt ever carried a forwarded
     * header at all, and "the forgery filled no bucket" is a tautology. The probe log is what says the
     * forgery arrived.
     */
    throttleCase($this->state, $case);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($named);
})->with([
    'the edge stripped it' => [['xff' => '203.0.113.50'], 'did not arrive at nginx'],
    'the chain was rewritten' => [['xff' => '198.51.100.9, 203.0.113.50'], 'did not arrive at nginx'],
    'no forwarded header at all' => [['xff' => ''], 'did not arrive at nginx'],
    'the last entry is not what the edge saw' => [['xff' => '192.0.2.1, 198.51.100.9'], 'as the last forwarded entry'],
    'nginx wrote no line for it' => [['probe_missing' => [1]], 'nginx\'s own record of it is missing'],
    'PHP did not answer it' => [['upstream' => ''], 'so PHP did not answer it'],
]);

it('voids everything and installs nothing without the instruments it drives', function (string $instrument): void {
    /*
     * ⚠️ THE PATH IN THE MESSAGE IS THE ASSERTION. `--runbook` declared to getopt with a double colon
     * accepts only `--runbook=value`, and given the space-separated form it returns false — which once made
     * a family fall back to its own directory, check the repository's instrument, and report a pass about
     * the wrong tree. Asserting only "the run failed" would pass the moment anything else voided.
     */
    File::delete($this->dir.'/runbook/host/'.$instrument);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('the instrument is missing')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($this->dir.'/runbook/host/'.$instrument)
        ->and(throttleVerdict($run, 'THR-3'))->toContain('VOID nothing was installed: nothing was sent to the host')
        ->and($run->getOutput())->toContain('SENTINEL throttle 3 THR-1 THR-2 THR-3')
        // Nothing was sent and nothing was asked of the host.
        ->and(is_file($this->state.'/installed'))->toBeFalse()
        ->and(throttleAnswers($this->state))->toBe([]);
})->with([
    'the probe log' => ['probe-log.sh'],
    'the store reader' => ['throttle-store.php'],
    'the shared host helpers' => ['common.sh'],
]);

it('says so plainly on a topology it is not promised for, rather than skipping', function (): void {
    $run = throttleRun($this->dir, $this->family, 'dns-only');

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('the dns-only rows land with the alpha bring-up')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('VOID')
        ->and($run->getOutput())->toContain('SENTINEL throttle 3')
        ->and(is_file($this->state.'/installed'))->toBeFalse();
});

it('fails loudly when the probe log was left behind, and voids when none was installed', function (array $case, string $outcome, string $named): void {
    /*
     * ⚠️ THE WORST OUTCOME THIS FAMILY CAN HAVE. Everything else is a report about the host; this is the
     * runbook having changed a live server and not changed it back, so it is a check of its own.
     */
    throttleCase($this->state, $case);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-3'))->toContain($outcome)
        ->and(throttleVerdict($run, 'THR-3'))->toContain($named);
})->with([
    'the removal failed' => [['stop_fails' => true], 'FAIL', 'THE PROBE LOG WAS NOT REMOVED'],
    'the start refused before installing anything' => [['start_fails' => true], 'VOID', 'nothing of the probe was installed'],
    'the start installed one and then failed' => [
        ['start_installed_then_failed' => "Refusing to check: after 90s of the runbook's own patience the pre-reload workers 3120 were still serving"],
        'PASS',
        'STATE stop removed',
    ],
]);

it('reads the running configuration as nginx writes it, and refuses to guess at the release', function (array $sites, string $extra, string $named): void {
    /*
     * The dump is where the hostnames and the release come from, and the store instrument is booted at that
     * release: a base that cannot be named from the configuration is a run that cannot name a bucket. The
     * `location` block in every fixture server carries a root of its own, which is not the site's.
     */
    throttleDump($this->state, $sites, $extra);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($named)
        ->and(throttleAnswers($this->state))->toBe([]);
})->with([
    'a hostname with no root of its own' => [['stage.kitsune.test' => []], '', 'has no server-level root ending in /public'],
    'two roots for one hostname' => [
        ['stage.kitsune.test' => ['/home/kitsune/site/current/public', '/home/kitsune/other/current/public']],
        '',
        'point at 2 different releases',
    ],
    'hostnames from two different releases' => [
        ['stage.kitsune.test' => ['/home/kitsune/site/current/public'], 'other.kitsune.test' => ['/srv/other/current/public']],
        '',
        'point at 2 different releases',
    ],
    'a server that names only the catch-all' => [[], '', 'no site hostname was found'],
    // A configuration that names a wildcard and nothing else has no site to sign in to, and the reason
    // says which pattern it found rather than claiming the host named nothing at all.
    'a server that names only a wildcard' => [
        ['*.kitsune.test' => ['/home/kitsune/site/current/public']],
        '',
        'it names only *.kitsune.test, which no request can be made to',
    ],
]);

it('voids a dump it could not read, and says what nginx said', function (): void {
    /*
     * ⚠️ THIS IS THE ONE THAT SHIPPED, in tunnel-log. Wrapped in a shell the dump never arrives — nginx
     * writes nothing to stdout and complains that it cannot bind, because the running server holds those
     * listeners — and with stderr discarded that arrived as a successful empty answer, read as "this host
     * serves no site". Asserting only VOID would pass on the defect, because the defect voided too.
     */
    throttleCase($this->state, ['dump_fails' => true]);

    $run = throttleRun($this->dir, $this->family);

    expect(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('still could not bind')
        ->and(throttleVerdict($run, 'THR-1'))->not->toContain('no site hostname')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('PASS');
});

it('voids a store it could not read, or one that belongs to another application', function (array $case, string $named): void {
    throttleCase($this->state, $case);

    $run = throttleRun($this->dir, $this->family);

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain($named)
        ->and(throttleAnswers($this->state))->toBe([]);
})->with([
    'sudo would have prompted' => [['store_fails' => true], 'a password is required'],
    'the release serves another Livewire endpoint' => [['store_prefix' => '/livewire-deadbeef'], 'belongs to another application'],
    'the owner could not be read' => [['owner_fails' => true], 'could not be read'],
    'the release is owned by root' => [['owner' => 'root'], 'will not run application code as root'],
    "Livewire's checksum limiter is already spent" => [['checksum' => ['v4' => 9]], 'checksum-failure limiter already holds'],
]);

it('refuses a verdict for a check it does not declare, and still removes the probe log', function (): void {
    /*
     * ⚠️ WHY verdict() THROWS RATHER THAN EXITS. This refusal fires inside the measuring path, after the
     * probe log is installed; an exit would skip the `finally` that removes it and leave the server changed
     * until the dead-man timer fired. The refusal reaches the stream, the undeclared id is never printed as
     * a verdict, THR-3 still reports the probe removed, and the stream is not closed.
     */
    $source = File::get($this->family);
    $mutation = "verdict('THR-1', \$one[0], \$one[1], \$verdicts);";

    expect(substr_count($source, $mutation))->toBe(1);

    $mutant = $this->dir.'/throttle-undeclared.php';
    File::put($mutant, str_replace($mutation, "verdict('THR-9', \$one[0], \$one[1], \$verdicts);", $source));
    File::copy(dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php', $this->dir.'/lib.php');

    $run = throttleRun($this->dir, $mutant);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('this family did not declare that id')
        ->and($run->getOutput())->toContain('REFUSED throttle verdict THR-9 ')
        ->and($run->getOutput())->not->toContain('VERDICT THR-9')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('PASS STATE stop removed')
        ->and($run->getOutput())->not->toContain('SENTINEL');
});

it('refuses an --egress-trace it was handed directly that is not an https URL', function (string $value): void {
    /*
     * ⚠️ run.sh REFUSES THIS FIRST, AND THE FAMILY STILL HAS TO. A family is runnable on its own — these
     * tests run it that way, and so does an operator narrowing a failure down — and what that endpoint
     * answers decides which address a check believes is its own. A plaintext or malformed one would let
     * anything on the path choose it, so nothing is installed and nothing is asked of the host.
     */
    $run = new Process(
        [
            'php', $this->family, '--host', 'forge@fixture', '--expect', 'tunnel',
            '--runbook', $this->dir.'/runbook', '--egress-trace', $value,
        ],
        $this->dir,
        [
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => $this->dir.'/tmp',
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
        ],
    );
    $run->setTimeout(60);
    $run->run();

    expect($run->isSuccessful())->toBeFalse()
        ->and(throttleVerdict($run, 'THR-1'))->toContain('VOID')
        ->and(throttleVerdict($run, 'THR-1'))->toContain('is not an https URL')
        ->and(throttleVerdict($run, 'THR-3'))->toContain('nothing was installed')
        ->and($run->getOutput())->toContain('SENTINEL throttle 3 THR-1 THR-2 THR-3')
        ->and(is_file($this->state.'/installed'))->toBeFalse()
        ->and(throttleAnswers($this->state))->toBe([]);
})->with([
    'plaintext' => ['http://trace.example/cdn-cgi/trace'],
    'no scheme at all' => ['trace.example/cdn-cgi/trace'],
    'a shell word' => ['; rm -rf /'],
]);
