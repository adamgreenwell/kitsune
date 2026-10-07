{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    A page that says one thing and offers nothing to submit — a 429 today; the 410 and 503 of sign-up and recovery later.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>

    <p role="alert">{{ $t($message) }}</p>
@endsection
