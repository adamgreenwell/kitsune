{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('review_title') }}</h1><p>{{ $t('quote_help') }}</p>
    <p>{{ $t($quote['snapshot']['test'] ? 'test_mode' : 'live_mode') }}</p>
    <ul>@foreach ($quote['snapshot']['lines'] as $line)<li dir="auto">{{ $line['title'] }} × {{ $line['quantity'] }} — <span dir="ltr">{{ $quote['snapshot']['currency'] }} {{ $money($line['gross_minor'], $quote['snapshot']['currency']) }}</span></li>@endforeach</ul>
    <p>{{ $t('gross') }}: {{ $quote['snapshot']['currency'] }} {{ $money($quote['snapshot']['itemGross'], $quote['snapshot']['currency']) }}</p>
    <p>{{ $t('tax') }}: {{ $quote['snapshot']['currency'] }} {{ $money($quote['snapshot']['itemTax'], $quote['snapshot']['currency']) }}</p>
    <form method="post" action="{{ $cartPath }}/orders">@csrf<input type="hidden" name="quote" value="{{ $quote['token'] }}">
        <fieldset><legend>{{ $t('shipping') }}</legend><p>{{ $t('shipping_help') }}</p>
        @foreach ($quote['rates'] as $rate)
            @php($shipping = $quote['snapshot']['parcels'] === [] ? ['gross'=>'0'] : ($quote['snapshot']['shippingTaxMode'] === 'inclusive' ? \Kitsune\Commerce\Taxes\TaxAmounts::inclusive($rate['amountMinor'], $quote['snapshot']['shippingRate']) : \Kitsune\Commerce\Taxes\TaxAmounts::exclusive($rate['amountMinor'], $quote['snapshot']['shippingRate'])))
            <p><label><input type="radio" name="rate" value="{{ $rate['id'] }}" required @checked($loop->first)> {{ $rate['id'] === 'none' ? $t('digital') : $rate['carrier'].' '.$rate['service'] }} — {{ $rate['currency'] }} {{ $money($shipping['gross'], $rate['currency']) }}</label></p>
            <p>{{ $t('total') }}: {{ $rate['currency'] }} {{ $money(\Kitsune\Commerce\Carts\CartAmounts::add($quote['snapshot']['itemGross'], $shipping['gross']), $rate['currency']) }}</p>
        @endforeach</fieldset>
        <button type="submit">{{ $t('prepare') }}</button>
    </form><p><a href="{{ $cartPath }}/checkout">{{ $t('checkout') }}</a></p>
@endsection
