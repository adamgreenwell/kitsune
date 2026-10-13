<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function moduleFreshBoot(string $mode): array
{
    $root = dirname(__DIR__, 3);
    $temporary = sys_get_temp_dir().'/kitsune-module-boot-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);

    try {
        $probe = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ModuleBootProbe.php', $root, $temporary, $mode], $root);
        $probe->setTimeout(30);
        $probe->mustRun();

        return json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('registers module resources before Filament builds a fresh panel and its routes', function (): void {
    $boot = moduleFreshBoot('enabled');

    expect($boot['resolver_was_null'])->toBeTrue()
        ->and($boot['fixture_calls'])->toBe(['register', 'boot'])
        ->and($boot['surface_has_currency'])->toBeTrue()
        ->and($boot['panel_has_currency'])->toBeTrue()
        ->and($boot['currency_route'])->toEndWith('/storefront/currency')
        ->and($boot['dashboard_route'])->not->toBeNull()
        ->and($boot['roles_route'])->not->toBeNull()
        ->and($boot['currency_translations_loaded'])->toBeTrue();
});

it('leaves disabled modules out of a fresh panel and its routes', function (): void {
    $boot = moduleFreshBoot('disabled');

    expect($boot['fixture_calls'])->toBe([])
        ->and($boot['surface_has_currency'])->toBeFalse()
        ->and($boot['panel_has_currency'])->toBeFalse()
        ->and($boot['currency_route'])->toBeNull()
        ->and($boot['dashboard_route'])->not->toBeNull()
        ->and($boot['roles_route'])->not->toBeNull();
});

it('registers and boots enabled modules on a fresh headless application', function (): void {
    $boot = moduleFreshBoot('headless');

    expect($boot['fixture_calls'])->toBe(['register', 'boot'])
        ->and($boot['surface_has_currency'])->toBeTrue()
        ->and($boot['panels'])->toBe([])
        ->and($boot['currency_route'])->toBeNull()
        ->and($boot['currency_translations_loaded'])->toBeTrue();
});
