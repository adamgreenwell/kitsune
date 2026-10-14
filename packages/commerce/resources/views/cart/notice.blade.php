{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $t($message) }}</p>
    @if ($cartPath !== null)
        <p class="back"><a href="{{ $cartPath }}">{{ $t('reload') }}</a></p>
    @endif
@endsection
