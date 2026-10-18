<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Native HTTP and encrypted browser sessions on disposable SQLite; never provider traffic. @return array<string,mixed> */
function orderPageProbe(string $scenario, bool $cached = false): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-order-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    try {
        foreach ($cached ? ['seed', 'cache', 'request'] : ['seed', 'request'] as $step) {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/OrderPageProbe.php', $root, $temporary, $step, $scenario], $root);
            $process->setTimeout(60);
            $process->mustRun();
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

function orderPageHeaders(array $response): void
{
    foreach (['cache-control' => 'no-store, private', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff'] as $name => $value) {
        expect($response['headers'][$name] ?? null)->toBe([$value], $name);
    }
    expect($response['headers']['content-security-policy'][0] ?? '')->toContain("frame-ancestors 'none'", "form-action 'self'");
    expect($response['oldInput'])->toBeNull()->and($response['input'])->toBe([])->and($response['query'])->toBe([])->and($response['rawBody'])->toBe('');
}

it('prepares displays exports cancels and erases a guest order through real cached or uncached routes', function (string $scenario, bool $cached): void {
    $result = orderPageProbe($scenario, $cached);
    $responses = $result['responses'];
    expect(array_column($responses, 'status'))->toBe([200, 303, 200, 200, 303, 200, 200, 200, 303, 200, 303, 404])
        ->and($result['cached'])->toBe($cached)->and($responses[3]['body'])->toContain('Review your order', 'USD 48.00')
        ->and($responses[5]['body'])->toContain('Your order', 'Surf course', 'USD 48.00', 'No payment has been taken')
        ->and($responses[6]['body'])->toContain('Your orders', 'USD 48.00')
        ->and($responses[7]['headers']['content-disposition'][0])->toBe('attachment; filename="kitsune-order.json"')
        ->and($responses[7]['body'])->toContain('private-order-buyer@example.test', 'Private Invoice Lane')
        ->and($responses[9]['body'])->toContain('Cancelled')->and($result['orders'])->toBe([])->and($result['lines'])->toBe([])
        ->and($result['evidence']['stock'])->toBe('5')->and($result['evidence']['grants'])->toBe(0)
        ->and($result['evidence']['transactionLevel'])->toBe(0)->and($result['evidence']['pdoTransaction'])->toBeFalse();
    foreach (array_slice($responses, 2) as $response) {
        orderPageHeaders($response);
    }
})->with(['plain routes' => ['guest-flow', false], 'cached routes' => ['guest-flow', true], 'nested cached routes' => ['nested-flow', true]]);

it('keeps invoice input out of refused pages previous URLs flash data and logs', function (): void {
    $result = orderPageProbe('invalid');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 422, 422, 422, 422])->and($result['orders'])->toBe([]);
    foreach (array_slice($result['responses'], 2) as $response) {
        orderPageHeaders($response);
        expect($response['body'])->not->toContain('private-invalid-email', 'private-nested-name', 'private-query-secret', 'private-body-secret', 'Private Invoice Lane', 'private-order-buyer@example.test');
        expect($response['previous'] ?? '')->not->toContain('private-query-secret');
    }
    expect($result['log'])->not->toContain('Private Invoice Lane', 'private-order-buyer@example.test', 'private-query-secret');
});

it('requires body CSRF even if an order POST carries a header and query token', function (): void {
    $result = orderPageProbe('csrf');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 419, 419])->and($result['orders'])->toBe([]);
    foreach (array_slice($result['responses'], 3) as $response) {
        orderPageHeaders($response);
        expect($response['body'])->toContain('Your session expired')->not->toContain('Private Invoice Lane', 'private-order-buyer@example.test');
    }
});

it('refuses a prepared quote after a live price changes without creating an invoice', function (): void {
    $result = orderPageProbe('stale');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 422])
        ->and($result['responses'][4]['body'])->toContain('changed. Review a fresh quote')->and($result['orders'])->toBe([]);
    orderPageHeaders($result['responses'][4]);
});

it('does not reveal an order through unknown or sibling site addresses', function (): void {
    $result = orderPageProbe('unknown');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 404, 404, 404]);
    foreach (array_slice($result['responses'], 2) as $response) {
        orderPageHeaders($response);
        expect($response['body'])->not->toContain('Surf course', 'Private Invoice', 'private-query-secret');
    }
});

