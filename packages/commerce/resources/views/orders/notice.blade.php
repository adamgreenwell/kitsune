{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('title') }}</h1>
    <p role="status">{{ $t($message) }}</p>
    @if (($message ?? null) === 'link_invalid' && isset($reference))
        <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/link">@csrf<button type="submit" class="secondary">{{ $t('send_link') }}</button></form>
    @endif
    <p><a href="{{ $cartPath }}">{{ $t('back') }}</a></p>
@endsection
