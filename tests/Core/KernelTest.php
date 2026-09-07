<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Kitsune;
use Kitsune\Core\KitsuneServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(KitsuneServiceProvider::class);
});

it('resolves Kitsune as a singleton', function (): void {
    expect(app(Kitsune::class))->toBe(app(Kitsune::class));
});

it('reports a version', function (): void {
    expect(Kitsune::version())->toBeString()->not->toBeEmpty();
});
