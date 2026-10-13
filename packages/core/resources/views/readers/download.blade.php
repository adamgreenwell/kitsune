{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    A file offered to readers — `DownloadController::show()`, ADR-041 decision 3 (Adam, 2026-10-11): what the file is
    and one link to it. The title is the owner's, in any script: escaped, its heading's direction its own (`dir=auto`),
    and isolated (U+2068 … U+2069) so a title that opens in one direction cannot carry the rest of the line with it.
    Its language is the site's (`$htmlLang`), not the copy's: the owner wrote it for this site, and `<main>` says only
    what language core's words were found in (`ReaderPage`). A title that is empty, or only space, is core's words
    instead (`download.untitled`), in the copy's language — never a heading of two invisible isolates.
    The page's `<title>` says "Download", not the file: a browser's history is no place to list what a reader bought.
--}}
@extends('kitsune::readers.layout')

@section('content')
    @if ($fileTitle !== null)
        <h1 lang="{{ $htmlLang }}" dir="auto">{{ "\u{2068}".$fileTitle."\u{2069}" }}</h1>
    @else
        <h1>{{ $t('download.untitled') }}</h1>
    @endif

    <p>{{ $t('download.includes', ['format' => $format, 'size' => $size]) }}</p>

    <p class="links"><a href="{{ $href }}">{{ $t($action) }}</a></p>
@endsection
