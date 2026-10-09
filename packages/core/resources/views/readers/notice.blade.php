{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    A page that says one thing and offers nothing to submit — a 429, a used-up link's 410, the 503 of a site that cannot
    send mail — and, where there is somewhere to go next, one link.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>

    <p role="alert">{{ $t($message, $replace ?? []) }}</p>

    @isset($next)
        <p class="links"><a href="{{ $next[0] }}">{{ $t($next[1]) }}</a></p>
    @endisset
@endsection
