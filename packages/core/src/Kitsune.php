<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core;

final class Kitsune
{
    /**
     * The lowest hardware Kitsune is designed to run on (ADR-027).
     *
     * Held here rather than in documentation alone so the resource-floor
     * benchmark has a single value to assert against, and so raising it
     * is a visible code change rather than a quiet drift.
     */
    public const FLOOR_VCPU = 1;

    public const FLOOR_MEMORY_MB = 1024;

    /**
     * Languages Kitsune claims right-to-left rendering for (ADR-018).
     *
     * ⚠️ These are exactly the locales Filament v5.7.8 ships a
     * `direction => 'rtl'` translation for — read out of
     * `filament/filament/resources/lang/*\/layout.php`, not assumed. ADR-018
     * said "four major RTL languages"; there are six. `ckb` (Sorani) and `ku`
     * (Kurmanji) were missing from the count.
     *
     * Deliberately not the full CLDR right-to-left set. Nothing in this
     * project can currently verify a direction claim for a language it has no
     * translation for, and invariant 15 says measure rather than reason — so
     * the list is what was measured, and adding to it is a visible change
     * here rather than a quiet assumption, the same reason FLOOR_VCPU is a
     * constant. A site publishing in a language absent from this list renders
     * LTR, which is wrong for that reader and is recorded in
     * `docs/accessibility-inventory.md` rather than left to be discovered.
     *
     * @var list<string>
     */
    public const RTL_LANGUAGES = ['ar', 'ckb', 'fa', 'he', 'ku', 'ur'];

    /**
     * Scripts written right-to-left, as ISO 15924 codes.
     *
     * ⚠️ Deliberately only the scripts the languages above are written in —
     * Arabic, its Nastaliq style (Urdu), and Hebrew. Anything else carrying an
     * explicit script subtag is treated as left-to-right, which is the same
     * fail-safe direction an unknown locale takes: most of the world's scripts
     * are LTR, and a wrong guess here is a layout annoyance rather than a data
     * problem.
     *
     * The list is short for the same reason `RTL_LANGUAGES` is: it covers what
     * this project can actually render, and extending it is a visible change
     * rather than a quiet assumption.
     *
     * @var list<string>
     */
    public const RTL_SCRIPTS = ['Arab', 'Aran', 'Hebr'];

    public static function version(): string
    {
        return '0.0.1-dev';
    }

    /**
     * The writing direction for a locale, as an HTML `dir` value.
     *
     * ⚠️ Kitsune needs its own answer rather than reading Filament's.
     * Filament renders `dir` from `__('filament-panels::layout.direction')`,
     * which is admin chrome — the public side has no panel and must not
     * depend on one (ADR-002 keeps core headless-capable). The skeleton's own
     * page emitted `lang` and no `dir` at all, so an Arabic locale served
     * Arabic text in a left-to-right document.
     */
    public static function textDirection(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $subtags = preg_split('/[_-]/', $locale) ?: [];

        // ⚠️ An explicit SCRIPT wins over the language's default, and reading
        // only the language was wrong.
        //
        // Direction is a property of the script, not the language, and two of
        // the six are routinely written in more than one. Kurmanji Kurdish is
        // usually LATIN — `ku-Latn` is the common case, not an exotic one — and
        // Sorani has a Latin orthography too. Taking the language subtag alone
        // returned `rtl` for both, so configuring `ku-Latn` served Latin text in
        // a right-to-left document: the same defect as the missing `dir`, with
        // the sign flipped.
        //
        // ⚠️ And the script is not necessarily the SECOND subtag, which the
        // first fix assumed. BCP 47 allows up to three three-letter extlangs
        // between the language and the script, so `ar-aao-Latn` names Latin and
        // was read as `ar` plus something region-shaped. The script is the first
        // FOUR-letter subtag, and it can only appear before the region — which a
        // two-letter or three-digit subtag marks — so the scan stops there
        // rather than searching the whole tag and finding a variant.
        foreach (array_slice($subtags, 1, 4) as $subtag) {
            if (preg_match('/^[A-Za-z]{4}$/', $subtag) === 1) {
                return in_array(ucfirst(strtolower($subtag)), self::RTL_SCRIPTS, true) ? 'rtl' : 'ltr';
            }

            // A region ends the region where a script may legally appear.
            if (preg_match('/^([A-Za-z]{2}|[0-9]{3})$/', $subtag) === 1) {
                break;
            }
        }

        // No script named, so the language's usual one decides. A REGION never
        // does: `ar_EG` and `ar` are written the same way.
        $language = strtolower((string) ($subtags[0] ?? ''));

        return in_array($language, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}
