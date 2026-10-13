<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Kitsune\Core\Http\Controllers\Readers\DownloadController;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Another site never learns what a reader holds — ADR-041, the reader's download route (decisions 1–4, Adam,
 * 2026-10-11). It runs after `ReaderArea` and before `RequireReader`, on the landing page and on the file, and decides
 * from the request's Fetch Metadata alone — before the reader, the entry or the file is read:
 *
 * - `Sec-Fetch-Site` absent or `same-origin`: on, untouched;
 * - `none`, `same-site` or `cross-site`, as a navigation to a document — a link followed, a bookmark, a receipt's link:
 *   the landing page goes on; the file is answered with a 303 to its landing page, so a link straight to the bytes lands
 *   where a reader can sign in and click, and the tab is never left on a sign-in form with nothing to go back to;
 * - anything else from elsewhere — an `<img>`, an `<iframe>`, a `fetch()` — and any value this does not know: the 404,
 *   the same for a file that exists and one that does not.
 *
 * ⚠️ WHY A PAGE AT ALL. With `SameSite=none`, or a browser that sends the cookie anyway, another site's `<img>` pointed at
 * a reader's file loads for a holder and fails for anyone else — and the page that embedded it has learned what its
 * visitor bought. `Cross-Origin-Resource-Policy` blocks the bytes, and this blocks the question: the landing page never
 * downloads anything, so a window another site opens on it tells that site nothing it can read.
 *
 * ⚠️ NAMED RESIDUALS. A browser that sends no Fetch Metadata, and a plain-http site — whose requests browsers do not mark —
 * reach the route as same-origin requests do, so `Cross-Origin-Resource-Policy` is all that stands for them. Sites of
 * different orgs served host-less from one origin are one origin to every browser, and no header can tell them apart.
 *
 * ⚠️ `{id}` IS UNTRUSTED INPUT (AGENTS.md §6): it is checked by `MediaStream::key()` before it is put in a `Location`, and
 * an id the column cannot hold is the 404.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class IsolateDownload
{
    /** The values a browser sends that say a request came from elsewhere — or from nowhere, as a typed address does. */
    private const ELSEWHERE = ['none', 'same-site', 'cross-site'];

    public function __construct(private readonly Context $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $from = $request->headers->get('Sec-Fetch-Site');

        if ($from === null || $from === 'same-origin') {
            return $next($request);
        }

        abort_unless(in_array($from, self::ELSEWHERE, true) && self::followed($request), 404);

        if (! self::isFile($request)) {
            return $next($request);
        }

        $site = $this->context->site();
        $id = MediaStream::key($request->route(DownloadController::PARAMETER));

        abort_if($site === null || $id === null, 404);

        return new RedirectResponse(ReaderLinks::download($site, (int) $id), 303);
    }

    /** A navigation to a document: a link followed, a bookmark, an address typed — never an embed or a script's fetch. */
    private static function followed(Request $request): bool
    {
        return $request->headers->get('Sec-Fetch-Mode') === 'navigate' && $request->headers->get('Sec-Fetch-Dest') === 'document';
    }

    /** Whether the route is the file's rather than its landing page's — read from the route's declared shape, not the path. */
    private static function isFile(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && str_ends_with($route->uri(), ReaderLinks::FILE);
    }
}
