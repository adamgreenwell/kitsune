<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Kitsune\Commerce\Exports\ExportCursor;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Commerce\Filament\OrderResource;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Native, body-authorized download of a complete checked page from the selected Site. @internal */
final class OrderExportController
{
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];

    public function __invoke(#[\SensitiveParameter] Request $request): Response
    {
        $body = $request->request->all();
        $token = $body['_token'] ?? null;
        if (! $request->isMethod('POST') || $request->isJson() || ! $request->hasSession()
            || ! is_string($token) || $token === '' || ! hash_equals((string) $request->session()->token(), $token)) {
            return $this->failure(419);
        }
        $context = app(Context::class);
        $org = $context->orgId();
        $site = $context->siteId();
        try {
            $allowed = OrderResource::canAccess();
        } catch (Throwable) {
            return $this->failure(503);
        }
        if ($context->orgId() !== $org || $context->siteId() !== $site) {
            return $this->failure(503);
        }
        if (! $allowed) {
            return $this->failure(403);
        }
        $format = $body['format'] ?? 'jsonl';
        $limit = $body['limit'] ?? '100';
        $cursor = $body['cursor'] ?? '';
        $billing = $body['include_billing'] ?? null;
        if (array_diff(array_keys($body), ['_token', 'format', 'limit', 'cursor', 'include_billing']) !== []
            || ! is_string($format) || ! in_array($format, ['jsonl', 'csv'], true)
            || ! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1 || (int) $limit > 100
            || ! is_string($cursor) || strlen($cursor) > ExportCursor::MAX_BYTES || ($billing !== null && $billing !== '1')) {
            return $this->failure(422);
        }
        try {
            $page = app(OrderHistoryExport::class)->forOwner($format, (int) $limit, $cursor === '' ? null : $cursor, $billing === '1');

            return new Response($page->body, 200, [...self::HEADERS, 'Content-Type' => $page->contentType(),
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $page->filename()),
                ...($page->nextCursor === null ? [] : ['X-Kitsune-Next-Cursor' => $page->nextCursor])]);
        } catch (Throwable) {
            return $this->failure(503);
        }
    }

    private function failure(int $status): Response
    {
        return new Response(__('commerce::exports.unavailable'), $status, [...self::HEADERS, 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
