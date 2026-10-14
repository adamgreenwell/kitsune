{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('commerce::catalogue.layout')

@section('content')
    <h1>{{ $t('unavailable_title') }}</h1>
    <p>{{ $t('unavailable_help') }}</p>
    <p class="back"><a href="{{ $homePath }}">{{ $t('home') }}</a></p>
@endsection
