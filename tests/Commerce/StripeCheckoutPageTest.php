<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Kitsune\Commerce\Http\Middleware\OrderResponse;
use Kitsune\Commerce\Payments\CheckoutMessages;
use Symfony\Component\Process\Process;

/** Actual HTTP kernel and level-zero SQLite; the concrete Stripe transport receives only fixture responses. */
function stripeCheckoutPageProbe(string $scenario, bool $cached = false): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-stripe-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    try {
        foreach ($cached ? ['seed', 'cache', 'request'] : ['seed', 'request'] as $step) {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/StripeCheckoutPageProbe.php', $root, $temporary, $step, $scenario], $root);
            $process->setTimeout(60);
            $process->mustRun();
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('reviews starts and reconciles a test Session through native root and nested cached routes and retains completed orders until captured-payment proof', function (string $scenario, bool $cached): void {
    $result = stripeCheckoutPageProbe($scenario, $cached);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 303, 200, 200, 303, 200, 200, 422, 422, 303, 200, 200, in_array($scenario, ['expire', 'owner-expire'], true) ? 303 : 422, in_array($scenario, ['expire', 'owner-expire'], true) ? 303 : 422])
        ->and($result['cached'])->toBe($cached)->and($result['getReadOnly'])->toBeTrue()
        ->and($result['responses'][5]['body'])->toContain('Total: ', 'USD 24.00', 'about 30 minutes', 'Surf &amp; &lt;course&gt;')
        ->and($result['responses'][6]['headers']['location'][0])->toStartWith('https://checkout.stripe.com/')
        ->and($result['responses'][6]['headers']['content-security-policy'][0])->toContain("form-action 'self' https://checkout.stripe.com")
        ->and($result['responses'][12]['body'])->toContain(in_array($scenario, ['expire', 'owner-expire'], true) ? 'Checkout expired' : 'Checkout completed')
        ->and($result['orders'])->toBe(in_array($scenario, ['expire', 'owner-expire'], true) ? 0 : 1)->and($result['attempts'])->toBe(in_array($scenario, ['expire', 'owner-expire'], true) ? 0 : 1)->and($result['stock'])->toBe('5')->and($result['grants'])->toBe(0)
        ->and($result['level'])->toBe(0)->and($result['pdo'])->toBeFalse();
    foreach ($result['calls'] as $call) {
        expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse();
    }
    foreach (array_slice($result['responses'], 4) as $response) {
        expect($response['query'])->toBe([])->and($response['input'])->toBe([])->and($response['rawBody'])->toBe('')
            ->and($response['oldInput'])->toBeNull()->and($response['previous'] ?? '')->not->toContain('private-query');
        expect($response['body'])->not->toContain('private-provider-fragment', 'private-forged-session', 'private-query-token');
    }
    expect(json_encode($result['payload'], JSON_THROW_ON_ERROR))->not->toContain('private-native-checkout', 'Private Checkout Buyer', '42 Private Checkout Lane', 'customer_email')
        ->and($result['log'])->not->toContain('private-query', 'Private Checkout Buyer', '42 Private Checkout Lane');
    if ($scenario === 'owner-expire') {
        expect($result['ownerRecovered'])->toBeTrue();
    }
})->with(['plain' => ['flow', false], 'cached nested' => ['nested', true], 'cached root' => ['root', true],
    'explicit expiry' => ['expire', false], 'owner recovery of lost Session ID' => ['owner-expire', false]]);

it('requires a native body CSRF token for every payment mutation before provider I O', function (): void {
    $result = stripeCheckoutPageProbe('csrf');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 303, 200, 419, 419, 419, 419])
        ->and($result['calls'])->toBe([])->and($result['attempts'])->toBe(0);
});

it('refuses sibling site and malformed direct payment references without leaking order details', function (): void {
    $result = stripeCheckoutPageProbe('scope', true);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 303, 200, 404, 404, 404])
        ->and($result['calls'])->toBe([])->and($result['attempts'])->toBe(0);
    foreach (array_slice($result['responses'], 5) as $response) {
        expect($response['body'])->not->toContain('Private Checkout Buyer', '42 Private Checkout Lane', 'Surf');
    }
});

it('scrubs alternate native URLs and every request bag while preserving a mounted front controller', function (): void {
    $globals = [$_GET, $_POST, $_REQUEST, $_SERVER];
    $request = Request::create('https://checkout.test/mount/index.php/brand/cart/orders/'.str_repeat('a', 32).'/payment/start?review=private-url-review&page[]=private-array-page&token[]=private-array-token', 'POST',
        ['review' => str_repeat('b', 64), 'attempt' => str_repeat('c', 32), 'unknown' => 'private-unknown-body'], [], [],
        ['SCRIPT_NAME' => '/mount/index.php', 'SCRIPT_FILENAME' => '/var/www/mount/index.php', 'PHP_SELF' => '/mount/index.php/brand/cart/orders/'.str_repeat('a', 32).'/payment/start',
            'UNENCODED_URL' => '/mount/index.php?review=private-alias', 'HTTP_X_ORIGINAL_URL' => '/mount/index.php?review=private-alias']);
    $request->setLaravelSession(app('session.store'));
    $_GET = ['review' => 'private-url-review'];
    $_POST = ['unknown' => 'private-unknown-body'];
    $_REQUEST = [...$_GET, ...$_POST];
    $_SERVER['UNENCODED_URL'] = 'private-alias';
    $_SERVER['HTTP_X_ORIGINAL_URL'] = 'private-alias';
    try {
        app(OrderResponse::class)->handle($request, static fn () => response('Safe fixture'));
        expect($request->getBaseUrl())->toBe('/mount/index.php')
            ->and($request->getRequestUri())->toBe('/mount/index.php/brand/cart/orders/'.str_repeat('a', 32).'/payment/start')
            ->and($request->query->all())->toBe([])->and($request->request->all())->toBe([])->and($request->getContent())->toBe('')
            ->and($request->server->has('UNENCODED_URL'))->toBeFalse()->and($request->server->has('HTTP_X_ORIGINAL_URL'))->toBeFalse()
            ->and($_GET)->toBe([])->and($_POST)->toBe([])->and($_REQUEST)->toBe([])
            ->and(isset($_SERVER['UNENCODED_URL']))->toBeFalse()->and(isset($_SERVER['HTTP_X_ORIGINAL_URL']))->toBeFalse();
    } finally {
        [$_GET, $_POST, $_REQUEST, $_SERVER] = $globals;
    }
});

it('maps only bounded workflow reasons into buyer and operator copy', function (string $reason, string $key): void {
    expect(CheckoutMessages::key($reason))->toBe($key);
})->with([
    ['zero', 'zero'], ['live', 'live'], ['currency', 'currency'], ['changed', 'changed'], ['stale', 'changed'],
    ['expired', 'review_expired'], ['busy', 'busy'], ['operator', 'operator'], ['setup', 'setup'], ['limit', 'limit'],
    ['private-provider-body-and-key', 'unavailable'],
]);
