{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $t('privacy.intro', ['site' => $siteName]) }}</p>

    @if ($saved)
        <p class="status" role="status">{{ $t('privacy.saved') }}</p>
    @endif
    @if ($globalPrivacyControl)
        <p>{{ $t('privacy.gpc') }}</p>
    @endif

    @foreach ($choices as $choice)
        <section>
            <h2>{{ $t('privacy.'.$choice['purpose']) }}</h2>
            <p>{{ $t('privacy.'.$choice['purpose'].'_description') }}</p>
            <p>{{ $t($choice['effective'] ? 'privacy.enabled' : 'privacy.declined') }}</p>
            @if ($choice['policy'] === null)
                <p>{{ $t('privacy.unavailable') }}</p>
            @elseif (! $choice['effective'] && ! $globalPrivacyControl)
                <p>{{ $t('privacy.expiry', ['days' => $choice['policy']['retention_days']]) }}</p>
            @endif
            @if ((! $choice['effective'] && ! $globalPrivacyControl && $choice['policy'] !== null) || $choice['saved'])
                <form method="post" action="{{ $action }}">
                    @csrf
                    <input type="hidden" name="purpose" value="{{ $choice['purpose'] }}">
                    @if (! $choice['effective'] && ! $globalPrivacyControl && $choice['policy'] !== null)
                        <input type="hidden" name="notice" value="{{ $choice['policy']['notice_version'] }}">
                        <button type="submit" name="decision" value="grant">{{ $t('privacy.grant') }}</button>
                    @endif
                    @if ($choice['saved'])
                        <button type="submit" name="decision" value="withdraw">{{ $t('privacy.withdraw') }}</button>
                    @endif
                </form>
            @endif
        </section>
    @endforeach

    <p><a href="{{ $account }}">{{ $t('privacy.back') }}</a></p>
@endsection
