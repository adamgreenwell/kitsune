<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Native optional-module HTTP, real encrypted cookies and route caching, on an isolated SQLite database. @return array<string,mixed> */
function cartPageProbe(string $scenario, bool $cached = false): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-cart-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);

    try {
        foreach ($cached ? ['seed', 'cache', 'request'] : ['seed', 'request'] as $step) {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/CartPageProbe.php', $root, $temporary, $step, $scenario], $root);
            $process->setTimeout(60);
            $process->mustRun();
        }

        try {
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The cart HTTP probe failed: '.substr($process->getOutput().$process->getErrorOutput(), -8000), previous: $failure);
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

/** Every served page, redirect, input refusal and outage uses the same private boundary. */
function cartPageHeaders(array $response): void
{
    foreach (['cache-control' => 'no-store, private', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff'] as $name => $value) {
        expect($response['headers'][$name] ?? null)->toBe([$value], $name);
    }

    expect($response['headers']['content-security-policy'][0] ?? '')->toContain("frame-ancestors 'none'", "form-action 'self'");
}

function cartLastResponse(array $result): array
{
    return $result['responses'][array_key_last($result['responses'])];
}

it('recovers a 30-day guest selection through its encrypted cookie after the ordinary auth session has gone', function (string $scenario, bool $secure, string $path, ?string $domain): void {
    $result = cartPageProbe($scenario);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200])
        ->and(cartLastResponse($result)['body'])->toContain('Surf course', 'USD 40.00', 'value="2"')
        ->and($result['lines'])->toHaveCount(1)
        ->and($result['evidence']['cookieEncrypted'])->toBeTrue()
        ->and($result['evidence']['sessionLifetime'])->toBe(120)
        ->and($result['evidence']['expiryAfter'])->toBe($result['evidence']['expiryBefore']);
    $cookies = array_values(array_filter($result['responses'][1]['cookies'], fn (array $cookie): bool => $cookie['name'] === $result['rememberCookieName']));
    expect($cookies)->toHaveCount(1);
    $cookie = $cookies[0];
    expect($cookie['httpOnly'])->toBeTrue()->and($cookie['sameSite'])->toBe('lax')
        ->and($cookie['secure'])->toBe($secure)->and($cookie['path'])->toBe($path)->and($cookie['domain'])->toBe($domain)
        ->and($cookie['expires'])->toBeGreaterThanOrEqual(time() + 30 * 86400 - 90)
        ->and($cookie['expires'])->toBeLessThanOrEqual(time() + 30 * 86400 + 90);
    cartPageHeaders(cartLastResponse($result));
})->with([
    'HTTP host defaults' => ['cookie-recovery', false, '/', null],
    'HTTPS host' => ['cookie-https', true, '/', null],
    'explicit secure path and domain' => ['cookie-configured', true, '/brand', 'localhost'],
]);

it('does not recover a guest cart without an authentic unexpired browser handle or after database expiry', function (string $scenario): void {
    $result = cartPageProbe($scenario);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200])
        ->and(cartLastResponse($result)['body'])->toContain('Your cart is empty')
        ->and(cartLastResponse($result)['body'])->not->toContain('Surf course', 'USD 40.00')
        ->and($result['lines'])->toHaveCount(1)
        ->and($result['evidence']['expiryAfter'])->toBe($result['evidence']['expiryBefore']);
})->with(['missing cookie' => ['cookie-missing'], 'forged plaintext seed' => ['cookie-forged'], 'expired persisted cart' => ['cookie-expired-cart']]);

it('retains both simultaneous first POST selections on the same CSRF session without pre-seeding a cart handle', function (): void {
    $result = cartPageProbe('first-concurrent');
    expect($result['evidence']['postStatuses'])->toBe([303, 303])
        ->and($result['evidence']['sameSession'])->toBeTrue()
        ->and($result['evidence']['guestStateAbsent'])->toBe([true, true])
        ->and($result['evidence']['rememberCookieAbsent'])->toBe([true, true])
        ->and($result['evidence']['cartCount'])->toBe(1)
        ->and($result['lines'])->toHaveCount(2)
        ->and(array_column($result['lines'], 'quantity'))->toBe([1, 1])
        ->and(cartLastResponse($result)['body'])->toContain('Surf course', 'Board guide', 'USD 32.50');
});

