<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Kitsune;

/*
 * ADR-018's RTL rule, as something other than an intention.
 *
 * ⚠️ Kitsune needs its own answer rather than reading Filament's. Filament
 * renders `dir` from `__('filament-panels::layout.direction')`, which is admin
 * chrome — the public side has no panel and must not depend on one (ADR-002).
 * The skeleton's page emitted `lang` and no `dir` at all, so an Arabic locale
 * served Arabic text in a left-to-right document. Nothing caught it because
 * nothing had ever rendered the app in an RTL locale (#12).
 */

it('reports rtl for every language Kitsune claims', function (string $locale): void {
    expect(Kitsune::textDirection($locale))->toBe('rtl');
})->with(['ar', 'ckb', 'fa', 'he', 'ku', 'ur']);

it('reports ltr for a left-to-right language', function (string $locale): void {
    expect(Kitsune::textDirection($locale))->toBe('ltr');
})->with(['en', 'fr', 'de', 'ja', 'zh_CN']);

it('reads the LANGUAGE subtag, not the whole tag', function (string $locale): void {
    // A region never changes which way a language is written, and the locale
    // arrives in three shapes: Laravel's `ar_EG`, BCP 47's `ar-EG`, and bare.
    expect(Kitsune::textDirection($locale))->toBe('rtl');
})->with(['ar_EG', 'ar-EG', 'AR', 'he_IL', 'fa-IR']);

it('reads an explicit SCRIPT subtag, which overrides the language', function (string $locale, string $expected): void {
    /*
     * ⚠️ Direction is a property of the SCRIPT, not the language, and reading
     * only the language subtag was wrong for two of the six.
     *
     * Kurmanji Kurdish is usually written in LATIN — `ku-Latn` is the common
     * case, not an exotic one — and Sorani has a Latin orthography too. Taking
     * the language alone returned `rtl` for both, so configuring `ku-Latn` served
     * Latin text in a right-to-left document: the same defect as the missing
     * `dir`, with the sign flipped.
     */
    expect(Kitsune::textDirection($locale))->toBe($expected);
})->with([
    ['ku-Latn', 'ltr'],
    ['ckb-Latn', 'ltr'],
    ['zh-Hans', 'ltr'],
    ['ar-Arab', 'rtl'],
    ['he-Hebr', 'rtl'],
    // Nastaliq, the style Urdu is set in.
    ['ur-Aran', 'rtl'],
]);

it('does not mistake a REGION for a script', function (string $locale): void {
    // BCP 47 puts the script second and it is always four letters, which is
    // what separates `ku-Latn` from `ku-IQ`. A region never changes direction.
    expect(Kitsune::textDirection($locale))->toBe('rtl');
})->with(['ku-IQ', 'ar-EG', 'he_IL', 'fa-AF']);

it('falls back to ltr for a locale it does not know', function (): void {
    // Fail SAFE rather than fail closed: an unknown locale renders the way
    // most of the world's languages do, and a wrong guess here is a layout
    // annoyance rather than a data or isolation problem. The list is
    // deliberately limited to what was measured, so unknown locales are
    // expected rather than exceptional — see the constant's docblock.
    expect(Kitsune::textDirection('xx'))->toBe('ltr')
        ->and(Kitsune::textDirection(''))->toBe('ltr');
});

it('uses the application locale when given nothing', function (): void {
    app()->setLocale('ar');
    expect(Kitsune::textDirection())->toBe('rtl');

    app()->setLocale('en');
    expect(Kitsune::textDirection())->toBe('ltr');
});
