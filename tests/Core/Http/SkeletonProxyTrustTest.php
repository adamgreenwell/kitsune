<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * What the published skeleton believes about the client address, scheme and host behind a proxy (ADR-034).
 *
 * ⚠️ THIS DRIVES THE REAL skeleton/bootstrap/app.php, NOT A COPY OF ITS CALL. A test repeating trustProxies(...) in
 * Testbench would stay green after the skeleton's line was deleted; a test searching the file for the call would pass
 * on a call that trusts nothing (on a web request, env() in that closure is null). A child process, because requiring
 * the bootstrap constructs a second Application and sets TrustProxies statics.
 *
 * The child resolves the HTTP kernel, which is the moment withMiddleware's closure runs on a real request, and binds
 * an EMPTY config, so the policy cannot be coming from configuration. LARAVEL_CLOUD is removed from the child's
 * environment unless a case sets it, so the runner's environment cannot decide the result.
 *
 * ⚠️ EVERY REQUEST IS A PATH WITH HTTP_HOST AND HTTPS IN THE SERVER ARRAY. Request::create() overwrites both from a
 * URI that carries a scheme and host, which would make the web server's HTTPS — what nginx passes through
 * fastcgi_params — a value no case actually controls.
 */
$probe = static function (array $cases, array $env = []): array {
    $child = <<<'PHP'
        [, $root] = $argv;
        require $root.'/vendor/autoload.php';
        $app = require $root.'/skeleton/bootstrap/app.php';
        $app->instance('config', new Illuminate\Config\Repository([]));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $seen = ['global' => in_array(Illuminate\Http\Middleware\TrustProxies::class, $kernel->getGlobalMiddleware(), true)];
        foreach (json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $name => [$uri, $server]) {
            $request = Illuminate\Http\Request::create($uri, 'GET', [], [], [], $server);
            (new Illuminate\Http\Middleware\TrustProxies)->handle($request, function ($resolved) use (&$seen, $name) {
                $seen[$name] = ['ip' => $resolved->ip(), 'secure' => $resolved->isSecure(), 'host' => $resolved->getHost(), 'root' => $resolved->root()];

                return new Illuminate\Http\Response;
            });
        }
        echo json_encode($seen, JSON_THROW_ON_ERROR);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $child, '--', dirname(__DIR__, 3)], env: $env + ['LARAVEL_CLOUD' => false]);
    $process->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
};

it('reads the visitor from the address the edge appended, past trusted hops, never a client-written entry', function () use ($probe): void {
    /*
     * Without the trustProxies call: 127.0.0.1 and ::1, so every stage visitor shared Filament's login throttle.
     * 'v4': the client wrote 6.6.6.6 to the left of the address Cloudflare appended. 'v6': a second loopback hop to the
     * right of it is skipped, so ::1 and 127.0.0.1 must both be trusted.
     */
    $seen = $probe([
        'v4' => ['/admin/login', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9']],
        'v6' => ['/', ['REMOTE_ADDR' => '::1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 127.0.0.1']],
    ]);

    expect($seen['global'])->toBeTrue()
        ->and($seen['v4']['ip'])->toBe('203.0.113.9')
        ->and($seen['v6']['ip'])->toBe('203.0.113.9');
});

it('takes the scheme from the web server, never from X-Forwarded-Proto, even from the trusted proxy', function () use ($probe): void {
    /*
     * ⚠️ This fails if the mask gains HEADER_X_FORWARDED_PROTO. On every supported hostname TLS terminates in the web
     * server, so HTTPS already says what the scheme is; a trusted X-Forwarded-Proto would only let a local process make
     * a secure request insecure ('downgrade'), or let a plain-HTTP hop claim to be secure ('upgrade').
     */
    $seen = $probe([
        'downgrade' => ['/', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'http']],
        'upgrade' => ['/', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']],
    ]);

    expect($seen['downgrade']['secure'])->toBeTrue()
        ->and($seen['downgrade']['root'])->toBe('https://stage.kitsunecms.org')
        ->and($seen['upgrade']['secure'])->toBeFalse()
        ->and($seen['upgrade']['root'])->toBe('http://stage.kitsunecms.org');
});

it('never takes the host, port or prefix from a forwarding header, even from the trusted proxy', function () use ($probe): void {
    /*
     * ⚠️ ip fails without the call. host and root fail on the plausible wrong fixes: loopback with Laravel's default
     * mask ('forged'), or with the RFC 7239 Forwarded header added to the mask ('rfc7239'). The two are separate
     * requests, so a Forwarded value never conflicts with a trusted X-Forwarded-Host.
     */
    $seen = $probe([
        'forged' => ['/', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_HOST' => 'stage-fr.kitsunecms.org', 'HTTP_X_FORWARDED_PORT' => '8443', 'HTTP_X_FORWARDED_PREFIX' => '/evil']],
        'rfc7239' => ['/', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_FORWARDED' => 'host=stage-fr.kitsunecms.org']],
    ]);

    expect($seen['forged']['ip'])->toBe('203.0.113.9')
        ->and($seen['forged']['host'])->toBe('stage.kitsunecms.org')
        ->and($seen['forged']['root'])->toBe('https://stage.kitsunecms.org')
        ->and($seen['rfc7239']['host'])->toBe('stage.kitsunecms.org')
        ->and($seen['rfc7239']['root'])->toBe('https://stage.kitsunecms.org');
});

it('trusts no other peer, including on a Forge vanity hostname', function () use ($probe): void {
    /*
     * ⚠️ WITHOUT THE CALL THIS FAILED ON 'vanity': ip() was the client's 6.6.6.6 and the root http://alpha-x.on-forge.com/evil.
     * 'mapped' pins that the list is literal: a dual-stack socket (ipv6only=off) presents ::ffff:127.0.0.1, untrusted.
     */
    $seen = $probe([
        'vanity' => ['/admin/login', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'alpha-x.on-forge.com', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_PREFIX' => '/evil']],
        'direct' => ['/', ['REMOTE_ADDR' => '192.168.1.50', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_HOST' => 'evil.example']],
        'mapped' => ['/', ['REMOTE_ADDR' => '::ffff:127.0.0.1', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']],
    ]);

    expect($seen['vanity']['ip'])->toBe('198.51.100.7')
        ->and($seen['vanity']['secure'])->toBeTrue()
        ->and($seen['vanity']['root'])->toBe('https://alpha-x.on-forge.com')
        ->and($seen['direct']['ip'])->toBe('192.168.1.50')
        ->and($seen['direct']['host'])->toBe('stage.kitsunecms.org')
        ->and($seen['mapped']['ip'])->toBe('::ffff:127.0.0.1');
});

it('does not switch trust-everything on when LARAVEL_CLOUD is set', function () use ($probe): void {
    // ⚠️ Pins ADR-034's stated cost: without the call, this peer's forged headers won (ip 6.6.6.6, host evil.example, http).
    $seen = $probe([
        'direct' => ['/', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'stage.kitsunecms.org', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_HOST' => 'evil.example']],
    ], ['LARAVEL_CLOUD' => '1']);

    expect($seen['direct'])->toBe(['ip' => '198.51.100.7', 'secure' => true, 'host' => 'stage.kitsunecms.org', 'root' => 'https://stage.kitsunecms.org']);
});
