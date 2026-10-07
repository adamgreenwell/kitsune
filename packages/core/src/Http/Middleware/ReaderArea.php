<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderRoutes;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of every reader page — ADR-037, as built. In this order, each a 404 that names nothing:
 *
 * 1. no site in context;
 * 2. the route's `{readerSite}` is not exactly the site's prefix (lower-cased; absent only for a site at the root), so
 *    each page has one address per site, and `/golfdom/x/account` is not Golfdom's;
 * 3. the path is inside a panel's URL space;
 * 4. reader accounts cannot work here (`ReaderAccounts::fault()` — the console says why);
 * 5. the site's mode is `off`.
 *
 * Then `Auth::shouldUse()` the reader guard, so on a reader route `auth()->user()`, `Permissions::currentUser()` and any
 * audit actor are the reader or nobody — never a staff user riding the same session. And on the way out, headers for a
 * page that holds a form for a password: never cached, never framed, never a referrer, no script.
 *
 * ⚠️ `{readerSite}` IS UNTRUSTED INPUT (AGENTS.md §6): it is compared, never looked up.
 *
 * ⚠️ A NAMED RESIDUAL OF `shouldUse()`: an exception reported during a reader's request carries Laravel's own `userId`
 * context — the reader's key, as a staff user's is on the admin. The handler merges it after any context core could
 * register, so only the host's handler can drop it.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderArea
{
    /** @var array<string, string> */
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    ];

    public function __construct(
        private readonly Context $context,
        private readonly ReaderAccounts $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->site();

        abort_if($site === null, 404);
        abort_unless(self::exactPrefix($request, $site), 404);
        abort_if(self::underPanel($request), 404);
        abort_if($this->accounts->fault() !== null, 404);
        abort_unless($this->accounts->mode($site)->signsIn(), 404);

        Auth::shouldUse((string) $this->accounts->guardName());

        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private static function exactPrefix(Request $request, Site $site): bool
    {
        $route = $request->route();
        $parameter = $route instanceof Route ? $route->parameter(ReaderRoutes::SITE_PARAMETER) : null;
        $prefix = $site->path_prefix ?? '';

        if ($parameter === null) {
            return $prefix === '';
        }

        return is_string($parameter) && '/'.strtolower($parameter) === $prefix;
    }

    /**
     * Whether the path is inside any panel's URL space — only when Filament is installed.
     *
     * ⚠️ THE PANEL'S WHOLE PATH, however many segments (review): `cp/admin` is matched by `/cp/admin/…`, not by its
     * first segment alone. A panel at the root owns every path.
     *
     * ⚠️ ON ITS OWN HOSTS ONLY: a panel that declares domains owns its path on those and nowhere else, at the root or not,
     * so `admin.example.test/admin` leaves a site's `/admin` on `www` alone. A panel declaring none owns it on every host.
     */
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
