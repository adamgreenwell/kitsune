<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Kitsune\Commerce\Catalogue\CatalogueRoutes;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/** Exact host-delegated address, live module receipt and bounded public input. @internal */
final class CatalogueArea
{
    public const PAGE = 'commerce.catalogue.page';

    public function __construct(private readonly Context $context) {}

    public static function preparePage(Request $request): void
    {
        $rawPage = $request->query->all()['page'] ?? null;
        // Unknown parameters, including identifiers/secrets, never become pagination links or _previous.url.
        $request->query->replace([]);
        $request->server->remove('QUERY_STRING');
        abort_unless($rawPage === null || (is_string($rawPage)
            && preg_match('/\A[1-9][0-9]{0,4}\z/D', $rawPage) === 1 && (int) $rawPage <= 10000), 404);
        $page = $rawPage === null ? 1 : (int) $rawPage;

        if ($page !== 1) {
            $request->query->set('page', (string) $page);
            $request->server->set('QUERY_STRING', 'page='.$page);
        }

        $request->attributes->set(self::PAGE, $page);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->site();
        abort_if($site === null || $this->context->orgId() === null, 404);
        $route = $request->route();
        $prefix = $route instanceof Route ? $route->parameter(CatalogueRoutes::SITE_PARAMETER) : null;
        abort_unless($prefix === null ? ($site->path_prefix ?? '') === ''
            : (is_string($prefix) && '/'.strtolower($prefix) === ($site->path_prefix ?? '')), 404);
        abort_if(self::underPanel($request), 404);
        abort_unless(app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider, 404);

        // Cached routes outlive enablement and upgrades; check the persisted receipt on every request.
        $version = ModuleDiscovery::version('kitsune/commerce');
        $receipt = Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        abort_unless($version !== null && $receipt !== null && $receipt->version === $version, 404);
        abort_if(ProductType::current() === null, 404);

        return $next($request);
    }

    /** Same panel-space rule as core's reader pages, including custom paths and declared panel domains. */
    private static function underPanel(Request $request): bool
    {
        if (! app()->bound('filament')) {
            return false;
        }

        $path = strtolower(trim($request->getPathInfo(), '/'));

        foreach (Filament::getPanels() as $panel) {
            $domains = $panel->getDomains();

            if ($domains !== [] && ! in_array(strtolower($request->getHost()), array_map('strtolower', $domains), true)) {
                continue;
            }

            $panelPath = strtolower(trim($panel->getPath(), '/'));

            if ($panelPath === '' || $path === $panelPath || str_starts_with($path, $panelPath.'/')) {
                return true;
            }
        }

        return false;
    }
}
