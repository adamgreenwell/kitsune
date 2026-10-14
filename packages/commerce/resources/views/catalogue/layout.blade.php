{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<!DOCTYPE html>
<html lang="{{ $htmlLang }}" dir="{{ $htmlDir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $siteName }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; background: #fafaf9; color: #1c1917; }
        a { color: inherit; text-underline-offset: .2em; }
        :focus-visible { outline: 3px solid; outline-offset: 3px; }
        header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; border-block-end: 1px solid #e7e5e4; }
        header > a { font-weight: 650; }
        header a, .pages a, .back a { display: inline-block; min-block-size: 2.75rem; padding-block: .625rem; }
        main { max-inline-size: 68rem; margin-inline: auto; padding: 2.5rem 1.5rem 3rem; overflow-wrap: anywhere; }
        h1 { margin-block: 0 .5rem; font-size: clamp(1.75rem, 5vw, 2.5rem); line-height: 1.2; letter-spacing: -.025em; }
        h2 { margin: 0; font-size: 1.25rem; line-height: 1.35; }
        p { margin-block: 0 1rem; }
        .intro, .description { color: #57534e; }
        .products { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; margin-block-start: 2rem; }
        article { min-inline-size: 0; border: 1px solid #d6d3d1; border-radius: .5rem; overflow: hidden; background: #fff; }
        article img { display: block; inline-size: 100%; aspect-ratio: 4/3; object-fit: cover; background: #f5f5f4; }
        .content { padding: 1.25rem; }
        .description { margin-block: .75rem 1rem; }
        .price { margin-block: 1rem .75rem; font-size: 1.125rem; font-weight: 650; }
        .badges { display: flex; flex-wrap: wrap; gap: .5rem; }
        .badge { display: inline-block; padding: .2rem .625rem; border-radius: .25rem; background: #f5f5f4; font-size: .875rem; font-weight: 600; }
        .held { background: #dcfce7; color: #14532d; }
        .sold { background: #fef3c7; color: #78350f; }
        .empty { margin-block-start: 2rem; padding: 1.5rem; border: 1px solid #d6d3d1; border-radius: .5rem; }
        .empty p { margin-block: .75rem 0; }
        .pages { display: flex; justify-content: space-between; gap: 1rem; margin-block-start: 2rem; }
        .next { margin-inline-start: auto; }
        .back { margin-block-start: 2rem; }
        .skip { position: absolute; inset-inline-start: 1rem; inset-block-start: 0; transform: translateY(-120%); padding: .75rem 1rem; background: #fff; z-index: 1; }
        .skip:focus { transform: translateY(0); }
        .visually-hidden { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
        @media (min-width: 60rem) { .products { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 38rem) { .products { grid-template-columns: minmax(0, 1fr); } main { padding-block-start: 1.75rem; } }
        @media (prefers-color-scheme: dark) {
            body { background: #1c1917; color: #fafaf9; }
            header { border-color: #44403c; }
            article { background: #292524; border-color: #57534e; }
            article img, .badge { background: #44403c; }
            .intro, .description { color: #d6d3d1; }
            .held { background: #052e16; color: #dcfce7; }
            .sold { background: #451a03; color: #fef3c7; }
            .empty { border-color: #57534e; }
            .skip { background: #292524; }
        }
    </style>
</head>
<body>
    <a class="skip" href="#catalogue" lang="{{ $copyLang }}" dir="{{ $copyDir }}">{{ $t('skip') }}</a>
    <header>
        <a href="{{ $homePath }}" dir="auto">{{ $siteName }}</a>
        @if ($accountPath !== null)
            <nav aria-label="{{ $t('navigation') }}" lang="{{ $copyLang }}" dir="{{ $copyDir }}"><a href="{{ $accountPath }}">{{ $t('account') }}</a></nav>
        @endif
    </header>
    <main id="catalogue" lang="{{ $copyLang }}" dir="{{ $copyDir }}">
        @yield('content')
    </main>
</body>
</html>
