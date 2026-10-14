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
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/** The host's exact cart address and a live optional module; unavailable content remains removable. @internal */
final class CartArea
{
    public const INPUT = 'commerce.cart.input';

    public const TOKEN = 'commerce.cart.token';

    public function __construct(private readonly Context $context) {}

    /** Keep only canonical body fields in attributes; no token, unknown query, or raw body reaches a refusal. */
    public static function prepare(Request $request): void
    {
        $route = $request->route();
        $action = $route instanceof Route ? $route->getActionMethod() : '';
        $fields = match ($action) {
            'add' => ['listing', 'quantity'],
            'update' => ['line', 'quantity', 'version'],
            'remove' => ['line', 'version'],
            default => [],
        };
        $body = $request->request->all();
        $input = [];

        foreach ($fields as $field) {
            $value = $body[$field] ?? null;
            $pattern = $field === 'quantity' ? '/\A[1-9][0-9]?\z/D' : ($field === 'version' ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D');
            $input[$field] = is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
        }

        $request->attributes->set(self::TOKEN, ReaderForm::tokenMatches($request));
        $request->attributes->set(self::INPUT, $input);
        $server = $request->server->all();
        $server['REQUEST_URI'] = $request->getPathInfo();
        unset($server['QUERY_STRING']);
        // initialize resets Symfony's cached URI too; replacing only QUERY_STRING leaves _previous.url dirty.
        $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '');
        $request->setJson(new InputBag);
        $_GET = $_POST = $_REQUEST = [];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->site();
        abort_if($site === null || $this->context->orgId() === null, 404);
        $route = $request->route();
        $prefix = $route instanceof Route ? $route->parameter(CartRoutes::SITE_PARAMETER) : null;
        abort_unless($prefix === null ? ($site->path_prefix ?? '') === ''
            : (is_string($prefix) && '/'.strtolower($prefix) === ($site->path_prefix ?? '')), 404);
        abort_if(self::underPanel($request), 404);
        abort_unless(app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider, 404);
        $version = ModuleDiscovery::version('kitsune/commerce');
        $receipt = Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        abort_unless($version !== null && $receipt !== null && $receipt->version === $version, 404);

        return $next($request);
    }

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
