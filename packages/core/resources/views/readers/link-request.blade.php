{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Ask for a link: create an account, or choose a new password — `LinkRequestController`. A refusal re-renders this in
    place, as sign-in does: the summary takes focus, its message is linked to the field, and the address is re-filled
    from the request, never the session.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>

    @if (! empty($problems))
        <div class="summary" role="alert" tabindex="-1" autofocus>
            <h2>{{ $t('errors.summary') }}</h2>
            <ul>
                @foreach ($problems as $field => [$key, $replace])
                    <li><a href="#{{ $field }}">{{ $t($key, $replace) }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <p>{{ $t($intro) }}</p>

    <form method="post" action="{{ $action }}" novalidate>
        @csrf

        <div class="field">
            <label for="email">{{ $t('sign_in.email') }}</label>
            @isset($problems['email'])
                <p class="error" id="email-error"><span class="visually-hidden">{{ $t('title.error_prefix') }}</span> {{ $t($problems['email'][0], $problems['email'][1]) }}</p>
            @endisset
            <input id="email" name="email" type="email" autocomplete="email" value="{{ $email }}" inputmode="email" dir="ltr"
                autocapitalize="none" spellcheck="false"
                @isset($problems['email']) aria-invalid="true" aria-describedby="email-error" @endisset>
        </div>

        <button type="submit">{{ $t('link.button') }}</button>
    </form>

    <p class="links"><a href="{{ $signIn }}">{{ $t('link.sign_in') }}</a></p>
@endsection
