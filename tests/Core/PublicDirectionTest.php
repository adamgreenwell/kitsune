<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Kitsune\Core\Kitsune;

/**
 * Kitsune's own output carries a direction — gap G1 of the #12 spike.
 *
 * ⚠️ Moved here from `e2e/rtl.spec.js`. That project used to run a second web server
 * with `APP_LOCALE=ar`, which was the only way to reach an RTL public page: the public
 * side has no site-scoped routes, so nothing resolves a locale per request for it
 * (roadmap Phase 6). Issue #38 replaced the second server with a seeded editor whose UI
 * locale is Arabic, and a viewer preference reaches the ADMIN rather than the public
 * page — so this assertion lost its fixture.
 *
 * It did not need a browser. There is no JavaScript in the claim, only that the template
 * emits what `Kitsune::textDirection()` returns, so a rendered view is the right level
 * and costs a whole web server less.
 */
it('emits dir and lang from the app locale, in both directions', function (): void {
    // ⚠️ BOTH directions asserted. The original RTL check visited the English site and
    // asserted `dir="ltr"`, which stays green while every RTL layout is broken — so the
    // LTR case alone proves nothing, and the RTL case alone would pass on a hard-coded
    // `dir="rtl"`.
    foreach ([['ar', 'rtl'], ['he', 'rtl'], ['en', 'ltr'], ['fr', 'ltr']] as [$locale, $direction]) {
        app()->setLocale($locale);

        expect(Kitsune::textDirection())->toBe($direction, "[{$locale}] resolved the wrong direction");
    }
});

it('renders the skeleton page with a direction attribute', function (): void {
    // The template half: `textDirection()` being right is no use if the view drops it,
    // which is exactly what the original defect was — `lang` was emitted and `dir` was
    // not, so an Arabic locale served Arabic text in a left-to-right document.
    app()->setLocale('ar');

    // ⚠️ PREPENDED, not added, and `addLocation()` silently tested the wrong file.
    //
    // Testbench ships its own `welcome` view. `addLocation()` APPENDS, so the framework's
    // stub won and the render came back as `<html lang="ar">` with no `dir` at all —
    // which reads exactly like the defect this test exists to catch, in a template that
    // has emitted `dir` all along. An assertion against the wrong artifact fails for the
    // right-looking reason, which is worse than one that fails cleanly.
    //
    // The core suite runs under Testbench and knows nothing of the application's views,
    // so the path is supplied here. The directory is in the repo, so this still runs on a
    // bare clone (invariant 11).
    View::prependLocation(dirname(__DIR__, 2).'/skeleton/resources/views');

    $html = view('welcome', [
        'version' => Kitsune::version(),
        'phase' => 'test',
        'direction' => Kitsune::textDirection(),
    ])->render();

    expect($html)->toContain('dir="rtl"')->toContain('lang="ar"');
});
