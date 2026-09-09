<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kitsune\Core\Kitsune;

/*
 * Placeholder front end. Kitsune has no admin panel yet - that arrives with
 * the tenancy kernel in Phase 2 and the schema engine in Phase 4.
 *
 * Until then this route earns its place by being something a browser can
 * actually assert on, which is what unblocks the Playwright job (ADR-024).
 * It reports the two facts worth proving at this stage: the framework boots,
 * and kitsune/core is installed and resolvable.
 */
Route::get('/', function () {
    return response()->view('welcome', [
        'version' => Kitsune::version(),
        'phase' => 'Phase 0 — foundations',
        // ⚠️ The page emitted `lang` and no `dir`, so an RTL locale served
        // RTL text in a left-to-right document. Filament supplies this for
        // the admin from its own translations; the public side has no panel
        // and needs Kitsune's own answer (ADR-018).
        'direction' => Kitsune::textDirection(),
    ]);
})->name('home');
