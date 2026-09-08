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

        // The LANGUAGE subtag decides it: `ar`, `ar_EG` and `ar-EG` are one
        // language, and a region never changes which way it is written.
        $language = strtolower((string) preg_split('/[_-]/', $locale)[0]);

        return in_array($language, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}
