{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    After asking for a link — the same words whatever happened to the address, so they say nothing about it.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>

    <p class="status" role="status">{{ $t($message, ['minutes' => $minutes, 'gap' => $gap]) }}</p>

    <p class="links"><a href="{{ $again }}">{{ $t('link.again') }}</a></p>
@endsection