it('adds, replays, updates and removes a guest selection through body-CSRF forms and fixed redirects', function (bool $cached): void {
    $result = cartPageProbe('guest-flow', $cached);
    $responses = $result['responses'];
    expect($result['cached'])->toBe($cached)
        ->and(array_column($responses, 'status'))->toBe([200, 200, 303, 200, 303, 200, 303, 200, 303, 200])
        ->and($responses[0]['body'])->toContain('Your cart is empty')
        ->and($responses[1]['body'])->toContain('Add to cart', 'action="/brand/cart/add"')
        ->and($responses[3]['body'])->toContain('Surf course', 'USD 40.00', 'value="2"')
        ->and($responses[5]['body'])->toContain('USD 40.00', 'value="2"')
        ->and($responses[7]['body'])->toContain('USD 60.00', 'value="3"')
        ->and($responses[9]['body'])->toContain('Your cart is empty')
        ->and($result['lines'])->toBe([]);

    foreach ($responses as $response) {
        cartPageHeaders($response);
        expect($response['oldInput'])->toBeNull();
        expect($response['body'])->not->toContain('Checkout', 'Buy now', 'cart-private-reader', 'owner_key', 'reader_id');

        if ($response['status'] === 303) {
            expect($response['headers']['location'][0] ?? '')->toBe('/brand/cart');
        }
    }
})->with(['uncached' => [false], 'real route cache' => [true]]);

it('requires the body CSRF token even with a valid header, same-origin metadata and a token in the address', function (string $scenario): void {
    $result = cartPageProbe($scenario);
    expect(array_column($result['responses'], 'status'))->toBe([200, 419, 419, 419])->and($result['lines'])->toBe([]);

    foreach (array_slice($result['responses'], 1) as $response) {
        expect($response['body'])->toContain('Your session expired')->and($response['body'])->not->toContain('wrong-token')
            ->and($response['query'])->toBe([])->and($response['input'])->toBe([])->and($response['rawBody'])->toBe('')
            ->and($response['oldInput'])->toBeNull();
        cartPageHeaders($response);
    }
})->with(['framework testing mode' => ['csrf'], 'actual host middleware' => ['csrf-host']]);

it('refuses malformed quantities without echoing or flashing any submitted body', function (): void {
    $result = cartPageProbe('invalid');
    expect(array_column($result['responses'], 'status'))->toBe([200, 422, 422, 422, 422, 422, 422])->and($result['lines'])->toBe([]);

    foreach (array_slice($result['responses'], 1) as $response) {
        expect($response['body'])->toContain('Use a whole quantity from 1 to 99')->and($response['body'])->not->toContain('private-body-secret')
            ->and($response['input'])->toBe([])->and($response['rawBody'])->toBe('')->and($response['oldInput'])->toBeNull();
        cartPageHeaders($response);
    }
});

it('takes listing and quantity from the body alone and removes unknown query and body data from the request', function (): void {
    $result = cartPageProbe('query-input');
    expect(array_column($result['responses'], 'status'))->toBe([200, 200, 422])->and($result['lines'])->toBe([])
        ->and($result['responses'][1]['previous'])->toBe('http://localhost/brand/cart');

    foreach ($result['responses'] as $response) {
        expect($response['query'])->toBe([])->and($response['input'])->toBe([])->and($response['rawBody'])->toBe('')
            ->and($response['oldInput'])->toBeNull()->and($response['body'])->not->toContain('private-query-secret', 'private-body-secret', 'cart-private-reader', 'private-cart-id');
    }
});

it('keeps sibling-site carts separate and refuses adding the first site listing through the sibling address', function (): void {
    $result = cartPageProbe('cross-site');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 422, 200])
        ->and($result['responses'][2]['body'])->toContain('Your cart is empty')
        ->and($result['responses'][4]['body'])->toContain('Surf course', 'USD 40.00')
        ->and($result['lines'])->toHaveCount(1);
});

it('returns the latest cart with a 409 when an old tab tries to update or remove a changed line', function (): void {
    $result = cartPageProbe('stale');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 303, 409, 409, 200])
        ->and($result['responses'][3]['body'])->toContain('Your cart changed in another tab', 'value="3"')
        ->and($result['lines'][0]['quantity'])->toBe(3);
    cartPageHeaders($result['responses'][3]);
    cartPageHeaders($result['responses'][4]);
});

