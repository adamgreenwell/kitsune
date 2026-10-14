{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('access_title') }}</h1><p>{{ $t('access_help') }}</p>
    <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/access">@csrf<button type="submit">{{ $t('access') }}</button></form>
    <p>{{ $t('link_help') }}</p>
    <form method="post" action="{{ $cartPath }}/orders/{{ $reference }}/link">@csrf<button type="submit" class="secondary">{{ $t('send_link') }}</button></form>
@endsection
