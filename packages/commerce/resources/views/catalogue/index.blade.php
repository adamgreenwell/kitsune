{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('title') }}</h1>
    <p class="intro">{{ $t('intro') }}</p>
    @if ($catalogue['products'] === [])
        <section class="empty">
            <h2>{{ $t($catalogue['hasCandidates'] || $catalogue['page'] > 1 ? 'page_empty_title' : 'empty_title') }}</h2>
            <p>{{ $t($catalogue['hasCandidates'] || $catalogue['page'] > 1 ? 'page_empty_help' : 'empty_help') }}</p>
        </section>
    @else
        <div class="products">
            @foreach ($catalogue['products'] as $product)
                <article>
                    @if ($product['imageUrl'] !== null)
                        <img src="{{ $product['imageUrl'] }}" alt="{{ $product['title'] }}" lang="{{ $htmlLang }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                    @endif
                    <div class="content">
                        <h2 lang="{{ $htmlLang }}" dir="auto">{{ $product['title'] }}</h2>
                        @if ($product['description'] !== null)
                            <p class="description" lang="{{ $htmlLang }}" dir="auto">{{ $product['description'] }}</p>
                        @endif
                        <p class="price"><span class="visually-hidden">{{ $t('price') }} </span><span dir="ltr">{{ $product['currency'] }} {{ $product['price'] }}</span></p>
                        <div class="badges">
                            <span class="badge {{ $product['stock'] === '0' ? 'sold' : '' }}">{{ $t($product['stock'] === '0' ? 'sold_out' : 'available') }}</span>
                            @if ($product['held'] === true)
                                <span class="badge held">{{ $t('held') }}</span>
                            @endif
                        </div>
                        @if (($cartPath ?? null) !== null)
                            <form method="post" action="{{ $cartPath }}/add" class="add-form">
                                @csrf
                                <input type="hidden" name="listing" value="{{ $product['listingId'] }}">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" @disabled($product['stock'] === '0')>{{ $t('add_to_cart') }}</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
    @if ($previousPath !== null || $nextPath !== null)
        <nav class="pages" aria-label="{{ $t('pages') }}">
            @if ($previousPath !== null)
                <a href="{{ $previousPath }}" rel="prev">{{ $t('previous') }}</a>
            @endif
            @if ($nextPath !== null)
                <a class="next" href="{{ $nextPath }}" rel="next">{{ $t('next') }}</a>
            @endif
        </nav>
    @endif
@endsection
