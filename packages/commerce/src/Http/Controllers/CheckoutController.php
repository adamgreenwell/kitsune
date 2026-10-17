<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Http\Middleware\OrderResponse;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\FreeOrders;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\CheckoutMessages;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\CheckoutWriter;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Native body-only checkout mutations. Provider URLs are authorized redirects, never HTML or flash data. @internal */
final class CheckoutController
{
    public function __construct(private readonly Context $context) {}

    public function show(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->page($request));
    }

    /** Stripe return parameters do not authorize a read, reconcile an attempt or change order state. */
    public function returned(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->page($request, notice: 'returned'));
    }

    /** The provider's Back link does not expire a Session or cancel an order. */
    public function cancelled(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->page($request, notice: 'back_from_stripe'));
    }

    public function review(Request $request): Response|RedirectResponse
    {
        return $this->safe(function () use ($request): Response {
            if (! $this->csrf($request)) {
                return $this->page($request, error: 'session_expired', status: 419);
            }
            try {
                $review = app(CheckoutReview::class)->review($request, $this->reference($request));

                return $this->view(['order' => $review['order'], 'reference' => $this->reference($request), 'review' => $review]);
            } catch (OrderRefused|CheckoutRefused $failure) {
                return $this->page($request, error: CheckoutMessages::key($failure->reason), status: 422);
            }
        });
    }

    public function start(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'start');
    }

    public function completeFree(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'completeFree');
    }

    public function refresh(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'refresh');
    }

    public function expire(Request $request): Response|RedirectResponse
    {
        return $this->mutate($request, 'expire');
    }

    private function mutate(Request $request, string $action): Response|RedirectResponse
    {
        return $this->safe(function () use ($request, $action): Response|RedirectResponse {
            if (! $this->csrf($request)) {
                return $this->page($request, error: 'session_expired', status: 419);
            }
            $reference = $this->reference($request);
            $input = $request->attributes->get(OrderResponse::INPUT, []);
            $input = is_array($input) ? $input : [];
            try {
                $writer = app(CheckoutWriter::class);
                $result = match ($action) {
                    'completeFree' => app(FreeOrders::class)->complete($request, $reference, $input['review'] ?? null),
                    'start' => $writer->start($request, $reference, $input['review'] ?? null),
                    'refresh' => $writer->refresh($request, $reference, $input['attempt'] ?? null),
                    'expire' => $writer->expire($request, $reference, $input['attempt'] ?? null),
                    default => throw new CheckoutRefused,
                };
                if ($action !== 'expire' && $result['state'] === 'open' && $result['url'] !== null) {
                    $url = parse_url($result['url']);
                    if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'checkout.stripe.com'
                        || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
                        throw new CheckoutRefused;
                    }

                    // Symfony's default redirect body repeats Location in HTML, including the private Session URL.
                    return (new RedirectResponse($result['url'], 303))->setContent('');
                }

                return new RedirectResponse($this->path().'/orders/'.$reference.'/payment', 303);
            } catch (CheckoutRefused|OrderRefused $failure) {
                return $this->page($request, error: CheckoutMessages::key($failure->reason), status: 422);
            }
        });
    }

    private function page(Request $request, ?string $error = null, ?string $notice = null, int $status = 200): Response
    {
        $reference = $this->reference($request);
        $order = app(OrderAccess::class)->owned($request, $reference) ?? abort(404);
        $record = app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes());

        return $this->view(['order' => $record, 'reference' => $reference, 'error' => $error, 'notice' => $notice], $status);
    }

    /** @param Closure(): (Response|RedirectResponse) $work */
    private function safe(Closure $work): Response|RedirectResponse
    {
        try {
            return $work();
        } catch (HttpExceptionInterface $failure) {
            return response('Not found', $failure->getStatusCode());
        } catch (Throwable) {
            try {
                return $this->view(['error' => 'unavailable'], 503);
            } catch (Throwable) {
                return response('Checkout unavailable. Please try again shortly.', 503);
            }
        }
    }

    private function reference(Request $request): string
    {
        $reference = $request->route('reference');

        return is_string($reference) && preg_match('/\A[a-f0-9]{32}\z/D', $reference) === 1 ? $reference : abort(404);
    }

    private function csrf(Request $request): bool
    {
        return $request->attributes->get(OrderResponse::TOKEN) === true;
    }

    private function path(): string
    {
        $site = $this->context->site();

        return $site === null ? '/cart' : CartRoutes::path($site);
    }

    /** @param array<string,mixed> $data */
    private function view(array $data, int $status = 200): Response
    {
        $site = $this->context->site();
        $locale = $site->locale ?? 'en';

        return response()->view('commerce::payments.checkout', [...$data, 'error' => $data['error'] ?? null,
            'notice' => $data['notice'] ?? null, 'review' => $data['review'] ?? null,
            't' => static fn (string $key): string => (string) __('commerce::payments.'.$key, [], 'en'),
            'money' => MinorUnits::format(...), 'title' => __('commerce::payments.title', [], 'en'), 'mainId' => 'payment',
            'siteName' => $site->name ?? __('commerce::payments.title', [], 'en'),
            'homePath' => ($site->path_prefix ?? '') === '' ? '/' : $site->path_prefix, 'cartPath' => $this->path(), 'accountPath' => null,
            'htmlLang' => str_replace('_', '-', $locale), 'htmlDir' => Kitsune::textDirection($locale), 'copyLang' => 'en', 'copyDir' => 'ltr'], $status);
    }
}
