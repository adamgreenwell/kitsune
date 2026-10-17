{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('title') }}</h1>
    @if (isset($order) && $order['quoted_total_minor'] !== '0')<p>{{ $t('mode_'.$order['mode']) }}</p>@endif
    @if ($error !== null)<p role="alert">{{ $t($error) }}</p>@endif
    @if ($notice !== null)<p role="status">{{ $t($notice) }}</p>@endif
    @if (isset($order))
        @php($attempts = $order['checkout_attempts'])
        @php($pending = collect($attempts)->contains(fn ($attempt) => in_array($attempt['state'], ['creating', 'open', 'uncertain'], true)))
        @php($completed = collect($attempts)->contains(fn ($attempt) => $attempt['state'] === 'completed'))
        @if ($review !== null)
            <section class="summary" aria-labelledby="review-heading">
                <h2 id="review-heading">{{ $t('total') }}: <span dir="ltr">{{ $order['currency'] }} {{ $money($order['quoted_total_minor'], $order['currency']) }}</span></h2>
                <ul>@foreach ($order['lines'] as $line)<li dir="auto">{{ $line['title'] }} × {{ $line['quantity'] }} — <span dir="ltr">{{ $order['currency'] }} {{ $money($line['gross_minor'], $order['currency']) }}</span>@php($offer = \Kitsune\Commerce\Offers\OfferTerms::label($line['offer_terms'] ?? null, ($line['entitlement_name'] ?? null) !== null))@if ($offer !== null)<p>{{ $offer }}</p>@endif</li>@endforeach</ul>
                @if ($order['shipping'] !== null)<p>{{ $order['shipping']['carrier'] }} {{ $order['shipping']['service'] }} — {{ $order['currency'] }} {{ $money($order['shipping']['gross'], $order['currency']) }}</p>@endif
                <p>{{ $order['quoted_total_minor'] === '0' ? __('commerce::free.commitment', [], 'en') : $t('commitment') }}</p>
                <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/payment/{{ $order['quoted_total_minor'] === '0' ? 'complete-free' : 'start' }}">@csrf
                    <input type="hidden" name="review" value="{{ $review['token'] }}">
                    <button type="submit">{{ $order['quoted_total_minor'] === '0' ? __('commerce::free.complete', [], 'en') : $t('continue') }}</button>
                </form>
            </section>
        @elseif ($order['state'] === 'prepared' && ! $pending && ! $completed)
            <p>{{ $t('review_help') }}</p>
            <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/payment/review">@csrf<button type="submit">{{ $t('review') }}</button></form>
        @endif
        @include('commerce::orders.payment-summary')
        @if ($completed && $order['payment'] === null)<p role="status">{{ $t('awaiting_confirmation') }}</p>@endif
        @if ($attempts !== [])
            <section class="summary" aria-labelledby="history-heading">
                <h2 id="history-heading">{{ $t('history') }}</h2>
                @foreach ($attempts as $attempt)
                    <article class="content">
                        <dl><dt>{{ $t('attempt') }}</dt><dd dir="ltr">{{ $attempt['reference'] }}</dd>
                            <dt>{{ $t('state') }}</dt><dd>{{ $t($attempt['state']) }}</dd>
                            <dt>{{ $t('total') }}</dt><dd dir="ltr">{{ $attempt['currency'] }} {{ $money($attempt['total_minor'], $attempt['currency']) }}</dd>
                            <dt>{{ $t('expires') }}</dt><dd dir="ltr">{{ $attempt['expires_at'] }}</dd></dl>
                        @if (in_array($attempt['state'], ['creating', 'open', 'uncertain'], true))
                            <p>{{ $t('pending_help') }}</p>
                            <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/payment/refresh">@csrf<input type="hidden" name="attempt" value="{{ $attempt['reference'] }}"><button type="submit">{{ $t('resume') }}</button></form>
                            <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/payment/expire">@csrf<input type="hidden" name="attempt" value="{{ $attempt['reference'] }}"><button class="secondary" type="submit">{{ $t('expire') }}</button></form>
                        @endif
                    </article>
                @endforeach
            </section>
        @endif
        <p class="back"><a href="{{ $cartPath }}/orders/{{ $reference }}">{{ $t('order') }}</a></p>
    @endif
@endsection