it('keeps an unavailable line removable after its product type is disabled, without showing a stale price', function (): void {
    $result = cartPageProbe('unavailable');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 422, 303, 200])
        ->and($result['responses'][2]['body'])->toContain('Unavailable product', 'Remove Unavailable product')
        ->and($result['responses'][2]['body'])->not->toContain('USD 20.00', 'USD 40.00', 'Surf course', 'Update quantity')
        ->and($result['responses'][5]['body'])->toContain('Your cart is empty')->and($result['lines'])->toBe([]);
});

it('never mutates through GET requests to mutation-shaped addresses, even when the host catch-all serves them', function (): void {
    $result = cartPageProbe('get-mutations');
    expect($result['lines'])->toBe([]);
});

it('honours canonical site roots, deep prefixes, ordinary routes and panel URL space with cached routes', function (string $scenario, array $statuses): void {
    $result = cartPageProbe($scenario, true);
    expect(array_column($result['responses'], 'status'))->toBe($statuses);
})->with([
    'host root' => ['root-host', [200, 404]],
    'four-segment site' => ['deep-prefix', [200]],
    'ordinary and panel routes' => ['host-route', [200, 200, 302]],
    'custom panel path' => ['panel-path', [404]],
    'panel owns root' => ['panel-root', [404]],
]);

it('keeps catalogue and cart placement independent in a real route cache', function (string $scenario, string $absent): void {
    $result = cartPageProbe($scenario, true);
    $last = cartLastResponse($result);
    expect($last['status'])->toBe(200)->and($last['body'])->not->toContain($absent);
})->with([
    'catalogue only' => ['catalogue-only', 'Add to cart'],
    'cart only' => ['cart-only', 'Continue browsing'],
]);

it('refuses cached cart routes when the optional runtime is disabled, removed or stale', function (string $scenario): void {
    $result = cartPageProbe($scenario, true);
    $response = cartLastResponse($result);
    expect($response['status'])->toBe(404)->and($response['body'])->not->toContain('Surf course', 'Your cart is empty');
    cartPageHeaders($response);
})->with(['disabled after boot' => ['disabled'], 'disabled before boot' => ['disabled-at-boot'], 'missing before boot' => ['missing-at-boot'], 'stale before boot' => ['stale-at-boot']]);

it('renders a generic private 503 when cart or setup database reads fail, without secrets in logs or output', function (string $scenario): void {
    $result = cartPageProbe($scenario);
    $response = cartLastResponse($result);
    expect($response['status'])->toBe(503)->and($response['body'])->toContain('Cart unavailable', 'Please try again shortly')
        ->and($response['body'])->not->toContain('Surf course', 'cart-private-reader', 'SQLSTATE', 'select ', 'owner_key')
        ->and($response['query'])->toBe([])->and($result['logs'])->toContain('The public cart could not be read or changed.', 'sqlstate')
        ->and($result['logs'])->not->toContain('cart-private-reader', 'owner_key', 'select ');
    cartPageHeaders($response);
})->with(['cart table' => ['cart-outage'], 'site resolver' => ['site-outage'], 'module receipt' => ['module-outage'], 'account-mode settings' => ['settings-outage']]);

it('keeps the Arabic site language on product content and English fallback copy on cart controls', function (): void {
    $response = cartLastResponse(cartPageProbe('arabic'));
    expect($response['status'])->toBe(200)->and($response['body'])->toContain('<html lang="ar" dir="rtl">', '<main id="cart" lang="en" dir="ltr">', '<h2 lang="ar" dir="auto">Surf course</h2>');
});

it('fails safely on a corrupted retained child without erasing it or rendering a partial cart', function (): void {
    $result = cartPageProbe('corrupt-child');
    $response = cartLastResponse($result);
    expect($response['status'])->toBe(503)->and($response['body'])->toContain('Cart unavailable')
        ->and($response['body'])->not->toContain('Surf course', 'USD 40.00')->and($result['lines'])->toHaveCount(1);
    cartPageHeaders($response);
});

it('renders an expired-token notice without querying broken account settings', function (): void {
    $response = cartLastResponse(cartPageProbe('settings-expired-token'));
    expect($response['status'])->toBe(419)->and($response['body'])->toContain('Your session expired')
        ->and($response['body'])->not->toContain('cart-private-reader', 'SQLSTATE');
    cartPageHeaders($response);
});
