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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Lang;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\CatalogueRoutes;
use Kitsune\Commerce\Http\Middleware\CartArea;
use Kitsune\Commerce\Http\Middleware\CartResponse;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;

/** Plain HTML, body-only mutation inputs, fixed redirects and safe in-place refusals. @internal */
final class CartController
{
    public function __construct(private readonly CartWriter $writer, private readonly Context $context) {}

    public function show(Request $request): Response
    {
        try {
            return $this->renderCart($request);
        } catch (CartRefused) {
            return CartResponse::unavailable('unknown');
        } catch (EntitlementUnavailable $unavailable) {
            return CartResponse::unavailable($unavailable->state);
        } catch (QueryException $unavailable) {
            return CartResponse::unavailable(ReaderAccounts::sqlstate($unavailable));
        }
    }

    public function add(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'add');
    }

    public function update(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'update');
    }

    public function remove(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'remove');
    }

    private function mutate(Request $request, string $action): Response|RedirectResponse
    {
        try {
            if ($request->attributes->get(CartArea::TOKEN) !== true) {
                return $this->sessionExpired();
            }
            $input = $request->attributes->get(CartArea::INPUT, []);
            $input = is_array($input) ? $input : [];

            if ($action === 'add') {
                $this->writer->add($request, $input['listing'] ?? null, $input['quantity'] ?? null);
            } elseif ($action === 'update') {
                $this->writer->update($request, $input['line'] ?? null, $input['quantity'] ?? null, $input['version'] ?? null);
            } else {
                $this->writer->remove($request, $input['line'] ?? null, $input['version'] ?? null);
            }

            return new RedirectResponse(CartRoutes::path($this->context->site() ?? abort(404)), 303);
        } catch (CartRefused $refused) {
            $reason = in_array($refused->reason, ['invalid', 'unavailable', 'limit', 'stale'], true) ? $refused->reason : 'invalid';

            try {
                return $this->renderCart($request, $reason, $reason === 'stale' ? 409 : 422);
            } catch (CartRefused) {
                return CartResponse::unavailable('unknown');
            } catch (EntitlementUnavailable $unavailable) {
                return CartResponse::unavailable($unavailable->state);
            } catch (QueryException $unavailable) {
                return CartResponse::unavailable(ReaderAccounts::sqlstate($unavailable));
            }
        } catch (EntitlementUnavailable $unavailable) {
            return CartResponse::unavailable($unavailable->state);
        } catch (QueryException $unavailable) {
            return CartResponse::unavailable(ReaderAccounts::sqlstate($unavailable));
        }
    }

    private function renderCart(Request $request, ?string $error = null, int $status = 200): Response
    {
        return $this->render('index', 'title', ['cart' => $this->writer->snapshot($request), 'error' => $error], $status);
    }

    public function unavailable(): Response
    {
        return $this->render('notice', 'unavailable_title', ['message' => 'unavailable_help'], 503);
    }

    public function sessionExpired(): Response
    {
        return $this->render('notice', 'refused_title', ['message' => 'session_expired'], 419);
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, string $title, array $data, int $status = 200): Response
    {
        $siteLocale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');
        $probe = 'commerce::cart.title';
        $copyLocale = Lang::hasForLocale($probe, $siteLocale) ? $siteLocale
            : (Lang::hasForLocale($probe, $fallback) ? $fallback : 'en');
        $t = self::translator($copyLocale);
        $site = $this->context->site();
        $accountPath = null;

        // Error notices must not repeat a failed settings/account read while trying to explain it.
        if ($view === 'index' && $site !== null) {
            $accounts = app(ReaderAccounts::class);
            $accountPath = $accounts->fault() === null && $accounts->mode($site)->signsIn() ? ReaderLinks::path($site) : null;
        }

        return response()->view('commerce::cart.'.$view, [
            ...$data, 't' => $t, 'title' => $t($title), 'mainId' => 'cart',
            'siteName' => $site->name ?? $t('title'),
            'homePath' => ($site->path_prefix ?? '') === '' ? '/' : $site->path_prefix,
            'cartPath' => $site === null ? null : CartRoutes::path($site),
            'cataloguePath' => $site !== null && CatalogueRoutes::availableFor($site) ? ($site->path_prefix ?? '').'/catalogue' : null,
            'accountPath' => $accountPath,
            'htmlLang' => str_replace('_', '-', $siteLocale), 'htmlDir' => Kitsune::textDirection($siteLocale),
            'copyLang' => str_replace('_', '-', $copyLocale), 'copyDir' => Kitsune::textDirection($copyLocale),
        ], $status);
    }

    /** @return Closure(string,array<string,mixed>=):string */
    private static function translator(string $locale): Closure
    {
        return static fn (string $key, array $replace = []): string => (string) __('commerce::cart.'.$key, $replace, $locale);
    }
}
