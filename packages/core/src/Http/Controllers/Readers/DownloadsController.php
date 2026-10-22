<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\DownloadOffers;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reader's current-Site private offers, with eligible-row pagination and no totals. Listing an offer promises no
 * byte availability: every link opens the existing landing route, which freshly checks access and storage.
 *
 * @internal First-party modules only; no public extension contract.
 */
final class DownloadsController
{
    public const PARAMETER = 'page';

    public function __invoke(Request $request, Context $context, DownloadOffers $offers): Response
    {
        $site = $context->site() ?? abort(404);
        $raw = $request->route(self::PARAMETER);
        $page = $raw === null ? 1 : (is_string($raw) && preg_match('/^[1-9][0-9]{0,4}$/D', $raw) === 1 ? (int) $raw : 0);
        abort_if($page < 1 || $page > DownloadOffers::LAST_PAGE, 404);

        if ($raw === '1') {
            return redirect(ReaderLinks::downloads($site), 303);
        }

        try {
            $found = $offers->page($page);
        } catch (EntitlementUnavailable $error) {
            return $this->unavailable($site, $error->state);
        } catch (QueryException $error) {
            return $this->unavailable($site, ReaderAccounts::sqlstate($error));
        }

        abort_if($page > 1 && $found['offers'] === [], 404);

        return ReaderPage::render($site, 'downloads', 'downloads.title', [
            'offers' => $found['offers'],
            'previous' => $page > 1 ? ReaderLinks::downloads($site, $page - 1) : null,
            'next' => $found['next'] ? ReaderLinks::downloads($site, $page + 1) : null,
            'account' => ReaderLinks::path($site),
        ]);
    }

    private function unavailable(Site $site, string $state): Response
    {
        MediaStream::unavailable($state);

        return ReaderPage::render($site, 'notice', 'downloads.title', [
            'message' => 'downloads.unavailable',
            'next' => [ReaderLinks::path($site), 'home.title'],
        ], 503);
    }
}
