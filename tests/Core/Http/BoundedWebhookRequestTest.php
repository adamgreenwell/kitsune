<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Kitsune\Core\Http\BoundedWebhookRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

$base = static fn (string $path, string $body = '{}', array $server = []): SymfonyRequest => SymfonyRequest::create(
    $path, 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=fixture'], $body,
);
$capture = static fn (SymfonyRequest $request): Request|Response => BoundedWebhookRequest::fromBase($request, '/cart/stripe/webhook', 'Stripe-Signature');

it('preserves exact bytes and prevents capture or global transforms from decoding before signature verification', function () use ($base, $capture): void {
    $body = "{ \"name\": \"  surfin\\u0020bird  \", \"nothing\": \"\", \"many\": [null, 1] }\n";
    $source = $base('/cart/stripe/webhook', $body);
    $request = $capture($source);

    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->headers->get('Content-Type'))->toBe('application/json')
        ->and($source->headers->get('Content-Type'))->toBe('application/json')
        ->and($request->headers->get('Stripe-Signature'))->toBe('t=1,v1=fixture')
        ->and($request->getContent())->toBe($body)
        ->and($request->getContent())->toBe($body)
        ->and($request->json()->all())->toBe([])
        ->and($request->request->all())->toBe([])
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();

    (new TrimStrings)->handle($request, static fn () => new Response);
    (new ConvertEmptyStringsToNull)->handle($request, static fn () => new Response);
    expect($request->json()->all())->toBe([])
        ->and($request->getContent())->toBe($body)
        ->and(hash_hmac('sha256', '1.'.$request->getContent(), 'whsec_fixture'))->toBe(hash_hmac('sha256', '1.'.$body, 'whsec_fixture'))
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();
});

it('leaves malformed and deeply nested JSON interpretation to the authenticated verifier', function (string $body) use ($base, $capture): void {
    $request = $capture($base('/cart/stripe/webhook', $body));
    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->getContent())->toBe($body)
        ->and($request->json()->all())->toBe([])
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();
})->with(['malformed' => ['{not-json'], 'deep' => [str_repeat('[', 2000).'0'.str_repeat(']', 2000)]]);

it('bounds every routed spelling including nested prefixes and front-controller mounts', function (string $path) use ($base, $capture): void {
    $request = $capture($base($path));
    expect($request)->toBeInstanceOf(Request::class)
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();
})->with([
    '/cart/stripe/webhook', '/news/fr/cart/stripe/webhook', '/index.php/news/fr/cart/stripe/webhook',
    '/cart/stripe/webhook/', '/cart/%73tripe/webhook', '/news%2Ffr/cart/stripe/webhook', '/cart/stripe/webhook?ignored=1',
]);

it('bounds the request URI selected by IIS just as the router does', function () use ($base, $capture): void {
    $request = $capture($base('/other', '{}', [
        'IIS_WasUrlRewritten' => '1', 'UNENCODED_URL' => '/news/cart/stripe/webhook?ignored=1',
    ]));
    expect($request)->toBeInstanceOf(Request::class)
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();
});

it('cannot bypass the actual body limit by omitting or understating a length or signature', function (?string $length, ?string $signature) use ($base, $capture): void {
    $source = $base('/cart/stripe/webhook', str_repeat('x', BoundedWebhookRequest::MAX_BODY_BYTES + 1));
    $length === null ? $source->headers->remove('Content-Length') : $source->headers->set('Content-Length', $length);
    $signature === null ? $source->headers->remove('Stripe-Signature') : $source->headers->set('Stripe-Signature', $signature);
    $response = $capture($source);
    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(413)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'missing both' => [null, null], 'understated' => ['1', 't=1,v1=fixture'], 'zero' => ['0', null],
    'declared' => [(string) (BoundedWebhookRequest::MAX_BODY_BYTES + 1), 't=1,v1=fixture'],
]);

it('admits the exact byte boundary without a second stream read', function () use ($base, $capture): void {
    $body = str_repeat('x', BoundedWebhookRequest::MAX_BODY_BYTES);
    $request = $capture($base('/cart/stripe/webhook', $body, ['CONTENT_LENGTH' => (string) strlen($body)]));
    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->getContent())->toBe($body)
        ->and($request->getContent())->toBe($body)
        ->and(BoundedWebhookRequest::isBounded($request))->toBeTrue();
});

