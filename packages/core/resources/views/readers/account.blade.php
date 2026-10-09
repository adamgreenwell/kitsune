{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    The signed-in reader's page — `AccountController`. Who is signed in, as escaped text, and a way out; nothing else.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>

    @if ($status !== null)
        <p class="status" role="status">{{ $t($status) }}</p>
    @endif

    <p>{{ $t('home.signed_in_as', ['email' => $email]) }}</p>

    <form method="post" action="{{ $signOut }}">
        @csrf
        <button type="submit">{{ $t('sign_out.button') }}</button>
    </form>
@endsection
