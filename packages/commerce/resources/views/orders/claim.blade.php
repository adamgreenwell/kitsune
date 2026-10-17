{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('claim_title') }}</h1>
    <p>{{ $t('reference') }}: <span dir="ltr">{{ $reference }}</span></p>
    <p>{{ $freeOrder ? __('commerce::free.claim_terms', [], 'en') : $t('claim_terms') }}</p>
    @if ($readerEmail !== null)
        <p>{{ $t('claim_permanent') }}</p>
        <p>{{ $t('claim_account') }}: <strong dir="auto">{{ $readerEmail }}</strong></p>
        <form method="post" action="{{ $claimPath }}">@csrf<button type="submit">{{ $t('claim_confirm') }}</button></form>
    @else
        <p>{{ $t('claim_help') }}</p>
        <p><a href="{{ $signInPath }}">{{ $t('claim_sign_in') }}</a></p>
        @if ($registerPath !== null)<p><a href="{{ $registerPath }}">{{ $t('claim_register') }}</a></p>@endif
    @endif
    <p><a href="{{ $cartPath }}/orders/{{ $reference }}">{{ $t('title') }}</a></p>
@endsection
