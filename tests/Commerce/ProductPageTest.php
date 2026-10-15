<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** The actual native pages, with their own level-zero database and real Filament/Livewire providers. @return array<string, mixed> */
function productPageProbe(string $mode): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-product-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);

    try {
        foreach (['seed', $mode] as $step) {
            $probe = new Process([PHP_BINARY, __DIR__.'/Fixtures/ProductPageProbe.php', $root, $temporary, $step], $root);
            $probe->setTimeout(30);
            $probe->mustRun();
        }

        try {
            return json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The product page probe failed: '.substr($probe->getOutput().$probe->getErrorOutput(), -8000), previous: $failure);
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('rolls back the entire native product save when a native event requests a rollback halt', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['event_reached'])->toBeTrue()->and($result['unchanged'])->toBeTrue()->and($result['level'])->toBe(0)
        ->and($result['pdo_transaction'])->toBeFalse()->and($result['revision_notes'])->toBe(0);
})->with(['halt-create', 'halt-update']);

it('hands native saved listeners the new shared values after an edit', function (): void {
    $result = productPageProbe('updated-event');
    expect($result['observed'])->toBe(['CHANGED', '2500', '5'])
        ->and($result['persisted'])->toBe(['sku' => 'CHANGED', 'price_minor' => '2500', 'stock' => '5'])
        ->and($result['errors'])->toBe([])->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse()
        ->and($result['notifications'])->toHaveCount(1)->and($result['notifications'][0]['title'])->toBe('Saved')
        ->and(array_column($result['dispatches'], 'name'))->toContain('notificationsSent');
});

it('keeps a concurrently changed balance when saving an already loaded native product form', function (): void {
    $result = productPageProbe('stale-stock-update');
    expect($result['persisted'])->toBe(['sku' => 'CHANGED', 'price_minor' => '2500', 'stock' => '2'])
        ->and($result['displayed_stock'])->toBe('2')->and($result['errors'])->toBe([])
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
});

it('can retry the same create form after a rollback halt without hydrating a rolled-back record', function (): void {
    $result = productPageProbe('halt-create-retry');
    expect($result['event_reached'])->toBeTrue()->and($result['unchanged'])->toBeTrue()
        ->and($result['persisted'])->toBe(['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'])
        ->and($result['errors'])->toBe([])->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
});

it('does not redirect or announce success when the outer commit fails', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['unchanged'])->toBeTrue()
        ->and($result['redirect'])->toBeNull()->and($result['notifications'])->toBe([])
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
})->with(['commit-failure', 'commit-failure-update']);

it('can retry the same create form after an outer commit failure', function (): void {
    $result = productPageProbe('commit-failure-retry');
    expect($result['unchanged'])->toBeTrue()
        ->and($result['persisted'])->toBe(['sku' => 'BOARD', 'price_minor' => '2000', 'stock' => '5'])
        ->and($result['errors'])->toBe([])->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
});

it('rechecks raw page write methods without an owner or a current enabled module receipt', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['statuses'])->toBe([403, 403, 403, 403])->and($result['unchanged'])->toBeTrue()->and($result['level'])->toBe(0);
})->with(['denied-no-actor', 'denied-member', 'denied-module', 'denied-version']);

it('refuses malformed and foreign product route keys before exposing a form', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['status'])->toBe(404)->and($result['unchanged'])->toBeTrue();
})->with(['bad-record-key', 'foreign-record']);

it('refuses forged mixed entitlement form state without a server error or any partial product write', function (string $mode, ?string $kept): void {
    $result = productPageProbe($mode);

    expect($result['unchanged'])->toBeTrue()->and($result['errors'])->toHaveKey('data.entitlement_name')
        ->and($result['mapping'])->toBe($kept)->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse()
        ->and($result['redirect'])->toBeNull()->and($result['notifications'])->toBe([]);
})->with([
    'create: array' => ['bad-offer-create-array', null],
    'create: bool' => ['bad-offer-create-bool', null],
    'create: numeric' => ['bad-offer-create-numeric', null],
    'edit: array' => ['bad-offer-update-array', 'course.kept'],
    'edit: bool' => ['bad-offer-update-bool', 'course.kept'],
    'edit: numeric' => ['bad-offer-update-numeric', 'course.kept'],
]);

it('saves explicit permanent or fixed access terms through the native product form', function (string $mode, array $terms): void {
    $result = productPageProbe($mode);
    expect($result['errors'])->toBe([])->and($result['mapping'])->toBe('course.surf')->and($result['terms'])->toBe($terms)
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
})->with([
    'permanent' => ['terms-permanent', ['version' => 1, 'kind' => 'permanent']],
    'fixed' => ['terms-fixed', ['version' => 1, 'kind' => 'fixed', 'days' => 45]],
    'edit fixed' => ['terms-edit', ['version' => 1, 'kind' => 'fixed', 'days' => 45]],
]);

it('clears configured purchased access when the native form clears its entitlement name', function (): void {
    $result = productPageProbe('terms-clear');
    expect($result['errors'])->toBe([])->and($result['mapping'])->toBeNull()->and($result['terms'])->toBeNull()
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
});

it('requires an explicit duration for new and historical mapped product forms', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['errors'])->toHaveKey('data.offer_kind')->and($result['unchanged'])->toBeTrue()
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
})->with(['terms-missing', 'terms-unknown']);

it('refuses forged days without partially saving product content or access', function (string $mode): void {
    $result = productPageProbe($mode);
    expect($result['errors'])->toHaveKey('data.offer_days')->and($result['unchanged'])->toBeTrue()
        ->and($result['level'])->toBe(0)->and($result['pdo_transaction'])->toBeFalse();
})->with(['terms-invalid-zero', 'terms-invalid-leading-zero', 'terms-invalid-fraction', 'terms-invalid-overbound', 'terms-invalid-array', 'terms-invalid-bool']);
