{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Sign in — `SignInController`. A refusal re-renders this in place: the summary takes focus, each message is linked to
    its field, and the address is re-filled from the request, never the session. The password is never echoed.
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

    @if ($status !== null)
        <p class="status" role="status">{{ $t($status) }}</p>
    @endif

    <form method="post" action="{{ $action }}" novalidate>
        @csrf

        @foreach (['email' => ['email', 'email'], 'password' => ['password', 'current-password']] as $field => [$type, $autocomplete])
            <div class="field">
                <label for="{{ $field }}">{{ $t('sign_in.'.$field) }}</label>
                @isset($problems[$field])
                    <p class="error" id="{{ $field }}-error"><span class="visually-hidden">{{ $t('title.error_prefix') }}</span> {{ $t($problems[$field][0], $problems[$field][1]) }}</p>
                @endisset
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}"
                    @if ($field === 'email') value="{{ $email }}" inputmode="email" dir="ltr" autocapitalize="none" spellcheck="false" @endif
                    @isset($problems[$field]) aria-invalid="true" aria-describedby="{{ $field }}-error" @endisset>
            </div>
        @endforeach

        <button type="submit">{{ $t('sign_in.button') }}</button>
    </form>
@endsection
