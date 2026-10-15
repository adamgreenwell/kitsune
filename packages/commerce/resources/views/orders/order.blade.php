{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('title') }}</h1>
    <dl><dt>{{ $t('reference') }}</dt><dd dir="ltr">{{ $order['reference'] }}</dd><dt>{{ $t('state') }}</dt><dd>{{ $t($order['state']) }}</dd></dl>
    <p>{{ $t($order['mode'].'_mode') }}</p>
    <p>{{ $t('unpaid') }}</p>
    <ul>@foreach ($order['lines'] as $line)<li dir="auto">{{ $line['title'] }} × {{ $line['quantity'] }} — <span dir="ltr">{{ $order['currency'] }} {{ $money($line['gross_minor'], $order['currency']) }}</span></li>@endforeach</ul>
    <p>{{ $t('net') }}: {{ $order['currency'] }} {{ $money($order['quoted_item_net_minor'], $order['currency']) }}</p>
    <p>{{ $t('tax') }}: {{ $order['currency'] }} {{ $money($order['quoted_tax_minor'], $order['currency']) }}</p>
    <h2>{{ $t('total') }}: <span dir="ltr">{{ $order['currency'] }} {{ $money($order['quoted_total_minor'], $order['currency']) }}</span></h2>
    @if ($order['mode'] === 'test')<p><a href="{{ $cartPath }}/orders/{{ $reference }}/payment">{{ __('commerce::payments.title', [], 'en') }}</a></p>@endif
    <p>{{ $t('email') }}: {{ $order['contact_email'] }}</p>
    @foreach (['billing', 'delivery'] as $kind)
        @if ($order[$kind] !== null)<section><h2>{{ $t($kind) }}</h2><p dir="auto">{{ $order[$kind]['name'] }}<br>@foreach ($order[$kind]['address'] as $part)@if ($part !== ''){{ $part }}<br>@endif @endforeach</p></section>@endif
    @endforeach
    @if ($order['state'] === 'prepared')<form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/cancel">@csrf<button type="submit" class="secondary">{{ $t('cancel') }}</button></form>@endif
    @if ($isGuest)<form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/link">@csrf<button type="submit" class="secondary">{{ $t('send_link') }}</button></form>@endif
    <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/export">@csrf<button type="submit" class="secondary">{{ $t('export') }}</button></form>
    <p>{{ $t('erase_help') }}</p><form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/erase">@csrf<button type="submit" class="secondary">{{ $t('erase') }}</button></form>
    <p><a href="{{ $cartPath }}/orders">{{ $t('orders') }}</a></p>
@endsection
