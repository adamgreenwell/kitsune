<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function internationalQuoteProbe(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-international-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    try {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/InternationalQuoteProbe.php', $root, $temporary, $scenario], $root);
        $process->setTimeout(60);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('quotes and rechecks an international guest purchase with exact original values and no financial effects', function (string $scenario, string $net): void {
    $result = internationalQuoteProbe($scenario);
    expect($result['accepted'])->toBeTrue()->and($result['replay'])->toBeTrue()->and($result['orders'])->toBe(1)
        ->and($result['attempts'])->toBe(0)->and($result['stock'])->toBe('5')->and($result['network'])->toHaveCount(2)
        ->and($result['record']['shipping']['international'])->toBeTrue()
        ->and($result['shippingExport']['data']['international'])->toBeTrue()
        ->and($result['shippingExport']['data']['duties_payor'])->toBe('recipient')
        ->and($result['shippingExport']['data']['non_delivery_option'])->toBe('return');
    foreach ($result['network'] as $network) {
        expect($network['level'])->toBe(0)->and($network['pdo'])->toBeFalse()
            ->and($network['payload']['carrier_accounts'])->toBe([str_repeat('a', 32)])
            ->and($network['payload']['customs_declaration']['items'][0])->toBe([
                'description' => 'Printed book', 'origin_country' => 'GB', 'tariff_number' => '490199', 'quantity' => 2,
                'net_weight' => '1000', 'mass_unit' => 'g', 'value_amount' => $net, 'value_currency' => 'USD',
            ]);
    }
    expect(json_encode([$result['record'], $result['shippingExport']], JSON_THROW_ON_ERROR))->not->toContain('Explicit Certifier', '490199', str_repeat('a', 32));
})->with([['success', '40.00'], ['inclusive', '33.33']]);

it('refuses international configuration changes at each quote and buyer consent seam', function (string $scenario, string $stage, int $calls, int $orders): void {
    $result = internationalQuoteProbe($scenario);
    expect($result['accepted'])->toBeFalse()->and($result['stage'])->toBe($stage)
        ->and($result['network'])->toHaveCount($calls)->and($result['orders'])->toBe($orders)->and($result['attempts'])->toBe(0);
})->with([
    ['quote-change-customs', 'quote', 1, 0], ['quote-change-settings', 'quote', 1, 0], ['quote-change-mode', 'quote', 1, 0],
    ['quote-change-credential', 'quote', 1, 0], ['quote-change-accounts', 'quote', 1, 0], ['quote-change-destination', 'quote', 1, 0], ['quote-change-certifier', 'quote', 1, 0], ['prepare-change-customs', 'prepare', 1, 0],
    ['review-change-customs', 'review', 2, 1], ['review-change-settings', 'review', 2, 1], ['review-change-mode', 'review', 2, 1],
    ['prepare-attack-site', 'prepare', 1, 0], ['prepare-attack-org', 'prepare', 1, 0], ['review-attack-site', 'review', 1, 1], ['review-attack-org', 'review', 1, 1],
    ['approve-change-customs', 'approve', 2, 1], ['approve-change-settings', 'approve', 2, 1],
]);

it('refuses incomplete unsupported and zero-value international goods before provider I O', function (string $scenario): void {
    $result = internationalQuoteProbe($scenario);
    expect($result['accepted'])->toBeFalse()->and($result['stage'])->toBe('quote')->and($result['network'])->toBe([])->and($result['orders'])->toBe(0);
})->with(['incomplete', 'zero', 'destination', 'no-accounts']);
