<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Themes\PublicThemePage;
use Symfony\Component\HttpFoundation\Response;

/** Dispatches public Site themes and the default welcome page. */
final class WelcomeController
{
    public function home(Request $request, Context $context, PublicThemePage $themes): Response
    {
        $site = $context->site();

        return ($site === null ? null : $themes->render($request, $site, 'site.home')) ?? $this->render();
    }

    public function site(Request $request, Context $context, PublicThemePage $themes): Response
    {
        $site = $context->site();
        abort_if($site === null, 404);

        return $themes->render($request, $site, 'site.home') ?? $this->render();
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