it('recovers a guest invoice through a mailed link only after a CSRF POST and refuses replay from another browser', function (): void {
    $result = orderPageProbe('access');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 303, 200, 404, 303, 200, 303, 200, 303, 422, 404])
        ->and($result['evidence']['mailedSecretFound'])->toBeTrue()->and($result['evidence']['mailCount'])->toBe(1)
        ->and($result['evidence']['consumedAfterGet'])->toBe(0)->and($result['evidence']['consumedAfterPost'])->toBe(1)
        ->and($result['responses'][10]['body'])->toContain('Surf course', 'Private Invoice Buyer');
    foreach (array_slice($result['responses'], 6) as $response) {
        orderPageHeaders($response);
        expect($response['body'])->not->toContain('?token=', 'private-query-secret');
        expect($response['headers']['location'][0] ?? '')->not->toContain('token=');
    }
});

it('quotes the complete packed shipment outside database transactions and stores explicitly chosen shipping tax', function (string $scenario, string $shippingNet, string $tax, string $total): void {
    $result = orderPageProbe($scenario);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 303, 200])
        ->and($result['evidence']['providerLevel'])->toBe(0)->and($result['evidence']['providerPdoTransaction'])->toBeFalse()
        ->and($result['evidence']['providerRequest']['parcels'])->toHaveCount(2)
        ->and($result['orders'])->toHaveCount(1)->and((string) $result['orders'][0]['quoted_shipping_net_minor'])->toBe($shippingNet)
        ->and((string) $result['orders'][0]['quoted_tax_minor'])->toBe($tax)->and((string) $result['orders'][0]['quoted_total_minor'])->toBe($total)
        ->and($result['evidence']['stock'])->toBe('5')->and($result['evidence']['grants'])->toBe(0);
})->with([
    'fee exclusive of tax' => ['physical-exclusive', '600', '920', '5520'],
    'fee inclusive of tax' => ['physical-inclusive', '500', '900', '5400'],
]);

it('refuses a carrier response if calculator configuration changed while the network call was in flight', function (): void {
    $result = orderPageProbe('physical-changed');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 422])
        ->and($result['evidence']['providerLevel'])->toBe(0)->and($result['orders'])->toBe([])
        ->and($result['responses'][3]['body'])->toContain('changed. Review a fresh quote');
});

it('does not retain or display a private carrier transport failure', function (): void {
    $result = orderPageProbe('physical-private-error');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 422])->and($result['orders'])->toBe([]);
    $response = $result['responses'][3];
    orderPageHeaders($response);
    expect($response['body'])->toContain('The order or delivery quote is unavailable')
        ->not->toContain('shippo_test_private_probe_token', 'Private Invoice Lane', 'private-order-buyer@example.test')
        ->and($result['log'])->not->toContain('shippo_test_private_probe_token', 'Private Invoice Lane', 'private-order-buyer@example.test');
});

it('returns a safe unavailable page rather than an incomplete saved invoice when its line store fails', function (): void {
    $result = orderPageProbe('order-outage');
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 303, 503]);
    $response = $result['responses'][5];
    orderPageHeaders($response);
    expect($response['body'])->toContain('The order or delivery quote is unavailable')
        ->not->toContain('Private Invoice Lane', 'private-order-buyer@example.test')
        ->and($result['log'])->not->toContain('Private Invoice Lane', 'private-order-buyer@example.test');
});

it('serves a private plain outage boundary from cached routes when commerce did not boot and site bootstrap fails', function (): void {
    $result = orderPageProbe('provider-absent-outage', true);
    expect($result['cached'])->toBeTrue()->and($result['evidence']['providerAbsent'])->toBeTrue()
        ->and(array_column($result['responses'], 'status'))->toBe([503]);
    $response = $result['responses'][0];
    orderPageHeaders($response);
    expect($response['body'])->toContain('unavailable')->not->toContain('private-provider-absent-bootstrap-fault', 'private-query-secret')
        ->and($result['log'])->not->toContain('private-provider-absent-bootstrap-fault', 'private-query-secret');
});

it('shows recipient duties at international quote and retained order pages without private customs details', function (): void {
    $result = orderPageProbe('physical-international', true);
    expect(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 303, 200]);
    foreach ([3, 5] as $index) {
        expect($result['responses'][$index]['body'])->toContain('payable by the recipient', 'not included in this quote')
            ->not->toContain('Private Certifier', '490199');
        orderPageHeaders($result['responses'][$index]);
    }
    expect(json_decode($result['orders'][0]['shipping'], true, flags: JSON_THROW_ON_ERROR)['international'])->toBeTrue()
        ->and($result['evidence']['providerRequest']['customs_declaration']['items'][0]['value_amount'])->toBe('40.00');
});
