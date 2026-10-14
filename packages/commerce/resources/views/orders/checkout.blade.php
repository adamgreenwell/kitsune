{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('checkout') }}</h1>
    <p>{{ $t('intro') }}</p>
    @if ($error !== null)<div class="summary" role="alert" tabindex="-1" autofocus>{{ $t($error) }}</div>@endif
    <form method="post" action="{{ $cartPath }}/quote" class="order-form">
        @csrf
        <p><label for="order-email">{{ $t('email') }}</label><input id="order-email" name="email" type="email" maxlength="255" autocomplete="email" required></p>
        @foreach (['billing', 'delivery'] as $kind)
            @if ($kind === 'delivery')
                <p><label><input type="checkbox" name="different_delivery" value="1"> {{ $t('different_delivery') }}</label></p>
                <p>{{ $t('delivery_help') }}</p>
            @endif
            <fieldset><legend>{{ $t($kind) }}</legend>
                @foreach (['name' => 200, 'line1' => 200, 'line2' => 200, 'city' => 100, 'region' => 32, 'postal_code' => 20, 'country' => 2] as $part => $length)
                    <p><label for="{{ $kind }}-{{ $part }}">{{ $t($part === 'region' ? 'region_label' : $part) }}</label>
                        <input id="{{ $kind }}-{{ $part }}" name="{{ $kind }}_{{ $part }}" type="text" maxlength="{{ $length }}" @if ($kind === 'billing' && in_array($part, ['name','line1','country'], true)) required @endif @if ($part === 'country') pattern="[A-Z]{2}" @endif autocomplete="{{ $kind === 'delivery' ? 'shipping' : 'billing' }} {{ match($part) {'name'=>'name','line1'=>'address-line1','line2'=>'address-line2','city'=>'address-level2','region'=>'address-level1','postal_code'=>'postal-code','country'=>'country'} }}">
                    </p>
                @endforeach
            </fieldset>
        @endforeach
        <button type="submit">{{ $t('review') }}</button>
    </form>
    <p><a href="{{ $cartPath }}">{{ $t('back') }}</a></p>
@endsection
