<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Tenancy\Context;

/**
 * The public placeholder, for `/` and for any path that resolves a site.
 *
 * Kitsune's first release is the admin. A public site that renders entries is theming, which ADR-011 moved to
 * v1.1 — so both routes render one page that says so and links to the admin.
 *
 * ⚠️ ONE PLACE BUILDS IT, BECAUSE TWO DID AND BOTH WENT STALE THE SAME WAY. Each route closure typed
 * `'phase' => 'Phase 0 — foundations'`, and the page went on telling a visitor "there is no admin panel yet"
 * for three phases after the admin shipped — while the README sent the same visitor to `/admin`. The page
 * names no phase now, and the admin link is the panel's own path rather than a second copy of it.
 */
final class WelcomeController
{
    public function home(): Response
    {
        return $this->render();
    }

    public function site(Context $context): Response
    {
        // ⚠️ 404 HERE rather than in the middleware. The middleware resolves identity and
        // reports absence by leaving Context empty, because most public routes are not
        // site-scoped and it must be attachable to them. A route that REQUIRES a site is
        // the thing entitled to refuse.
        abort_if($context->site() === null, 404);

        return $this->render();
    }

    private function render(): Response
    {
        return response()->view('welcome', [
            'version' => Kitsune::version(),
            'adminUrl' => url(Filament::getDefaultPanel()->getPath()),
            // ⚠️ The page emitted `lang` and no `dir`, so an RTL locale served RTL text in a
            // left-to-right document. Filament supplies this for the admin from its own
            // translations; the public side has no panel and needs Kitsune's own answer
            // (ADR-018). Resolved per request by `SetSiteLocale`, so two sites with different
            // locales are served correctly from one process.
            'direction' => Kitsune::textDirection(),
        ]);
    }
}
