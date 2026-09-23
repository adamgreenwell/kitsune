<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\SvgSanitizer;

use Kitsune\Core\Media\SanitisesSvg;
use Kitsune\Core\Modules\ModuleServiceProvider;

/**
 * The sanitiser core declares and deliberately does not ship — ADR-041, Standing Principle #11.
 *
 * ⚠️ IT SHIPS NO ROWS, NO MIGRATIONS AND NO ADMIN SURFACE. Its entire footprint is one container binding, so
 * `install()` and `uninstall()` are inherited no-ops rather than empty overrides: there is nothing to create
 * and therefore nothing to refuse to destroy. `scoping: []` is a claim the kernel's verifier can check by
 * sweeping the package, and here it sweeps two classes with no models between them.
 *
 * ⚠️ WHAT ENABLING THIS MODULE CHANGES IS ONE ANSWER IN CORE. `MediaIntake::acceptedTypes()` asks whether
 * anything implements `SanitisesSvg`; until this binding exists the answer is no and `.svg` is refused with a
 * message naming this package. That indirection is the licence boundary: core keeps the gate and the
 * vocabulary, and the GPL-2.0-or-later library sits on this side of it.
 *
 * ⚠️ THE LICENCE SPLIT IS THE POINT, AND IT IS NOT A TECHNICALITY. ADR-005 keeps core plain MPL-2.0 — no
 * Exhibit B, enforced by AGENTS.md rule 7 — *so that* GPL code can legitimately be combined with Kitsune. It
 * separately rejected GPL as CORE's licence because that conflicts with ADR-004's paid modules and with
 * proprietary third-party ones. Both of those hold at once: this module's own code is MPL-2.0, it may
 * lawfully require a GPL library, and an operator who never installs it never distributes one.
 *
 * ⚠️ DISABLING IT DOES NOT MAKE STORED SVGs UNSAFE. The bytes on disk were sanitised on the way in and no
 * original was kept (ADR-041's departure 2), so removing this module stops NEW SVG uploads and changes
 * nothing about the ones already stored. That asymmetry is what "sanitise on write" buys over "sanitise on
 * read", and it is the reason the ADR chose it.
 */
final class SvgSanitizerServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * ⚠️ `singleton`, THOUGH THE ADAPTER BUILDS A FRESH `Sanitizer` PER CALL. The binding is what
         * `MediaIntake::acceptedTypes()` asks about on every upload of every type, so resolving it must be
         * cheap; the parser state that must not be shared is created inside `sanitise()`, where it belongs.
         */
        $this->app->singleton(SanitisesSvg::class, EnshrinedSvgSanitiser::class);
    }
}
