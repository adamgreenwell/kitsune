<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Controllers;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Http\Middleware\OrderResponse;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\CompletionFacts;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Plain HTML checkout and exact-order proof; invoice fields never enter a redirect, flash bag or public model JSON. @internal */
final class OrderController
{
    public function __construct(private readonly Context $context) {}

    public function index(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->indexPage($request));
    }

    public function checkout(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->checkoutPage());
    }

    public function quote(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->quotePage($request));
    }

    public function prepare(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->preparePage($request));
    }

    public function show(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->showPage($request));
    }

    public function retryAccess(Request $request): Response|RedirectResponse
    {
        return $this->safe(function () use ($request): Response|RedirectResponse {
            if (! $this->csrf($request)) {
                return $this->view('notice', ['message' => 'session_expired'], 419);
            }
            $reference = $this->reference($request);
            abort_if(app(OrderAccess::class)->owned($request, $reference) === null, 404);
            try {
                app(AccessFulfillment::class)->reconcileReader($request, $reference);
            } catch (Throwable) {
                return $this->view('notice', ['message' => 'claim_unavailable'], 422);
            }

            return new RedirectResponse($this->path().'/orders/'.$reference, 303);
        });
    }

    public function claimForm(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->claimFormPage($request));
    }

    public function claim(Request $request): Response|RedirectResponse
    {
        return $this->safe(function () use ($request): Response|RedirectResponse {
            if (! $this->csrf($request)) {
                return $this->view('notice', ['message' => 'session_expired'], 419);
            }
            $reference = $this->reference($request);
            try {
                $order = app(OrderClaims::class)->claim($request, $reference);
                $order->getConnection()->afterCommit(static function () use ($request, $reference): void {
                    try {
                        app(AccessFulfillment::class)->reconcileClaim($request, $reference);
                    } catch (Throwable) {
                        // The durable claim and pending access work already committed; recovery can resume it.
                    }
                });

                return new RedirectResponse($this->path().'/orders/'.$reference, 303);
            } catch (OrderRefused) {
                return $this->view('notice', ['message' => 'claim_unavailable'], 422);
            }
        });
    }

    public function access(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->accessPage($request));
    }

    public function consume(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->consumePage($request));
    }

    public function link(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->linkPage($request));
    }

    public function cancel(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->cancelPage($request));
    }

    public function export(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->exportPage($request));
    }

    public function erase(Request $request): Response|RedirectResponse
    {
        return $this->safe(fn () => $this->erasePage($request));
    }

    private function indexPage(Request $request): Response
    {
        $page = $this->input($request)['page'] ?? '1';
        if (! is_string($page) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $page) !== 1) {
            abort(404);
        }
        $owner = CartOwner::current($request);
        $records = [];
        $hasNext = false;
        if ($owner !== null) {
            $rows = Order::query()->useWritePdo()->where(function ($owned) use ($owner): void {
                $owned->where('owner_key', $owner->key);
                if ($owner->reader !== null) {
                    $owned->orWhereExists(fn (Builder $claim): Builder => $claim->selectRaw('1')->from(OrderClaims::TABLE)
                        ->whereColumn(OrderClaims::TABLE.'.order_id', 'commerce_orders.id')->whereColumn(OrderClaims::TABLE.'.org_id', 'commerce_orders.org_id')
                        ->whereColumn(OrderClaims::TABLE.'.site_id', 'commerce_orders.site_id')->where(OrderClaims::TABLE.'.reader_id', $owner->reader));
                }
            })->orderByDesc('id')
                ->offset(((int) $page - 1) * 24)->limit(25)->get();
            $hasNext = $rows->count() > 24;
            foreach ($rows->take(24) as $row) {
                $owned = app(OrderAccess::class)->owned($request, $row->getAttributes()['reference']);
                if ($owned === null) {
                    continue;
                }
                $records[] = app(OrderReaderData::class)->record($owned->getConnection(), (object) $owned->getAttributes());
            }
        }

        return $this->view('index', ['orders' => $records, 'page' => (int) $page, 'hasNext' => $hasNext, 'money' => MinorUnits::format(...)]);
    }

    private function checkoutPage(): Response
    {
        return $this->view('checkout');
    }

    private function quotePage(Request $request): Response
    {
        if (! $this->csrf($request)) {
            return $this->view('checkout', ['error' => 'session_expired'], 419);
        }
        $input = $this->input($request);
        $billing = $this->address($input, 'billing');
        $details = ['email' => $input['email'] ?? null, 'billing' => $billing,
            'delivery' => ($input['different_delivery'] ?? null) === '1' ? $this->address($input, 'delivery') : null];
        try {
            $quote = app(OrderQuotes::class)->quote($request, $details);

            return $this->view('quote', ['quote' => $quote, 'money' => MinorUnits::format(...)]);
        } catch (OrderRefused $failure) {
            $reason = in_array($failure->reason, ['invalid', 'setup', 'mail', 'region', 'tax', 'stale'], true) ? $failure->reason : 'unavailable';

            return $this->view('checkout', ['error' => $reason], 422);
        }
    }

    private function preparePage(Request $request): Response|RedirectResponse
    {
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }
        $input = $this->input($request);
        try {
            $order = app(OrderWriter::class)->prepare($request, $input['quote'] ?? null, $input['rate'] ?? null);

            return new RedirectResponse($this->path().'/orders/'.$order->getAttributes()['reference'], 303);
        } catch (OrderRefused $failure) {
            $reason = in_array($failure->reason, ['expired', 'stale', 'existing', 'mail'], true) ? $failure->reason : 'unavailable';

            return $this->view('notice', ['message' => $reason], 422);
        }
    }

    private function showPage(Request $request): Response
    {
        $reference = $this->reference($request);
        $order = app(OrderAccess::class)->owned($request, $reference) ?? abort(404);
        $record = app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes());

        $claim = app(OrderClaims::class)->forOrder($order->getConnection(), (object) $order->getAttributes());
        $isGuest = $order->getAttributes()['reader_id'] === null && $claim === null;
        $site = $this->context->site();
        $canClaim = $isGuest && in_array($record['state'], ['paid', 'completed_free'], true) && $site !== null
            && app(ReaderAccounts::class)->fault() === null && app(ReaderAccounts::class)->mode($site)->signsIn();

        return $this->view('order', ['order' => $record, 'reference' => $reference, 'isGuest' => $isGuest,
            'claimPath' => $canClaim ? app(OrderClaims::class)->path($reference) : null,
            'accessRetryPath' => ! $isGuest && in_array($record['access_fulfillment']['state'] ?? null, ['pending', 'held'], true)
                ? $this->path().'/orders/'.$reference.'/access/retry' : null, 'money' => MinorUnits::format(...)]);
    }

    private function claimFormPage(Request $request): Response|RedirectResponse
    {
        $site = $this->context->site() ?? abort(404);
        $accounts = app(ReaderAccounts::class);
        abort_if($accounts->fault() !== null || ! $accounts->mode($site)->signsIn(), 404);
        $reference = $this->reference($request);
        $connection = (new Order)->getConnection();
        $claims = app(OrderClaims::class);
        $order = app(OrderReaderData::class)->find($connection, (int) $site->org_id, (int) $site->getKey(), $reference) ?? abort(404);
        app(OrderReaderData::class)->project($connection, (int) $site->org_id, $order);
        $claim = $claims->forOrder($connection, $order);
        if ($claim !== null) {
            abort_if(app(OrderAccess::class)->owned($request, $reference) === null, 404);

            return new RedirectResponse($this->path().'/orders/'.$reference, 303);
        }
        abort_if($order->reader_id !== null || ! CompletionFacts::completed($order) || app(OrderAccess::class)->guestProof($request, $order) === null, 404);
        $claims->remember($request, $reference);
        $reader = null;
        try {
            $reader = $claims->freshReader($request, $connection);
        } catch (OrderRefused) {
            // An unbound or expired reader session conveys no claim authority.
        }
        if ($reader === null) {
            $request->session()->put(RequireReader::INTENDED, $claims->path($reference));
        }

        return $this->view('claim', ['reference' => $reference, 'freeOrder' => $order->state === 'completed_free', 'claimPath' => $claims->path($reference),
            'readerEmail' => $reader?->readerEmail(), 'signInPath' => ReaderLinks::path($site, ReaderLinks::SIGN_IN),
            'registerPath' => $accounts->mode($site)->signsUp() ? ReaderLinks::path($site, ReaderLinks::REGISTER) : null]);
    }

    private function accessPage(Request $request): Response|RedirectResponse
    {
        $reference = $this->reference($request);
        $secret = $this->input($request)['secret'] ?? null;
        if (($this->input($request)['hasSecret'] ?? false) === true) {
            app(OrderAccess::class)->capture($request, $reference, $secret);

            return new RedirectResponse($this->path().'/orders/'.$reference.'/access', 303);
        }

        return $this->view('access', ['reference' => $reference]);
    }

    private function consumePage(Request $request): Response|RedirectResponse
    {
        $reference = $this->reference($request);
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }

        return app(OrderAccess::class)->consume($request, $reference)
            ? new RedirectResponse($this->path().'/orders/'.$reference, 303)
            : $this->view('notice', ['message' => 'link_invalid', 'reference' => $reference], 422);
    }

    private function linkPage(Request $request): Response
    {
        $reference = $this->reference($request);
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }
        if (ReaderThrottle::linkRequestRefusal($request->ip()) !== null) {
            return $this->view('notice', ['message' => 'throttled'], 429);
        }
        app(OrderAccess::class)->requestLink($request, $reference);

        return $this->view('notice', ['message' => 'link_sent']);
    }

    private function cancelPage(Request $request): Response|RedirectResponse
    {
        $reference = $this->reference($request);
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }
        try {
            app(OrderWriter::class)->cancel($request, $reference);

            return new RedirectResponse($this->path().'/orders/'.$reference, 303);
        } catch (OrderRefused) {
            return $this->view('notice', ['message' => 'unavailable'], 422);
        }
    }

    private function exportPage(Request $request): Response
    {
        $reference = $this->reference($request);
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }
        $data = app(OrderReaderData::class)->exportGuest($request, $reference);

        return response(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200,
            ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="kitsune-order.json"']);
    }

    private function erasePage(Request $request): Response|RedirectResponse
    {
        $reference = $this->reference($request);
        if (! $this->csrf($request)) {
            return $this->view('notice', ['message' => 'session_expired'], 419);
        }
        try {
            app(OrderReaderData::class)->eraseGuest($request, $reference);

            return new RedirectResponse($this->path(), 303);
        } catch (Throwable) {
            return $this->view('notice', ['message' => 'unavailable'], 422);
        }
    }

    public function unavailable(): Response
    {
        try {
            return $this->view('notice', ['message' => 'unavailable'], 503);
        } catch (Throwable) {
            return response('Order unavailable. Please try again shortly.', 503);
        }
    }

    /** @param Closure(): (Response|RedirectResponse) $work */
    private function safe(Closure $work): Response|RedirectResponse
    {
        try {
            return $work();
        } catch (HttpExceptionInterface $failure) {
            return response('Not found', $failure->getStatusCode());
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function address(#[\SensitiveParameter] array $input, string $prefix): array
    {
        $address = [];
        foreach (['line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $part) {
            $address[$part] = $input[$prefix.'_'.$part] ?? '';
        }

        return ['name' => $input[$prefix.'_name'] ?? null, 'address' => $address];
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $input = $request->attributes->get(OrderResponse::INPUT, []);

        return is_array($input) ? $input : [];
    }

    private function csrf(Request $request): bool
    {
        return $request->attributes->get(OrderResponse::TOKEN) === true;
    }

    private function reference(Request $request): string
    {
        $reference = $request->route('reference');

        return is_string($reference) && preg_match('/\A[a-f0-9]{32}\z/D', $reference) === 1 ? $reference : abort(404);
    }

    private function path(): string
    {
        $site = $this->context->site();

        return $site === null ? '/cart' : CartRoutes::path($site);
    }

    /** @param array<string,mixed> $data */
    private function view(string $view, array $data = [], int $status = 200): Response
    {
        $site = $this->context->site();
        $locale = $site->locale ?? 'en';

        return response()->view('commerce::orders.'.$view, [...$data, 'error' => $data['error'] ?? null,
            't' => static fn (string $key): string => (string) __('commerce::orders.'.$key, [], 'en'),
            'title' => __('commerce::orders.title', [], 'en'), 'mainId' => 'orders', 'siteName' => $site->name ?? __('commerce::orders.title', [], 'en'),
            'homePath' => ($site->path_prefix ?? '') === '' ? '/' : $site->path_prefix, 'cartPath' => $this->path(), 'accountPath' => null,
            'htmlLang' => str_replace('_', '-', $locale), 'htmlDir' => Kitsune::textDirection($locale), 'copyLang' => 'en', 'copyDir' => 'ltr'], $status);
    }
}
