<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Lang;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Models\Site;

/**
 * One reader page, rendered — the site's language and direction on `<html>`, the copy's on `<main>`.
 *
 * ⚠️ TWO LOCALES, AS THE WELCOME PAGE HAS. `<html lang dir>` is the site's: it is the site's document. `<main lang dir>`
 * is the locale the words on the page were actually found in — the site's when core has `kitsune::readers` in it, else
 * the application's fallback — so an Arabic site renders English copy left to right, and a screen reader reads it as
 * English, until an `ar` file exists (`lang/vendor/kitsune/ar/readers.php`), when it follows by itself.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderPage
{
    /** A key every copy of the readers' strings has: asked to decide whether a locale has them at all. */
    private const PROBE = 'kitsune::readers.sign_in.title';

    /** @param  array<string, mixed>  $data */
    public static function render(Site $site, string $view, string $title, array $data = [], int $status = 200): Response
    {
        $siteLocale = app()->getLocale();
        $copyLocale = self::copyLocale();
        $t = self::translator($copyLocale);

        return response()->view('kitsune::readers.'.$view, [
            ...$data,
            't' => $t,
            'siteName' => $site->name,
            'title' => $t($title),
            'htmlLang' => str_replace('_', '-', $siteLocale),
            'htmlDir' => Kitsune::textDirection($siteLocale),
            'copyLang' => str_replace('_', '-', $copyLocale),
            'copyDir' => Kitsune::textDirection($copyLocale),
            'homePath' => ($site->path_prefix ?? '') === '' ? '/' : $site->path_prefix,
        ], $status);
    }

    /** The locale the readers' copy is found in: the site's when core has `kitsune::readers` in it, else the fallback. */
    public static function copyLocale(): string
    {
        $siteLocale = app()->getLocale();

        return Lang::hasForLocale(self::PROBE, $siteLocale) ? $siteLocale : (string) config('app.fallback_locale', 'en');
    }

    /**
     * One copy key, in that locale. A `count` replacement picks a counted sentence's form.
     *
     * @return \Closure(string, array<string, int|string>=): string
     */
    public static function translator(string $locale): \Closure
    {
        return static fn (string $copy, array $replace = []): string => isset($replace['count']) && is_int($replace['count'])
            ? trans_choice('kitsune::readers.'.$copy, $replace['count'], $replace, $locale)
            : (string) __('kitsune::readers.'.$copy, $replace, $locale);
    }
}
