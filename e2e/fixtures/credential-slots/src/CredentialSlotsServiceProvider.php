<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\CredentialSlots;

use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Modules\ModuleServiceProvider;

/**
 * The browser suite's credentials — ADR-040's admin half has nothing to set until a module declares something.
 *
 * ⚠️ TEST-ONLY, AND NEVER PUBLISHED. `composer skeleton:install` puts it in the skeleton's vendor switched off, and only
 * `e2e/global-setup.js` enables it. It lives under `e2e/`, which no release, package split or floor benchmark copies.
 *
 * ⚠️ IT MIRRORS `tests/Core/Fixtures/CredentialFixture.php`'s THREE SHAPES, kept in step by review because the PHP suite
 * cannot load it: prefixes that decide the mode, one prefix that decides nothing (the line decides), and one value kept
 * once. The prefixes are invented, so nothing committed matches a real provider's key pattern.
 */
final class CredentialSlotsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $help = 'Declared by the browser suite\'s test module so the credentials page has something to set. Nothing reads it.';

        $this->app->make(CredentialSlots::class)
            ->register(new CredentialSlot(
                name: 'e2e.payment-key',
                label: 'Browser-test payment key',
                help: $help,
                moded: true,
                prefixes: ['test' => ['fx_test_'], 'live' => ['fx_live_']],
                minLength: 32,
            ))
            ->register(new CredentialSlot(
                name: 'e2e.webhook-secret',
                label: 'Browser-test webhook secret',
                help: $help,
                moded: true,
                prefixes: ['test' => ['fxhook_'], 'live' => ['fxhook_']],
                minLength: 24,
            ))
            ->register(new CredentialSlot(
                name: 'e2e.shared-secret',
                label: 'Browser-test shared secret',
                help: $help,
                moded: false,
                minLength: 32,
            ));
    }
}
