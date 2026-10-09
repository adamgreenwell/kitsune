{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Choose a password, from a mailed link — `LinkUseController`, for a new account or a new password. Typed twice (Adam,
    2026-10-09: "passwords always need to be typed twice"), and never echoed. The address it is for is shown as escaped
    text, and again in a read-only field a password manager saves the password under; the server never reads that field.
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

    <p>{{ $t($for, ['email' => $email]) }}</p>

    <form method="post" action="{{ $action }}" novalidate>
        @csrf

        <div class="field">
            <label for="username">{{ $t('password.account') }}</label>
            <input id="username" name="username" type="email" autocomplete="username" value="{{ $email }}" readonly dir="ltr">
        </div>

        @foreach (['password' => 'password.new', 'password_confirmation' => 'password.confirm'] as $field => $label)
            <div class="field">
                <label for="{{ $field }}">{{ $t($label) }}</label>
                @if ($field === 'password')
                    <p class="hint" id="password-hint">{{ $t('password.hint', ['min' => $min]) }}</p>
                @endif
                @isset($problems[$field])
                    <p class="error" id="{{ $field }}-error"><span class="visually-hidden">{{ $t('title.error_prefix') }}</span> {{ $t($problems[$field][0], $problems[$field][1]) }}</p>
                @endisset
                @php($describedBy = trim(($field === 'password' ? 'password-hint ' : '').(isset($problems[$field]) ? $field.'-error' : '')))
                <input id="{{ $field }}" name="{{ $field }}" type="password" autocomplete="new-password"
                    @isset($problems[$field]) aria-invalid="true" @endisset
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
            </div>
        @endforeach

        <button type="submit">{{ $t($button) }}</button>
    </form>
@endsection
