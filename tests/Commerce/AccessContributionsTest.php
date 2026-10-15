<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Payments\AccessContributions;
use Kitsune\Commerce\Payments\CheckoutRefused;

function contributionManifest(): array
{
    return ['version' => 1, 'confirmed_at' => '2026-10-01 12:34:56', 'definitions' => [
        ['entitlement' => 'course.surf', 'source' => 'commerce.order:42', 'expires_at' => null, 'known' => true],
        ['entitlement' => 'course.kayak', 'source' => 'commerce.order:42', 'expires_at' => '2026-10-31 12:34:56', 'known' => true],
    ], 'lines' => [
        ['id' => 11, 'entitlement_name' => 'course.surf', 'offer_terms' => '{"version":1,"kind":"fixed","days":7}'],
        ['id' => 12, 'entitlement_name' => 'course.surf', 'offer_terms' => '{"version":1,"kind":"fixed","days":30}'],
        ['id' => 13, 'entitlement_name' => 'course.surf', 'offer_terms' => '{"version":1,"kind":"permanent"}'],
        ['id' => 14, 'entitlement_name' => 'course.kayak', 'offer_terms' => '{"version":1,"kind":"fixed","days":30}'],
    ], 'allocations' => [
        ['line_id' => 11, 'awarded' => 3], ['line_id' => 12, 'awarded' => 1],
        ['line_id' => 13, 'awarded' => 1], ['line_id' => 14, 'awarded' => 2],
    ]];
}

it('removes a refunded permanent contribution then the longest fixed contribution without restarting time', function (): void {
    $manifest = contributionManifest();
    $permanentRemoved = AccessContributions::project($manifest, [13 => 1], false);
    $longRemoved = AccessContributions::project($manifest, [13 => 1, 12 => 1], false);
    expect($permanentRemoved[0]['expires_at'])->toBe('2026-10-31 12:34:56')
        ->and($longRemoved[0]['expires_at'])->toBe('2026-10-08 12:34:56')
        ->and($longRemoved[0]['suspended'])->toBeFalse()
        ->and($longRemoved[1])->toBe($permanentRemoved[1])
        ->and($manifest)->toBe(contributionManifest());
});

it('keeps one remaining unit as one pass and suspends only when its last supporting unit is removed', function (): void {
    $some = AccessContributions::project(contributionManifest(), [11 => 2, 12 => 1, 13 => 1, 14 => 1], false);
    $all = AccessContributions::project(contributionManifest(), [11 => 3, 12 => 1, 13 => 1], false);
    expect($some[0]['expires_at'])->toBe('2026-10-08 12:34:56')
        ->and($some[0]['suspended'])->toBeFalse()
        ->and($some[1]['suspended'])->toBeFalse()
        ->and($all[0]['remaining'])->toBeFalse()
        ->and($all[0]['suspended'])->toBeTrue()
        ->and($all[1]['suspended'])->toBeFalse();
});

it('releases a failed refund or won dispute using the unchanged original offer', function (): void {
    $original = AccessContributions::project(contributionManifest(), [], false);
    $held = AccessContributions::project(contributionManifest(), [], true);
    $restored = AccessContributions::project(contributionManifest(), [], false);
    expect(array_column($held, 'suspended'))->toBe([true, true])
        ->and(array_column($held, 'expires_at'))->toBe(array_column($original, 'expires_at'))
        ->and($restored)->toBe($original)
        ->and($restored[0]['expires_at'])->toBeNull();
});

it('never guesses the duration of a historical unknown order effect', function (): void {
    $manifest = contributionManifest();
    $manifest['definitions'][0]['known'] = false;
    $manifest['lines'][2]['offer_terms'] = null;
    $plan = AccessContributions::project($manifest, [13 => 1], false);
    expect($plan[0]['known'])->toBeFalse()->and($plan[0]['suspended'])->toBeTrue();
});

it('refuses attribution to unavailable foreign or loosely typed units', function (array $removed): void {
    expect(fn () => AccessContributions::project(contributionManifest(), $removed, false))->toThrow(CheckoutRefused::class);
})->with([
    'foreign line' => [[99 => 1]], 'over allocation' => [[12 => 2]], 'negative' => [[11 => -1]],
    'float' => [[11 => 1.0]], 'string' => [[11 => '1']], 'boolean' => [[11 => true]], 'padded line' => [['011' => 1]],
]);
