<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Lang;
use Kitsune\Commerce\Catalogue\CatalogueRoutes;
use Kitsune\Commerce\Catalogue\PublicCatalogue;
use Kitsune\Commerce\Http\Middleware\CatalogueArea;
use Kitsune\Commerce\Http\Middleware\CatalogueResponse;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;

/** Module-owned catalogue presentation, without a general theme or purchase policy. @internal */
final class CatalogueController
{
    public function __construct(private readonly PublicCatalogue $catalogue, private readonly Context $context) {}

    public function __invoke(Request $request): Response
    {
        try {
            return $this->page($request);
        } catch (EntitlementUnavailable $unavailable) {
            return CatalogueResponse::unavailable($unavailable->state);
        } catch (QueryException $unavailable) {
            return CatalogueResponse::unavailable(ReaderAccounts::sqlstate($unavailable));
        }
    }

    private function page(Request $request): Response
    {
        $page = $request->attributes->get(CatalogueArea::PAGE, 1);
        abort_unless(is_int($page), 404);
        $result = $this->catalogue->page($page);
        $site = $this->context->site();
        abort_if($site === null, 404);
        $path = ($site->path_prefix ?? '').'/'.CatalogueRoutes::SEGMENT;
        $accounts = app(ReaderAccounts::class);

        return $this->render('index', 'title', [
            'catalogue' => $result,
            'previousPath' => $result['hasPrevious'] ? $path.($page === 2 ? '' : '?page='.($page - 1)) : null,
            'nextPath' => $result['hasNext'] ? $path.'?page='.($page + 1) : null,
            'accountPath' => $accounts->fault() === null && $accounts->mode($site)->signsIn()
                ? ($site->path_prefix ?? '').'/'.ReaderLinks::SEGMENT : null,
        ]);
    }

    public function unavailable(): Response
    {
        return $this->render('unavailable', 'unavailable_title', [], 503);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, string $title, array $data, int $status = 200): Response
    {
        $siteLocale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');
        $probe = 'commerce::storefront.title';
        $copyLocale = Lang::hasForLocale($probe, $siteLocale) ? $siteLocale
            : (Lang::hasForLocale($probe, $fallback) ? $fallback : 'en');
        $t = self::translator($copyLocale);
        $site = $this->context->site();

        return response()->view('commerce::catalogue.'.$view, [
            ...$data,
            't' => $t,
            'title' => $t($title),
            'siteName' => $site->name ?? $t('title'),
            'homePath' => ($site->path_prefix ?? '') === '' ? '/' : $site->path_prefix,
            'accountPath' => $data['accountPath'] ?? null,
            'htmlLang' => str_replace('_', '-', $siteLocale),
            'htmlDir' => Kitsune::textDirection($siteLocale),
            'copyLang' => str_replace('_', '-', $copyLocale),
            'copyDir' => Kitsune::textDirection($copyLocale),
        ], $status);
    }

    /** @return Closure(string): string */
    private static function translator(string $locale): Closure
    {
        return static fn (string $key): string => (string) __('commerce::storefront.'.$key, [], $locale);
    }
}
