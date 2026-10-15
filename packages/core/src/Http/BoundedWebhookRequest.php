<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A host's signed webhook leaf is bounded before Laravel's capture eagerly decodes JSON (ADR-040).
 *
 * The host names the reserved suffix and signature header; unrelated callback paths retain ordinary capture.
 * No signature or JSON is interpreted here. A verifier authenticates the exact bounded bytes before parsing them.
 * A route must require isBounded(), so a host which forgets this front-controller integration fails closed.
 *
 * @internal First-party host integration; no extension contract before v1.2.
 */
final class BoundedWebhookRequest
{
    public const MAX_BODY_BYTES = 262144;

    public const MAX_HEADER_BYTES = 4096;

    public const MAX_SIGNATURE_PARTS = 32;

    private const MARKER = 'kitsune.webhook.bounded_body';

    public static function capture(string $pathSuffix, string $signatureHeader): Request|Response
    {
        Request::enableHttpMethodParameterOverride();
        // Symfony's createFromGlobals() also parses PUT/PATCH/DELETE/QUERY bodies before returning. Inspect only
        // the globals first, so a different method cannot make that parser run before the reserved leaf's budget.
        $base = new SymfonyRequest($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER);
        if (self::appliesTo($base, $pathSuffix)) {
            return self::fromBase($base, $pathSuffix, $signatureHeader);
        }

        return self::fromBase(SymfonyRequest::createFromGlobals(), $pathSuffix, $signatureHeader);
    }

    /** Symfony has not decoded JSON; passing an already captured Illuminate request proves no ingress memory bound. */
    public static function fromBase(SymfonyRequest $base, string $pathSuffix, string $signatureHeader): Request|Response
    {
        if ($pathSuffix === '' || $pathSuffix[0] !== '/' || strpbrk($pathSuffix, '?%#') !== false
            || preg_match('/\A[A-Za-z][A-Za-z0-9-]*\z/D', $signatureHeader) !== 1) {
            throw new InvalidArgumentException('The webhook capture suffix and signature header must be fixed names.');
        }

        // UriValidator trims before its single decode. Match every method: an override must not bypass this budget.
        if (! self::appliesTo($base, $pathSuffix)) {
            return Request::createFromBase($base);
        }

        try {
            if (! self::headerIsBounded($base, $signatureHeader)) {
                return self::refused(400);
            }
            $length = $base->headers->get('Content-Length');
            if ($length !== null && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $length) !== 1) {
                return self::refused(400);
            }
            if ($length !== null && (int) $length > self::MAX_BODY_BYTES) {
                return self::refused(413);
            }

            $stream = $base->getContent(true);
            if (! is_resource($stream)) {
                return self::refused(400);
            }
            $body = '';
            try {
                while (! feof($stream) && strlen($body) <= self::MAX_BODY_BYTES) {
                    $chunk = fread($stream, min(8192, self::MAX_BODY_BYTES + 1 - strlen($body)));
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        return self::refused(400);
                    }
                    $body .= $chunk;
                }
            } finally {
                fclose($stream);
            }
            if (strlen($body) > self::MAX_BODY_BYTES) {
                return self::refused(413);
            }

            $headers = $base->headers->all();
            $base->initialize($base->query->all(), [], $base->attributes->all(), $base->cookies->all(), [], $base->server->all(), $body);
            $base->headers->replace($headers);
            // Laravel's createFromBase() eagerly JSON-decodes. Mask only during capture, then cache an empty JSON bag
            // for global TrimStrings/ConvertEmptyStringsToNull. The route's verifier gets the untouched raw body.
            $base->headers->set('Content-Type', 'application/octet-stream');
            try {
                $request = Request::createFromBase($base);
            } finally {
                $base->headers->replace($headers);
            }
            $request->headers->replace($headers);
            $request->setJson(new InputBag);
            $request->attributes->set(self::MARKER, hash('sha256', $body));

            return $request;
        } catch (Throwable) {
            return self::refused(400);
        }
    }

    /** This is a byte-capture receipt, never authentication. Any raw-body mutation invalidates it. */
    public static function isBounded(Request $request): bool
    {
        $marker = $request->attributes->get(self::MARKER);
        if (! is_string($marker)) {
            return false;
        }
        $body = $request->getContent();

        return strlen($body) <= self::MAX_BODY_BYTES && hash_equals($marker, hash('sha256', $body));
    }

    private static function headerIsBounded(SymfonyRequest $request, string $header): bool
    {
        $values = $request->headers->all($header);

        // Absence still receives a body budget; the signature verifier refuses it after capture.
        return $values === [] || (count($values) === 1 && strlen($values[0]) <= self::MAX_HEADER_BYTES
            && substr_count($values[0], ',') < self::MAX_SIGNATURE_PARTS);
    }

    private static function appliesTo(SymfonyRequest $request, string $suffix): bool
    {
        return str_ends_with(rawurldecode(rtrim($request->getPathInfo(), '/')), $suffix);
    }

    private static function refused(int $status): Response
    {
        return new Response($status === 413 ? 'Payload too large' : 'Bad request', $status, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
