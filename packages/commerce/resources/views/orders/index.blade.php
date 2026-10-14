{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('orders') }}</h1>
    @if ($orders === [])<p>{{ $t('empty') }}</p>@endif
    <ul>@foreach ($orders as $order)<li><a href="{{ $cartPath }}/orders/{{ $order['reference'] }}">{{ $order['reference'] }}</a> — {{ $t($order['state']) }} — {{ $order['currency'] }} {{ $money($order['quoted_total_minor'], $order['currency']) }}</li>@endforeach</ul>
    <nav class="pages" aria-label="{{ $t('orders') }}">@if ($page > 1)<a href="{{ $cartPath }}/orders?page={{ $page - 1 }}">{{ $t('previous') }}</a>@endif @if ($hasNext)<a href="{{ $cartPath }}/orders?page={{ $page + 1 }}">{{ $t('next') }}</a>@endif</nav>
    <p><a href="{{ $cartPath }}">{{ $t('back') }}</a></p>
@endsection
