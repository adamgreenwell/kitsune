<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\ReaderGuard;

use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Modules\ModuleServiceProvider;

/**
 * The browser suite's reader guard — ADR-040's entitlements page lists nothing, and offers nothing, until an
 * installation declares one.
 *
 * ⚠️ TEST-ONLY, AND NEVER PUBLISHED. `composer skeleton:install` puts it in the skeleton's vendor switched off, and only
 * `e2e/global-setup.js` enables it. It lives under `e2e/`, which no release, package split or floor benchmark copies.
 * When reader accounts arrive, the skeleton's own reader model replaces it, and that slice deletes it.
 *
 * ⚠️ IT MIRRORS `tests/Core/Fixtures/EntitlementFixture::declareReaders()`, kept in step by review because the PHP suite
 * cannot load it: the host's `config/auth.php` and `config/kitsune.php`, as a host writes them. Here rather than in
 * `register()`, which the kernel keeps final; `ReaderGuard` reads the configuration on every call, and Laravel builds a
 * guard on its first use, so setting it while the module registers is in time.
 *
 * ⚠️ `config:cache` WOULD KEEP THE GUARD after the module is disabled, until `config:clear`. Nothing in the browser
 * suite caches configuration.
 */
final class ReaderGuardServiceProvider extends ModuleServiceProvider
{
    public const GUARD = 'e2e_public_readers';

    protected function registerModule(): void
    {
        config([
            'auth.guards.'.self::GUARD => ['driver' => 'session', 'provider' => self::GUARD],
            'auth.providers.'.self::GUARD => ['driver' => 'eloquent', 'model' => PublicReader::class],
            ReaderGuard::CONFIG => self::GUARD,
        ]);
    }

    protected function bootModule(): void
    {
        $this->commands([SeedPublicReadersCommand::class]);
    }

    public function migrationPath(): ?string
    {
        return __DIR__.'/../database/migrations';
    }
}
