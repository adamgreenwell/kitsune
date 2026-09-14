<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Composer\InstalledVersions;
use Kitsune\Core\Kitsune;
use Kitsune\Core\KitsuneServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(KitsuneServiceProvider::class);
});

it('resolves Kitsune as a singleton', function (): void {
    expect(app(Kitsune::class))->toBe(app(Kitsune::class));
});

it('reports the version Composer installed', function (): void {
    /*
     * ⚠️ THE VERSION WAS A LITERAL, AND THIS ASSERTED ONLY THAT IT WAS NOT EMPTY — so it passed while every
     * tagged release would have reported `0.0.1-dev`. Asserted against Composer's own record now, which is what
     * a host's lock file says was installed.
     */
    expect(InstalledVersions::isInstalled('kitsune/core'))->toBeTrue()
        ->and(Kitsune::version())->toBe(InstalledVersions::getPrettyVersion('kitsune/core'))
        ->and(Kitsune::version())->not->toBe('0.0.1-dev');
});
