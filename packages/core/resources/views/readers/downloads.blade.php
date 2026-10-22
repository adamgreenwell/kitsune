{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Authorized current offers only. No totals, grant names, storage paths or direct-byte URLs.
--}}
@extends('kitsune::readers.layout')

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $t('downloads.intro') }}</p>

    @if ($offers === [])
        <p>{{ $t('downloads.empty') }}</p>
    @else
        <ul>
            @foreach ($offers as $offer)
                <li><a href="{{ $offer['href'] }}"><bdi>{{ \Illuminate\Support\Str::trim($offer['title']) !== '' ? $offer['title'] : $t('download.untitled') }}</bdi></a></li>
            @endforeach
        </ul>
    @endif

    @if ($previous !== null || $next !== null)
        <nav aria-label="{{ $t('downloads.pages') }}">
            @if ($previous !== null)
                <a href="{{ $previous }}" rel="prev">{{ $t('downloads.previous') }}</a>
            @endif
            @if ($next !== null)
                <a href="{{ $next }}" rel="next">{{ $t('downloads.next') }}</a>
            @endif
        </nav>
    @endif

    <p><a href="{{ $account }}">{{ $t('home.title') }}</a></p>
@endsection
