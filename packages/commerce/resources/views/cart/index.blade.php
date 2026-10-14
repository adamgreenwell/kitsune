{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('title') }}</h1>
    <p class="intro">{{ $t('intro') }}</p>
    @if ($error !== null)
        <div class="summary" role="alert" tabindex="-1" autofocus>
            <h2>{{ $t('summary_title') }}</h2>
            <p>{{ $t($error) }}</p>
        </div>
    @endif
    @if ($cart['mergeBlocked'])
        <section class="summary" role="status">
            <h2>{{ $t('merge_title') }}</h2>
            <p>{{ $t('merge_help') }}</p>
            <a href="{{ $cartPath }}">{{ $t('retry_merge') }}</a>
        </section>
    @endif
    @if ($cart['items'] === [])
        <section class="empty">
            <h2>{{ $t('empty_title') }}</h2>
            <p>{{ $t('empty_help') }}</p>
        </section>
    @else
        <div class="cart-items">
            @foreach ($cart['items'] as $item)
                <article class="cart-item">
                    @if ($item['available'] && $item['imageUrl'] !== null)
                        <img src="{{ $item['imageUrl'] }}" alt="{{ $item['title'] }}" lang="{{ $htmlLang }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                    @endif
                    <div class="content">
                        <h2 lang="{{ $item['title'] === '' ? $copyLang : $htmlLang }}" dir="auto">{{ $item['title'] === '' ? $t('unavailable_product') : $item['title'] }}</h2>
                        @if ($item['available'])
                            <p class="price">{{ $t('unit_price') }}: <span dir="ltr">{{ $item['currency'] }} {{ $item['price'] }}</span></p>
                            <p>{{ $t('line_total') }}: <span dir="ltr">{{ $item['currency'] }} {{ $item['lineTotal'] }}</span></p>
                            <form method="post" action="{{ $cartPath }}/update" class="quantity-form">
                                @csrf
                                <input type="hidden" name="line" value="{{ $item['lineId'] }}">
                                <input type="hidden" name="version" value="{{ $cart['version'] }}">
                                <label for="quantity-{{ $item['lineId'] }}">{{ $t('quantity_for', ['title' => $item['title']]) }}</label>
                                <div class="quantity-controls">
                                    <input id="quantity-{{ $item['lineId'] }}" type="number" name="quantity" value="{{ $item['quantity'] }}" min="1" max="99" step="1" required inputmode="numeric" autocomplete="off" dir="ltr">
                                    <button type="submit">{{ $t('update') }}</button>
                                </div>
                            </form>
                        @else
                            <p class="unavailable-item">{{ $t('unavailable_item') }}</p>
                            <p>{{ $t('quantity') }}: <span dir="ltr">{{ $item['quantity'] }}</span></p>
                        @endif
                        <form method="post" action="{{ $cartPath }}/remove" class="remove-form">
                            @csrf
                            <input type="hidden" name="line" value="{{ $item['lineId'] }}">
                            <input type="hidden" name="version" value="{{ $cart['version'] }}">
                            <button type="submit" class="secondary">{{ $t('remove', ['title' => $item['title'] === '' ? $t('unavailable_product') : $item['title']]) }}</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
        <section class="cart-total" aria-label="{{ $t('total') }}">
            @if ($cart['currency'] !== null && $cart['total'] !== null && ! collect($cart['items'])->contains(fn ($item) => ! $item['available']))
                <h2>{{ $t('total') }}: <span dir="ltr">{{ $cart['currency'] }} {{ $cart['total'] }}</span></h2>
            @else
                <p>{{ $t('total_unavailable') }}</p>
            @endif
        </section>
    @endif
    @if ($cataloguePath !== null)
        <p class="back"><a href="{{ $cataloguePath }}">{{ $t('continue') }}</a></p>
    @endif
@endsection