it('refuses malformed content lengths without opening an untrusted stream', function (string $length) use ($base, $capture): void {
    $response = $capture($base('/cart/stripe/webhook', '{}', ['CONTENT_LENGTH' => $length]));
    expect($response)->toBeInstanceOf(Response::class)->and($response->getStatusCode())->toBe(400);
})->with(['-1', '+2', '01', '2.0', '2e1', '10000000000', '2, 2']);

it('bounds signature header bytes and components independently of authentication', function (string $header, bool $allowed) use ($base, $capture): void {
    $response = $capture($base('/cart/stripe/webhook', '{}', ['HTTP_STRIPE_SIGNATURE' => $header]));
    expect($response)->toBeInstanceOf($allowed ? Request::class : Response::class);
    if (! $allowed) {
        expect($response->getStatusCode())->toBe(400);
    }
})->with([
    'exact bytes' => [str_repeat('a', 4096), true], 'too many bytes' => [str_repeat('a', 4097), false],
    'exact parts' => [implode(',', array_fill(0, 32, 'v1=fixture')), true],
    'too many parts' => [implode(',', array_fill(0, 33, 'v1=fixture')), false],
]);

it('refuses ambiguous duplicated signature headers', function () use ($base, $capture): void {
    $source = $base('/cart/stripe/webhook');
    $source->headers->set('Stripe-Signature', ['t=1,v1=fixture', 't=2,v1=other']);
    $response = $capture($source);
    expect($response)->toBeInstanceOf(Response::class)->and($response->getStatusCode())->toBe(400);
});

it('guards every method so method overrides cannot evade the reserved leaf budget', function (string $method) use ($base, $capture): void {
    $source = $base('/cart/stripe/webhook', str_repeat('x', BoundedWebhookRequest::MAX_BODY_BYTES + 1));
    $source->setMethod($method);
    $source->headers->set('X-HTTP-Method-Override', 'POST');
    $response = $capture($source);
    expect($response)->toBeInstanceOf(Response::class)->and($response->getStatusCode())->toBe(413);
})->with(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('does not commandeer other callback paths or ordinary JSON capture', function (string $path) use ($base, $capture): void {
    $request = $capture($base($path, '{"name":"ordinary"}', ['HTTP_STRIPE_SIGNATURE' => str_repeat('x', 5000)]));
    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->json()->all())->toBe(['name' => 'ordinary'])
        ->and(BoundedWebhookRequest::isBounded($request))->toBeFalse();
})->with(['/another-stripe-callback', '/cart/stripe/webhook/extra', '/cart/stripe/webhook-extra', '/cart/%2573tripe/webhook']);

it('does not grant capture proof to an ordinary request or a changed raw body', function () use ($base, $capture): void {
    $plain = Request::create('/cart/stripe/webhook', 'POST', ['kitsune.webhook.bounded_body' => hash('sha256', '{}')], content: '{}');
    expect(BoundedWebhookRequest::isBounded($plain))->toBeFalse();
    $request = $capture($base('/cart/stripe/webhook'));
    $request->initialize([], [], $request->attributes->all(), [], [], $request->server->all(), '{"changed":true}');
    expect(BoundedWebhookRequest::isBounded($request))->toBeFalse();
});

it('drives the actual skeleton front controller and refuses before its application or method body parser bootstraps', function (string $method): void {
    $child = <<<'PHP'
        [, $directory, $method] = $argv;
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/news/fr/cart/stripe/webhook',
            'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $directory.'/public/index.php',
            'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '262145', 'HTTP_HOST' => 'localhost'];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        register_shutdown_function(static function (): void {
            fwrite(STDERR, json_encode(['status' => http_response_code(),
                'application' => Illuminate\Container\Container::getInstance() instanceof Illuminate\Foundation\Application], JSON_THROW_ON_ERROR));
        });
        require $directory.'/public/index.php';
        PHP;
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir().'/kitsune-webhook-front-'.bin2hex(random_bytes(8));
    try {
        mkdir($directory.'/public', 0700, true);
        mkdir($directory.'/vendor', 0700);
        // The real entrypoint, byte for byte, using root's bare-clone dependencies. No installed skeleton is needed.
        copy($root.'/skeleton/public/index.php', $directory.'/public/index.php');
        file_put_contents($directory.'/vendor/autoload.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).';');
        $process = new Process([PHP_BINARY, '-r', $child, '--', $directory, $method]);
        $process->mustRun();
        expect($process->getOutput())->toBe('Payload too large')
            ->and(json_decode($process->getErrorOutput(), true, 8, JSON_THROW_ON_ERROR))->toBe(['status' => 413, 'application' => false]);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY']);
