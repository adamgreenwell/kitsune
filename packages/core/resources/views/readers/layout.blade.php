{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    A reader page's frame — ADR-037, as built. No theme layer yet (ADR-011): the welcome page's palette, inline styles,
    system fonts, no JavaScript and nothing off-host (ADR-027, ADR-020 #7), because `ReaderArea`'s policy allows inline
    styles and nothing else. Logical properties only, so a right-to-left page needs nothing of its own.

    `<html>` is the site's language and direction; `<main>` is the language the copy was found in (`ReaderPage`).
    No promise in 0.x: a host may override this through `resources/views/vendor/kitsune/readers/`.
--}}
<!DOCTYPE html>
<html lang="{{ $htmlLang }}" dir="{{ $htmlDir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ! empty($problems) ? $t('title.error_prefix').' ' : '' }}{{ $title }} — {{ $siteName }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #fafaf9; color: #1c1917;
        }
        header { padding-block: 1rem; padding-inline: 1.5rem; border-block-end: 1px solid #e7e5e4; font-weight: 600; }
        main { max-width: 30rem; margin-inline: auto; padding-block: 2rem 3rem; padding-inline: 1.5rem; overflow-wrap: anywhere; }
        h1 { margin-block: 0 1.25rem; font-size: 1.75rem; line-height: 1.25; letter-spacing: -.01em; }
        h2 { margin-block: 0 .5rem; font-size: 1.125rem; }
        p { margin-block: 0 1rem; }
        a { color: inherit; text-underline-offset: .2em; }
        .field { margin-block-end: 1.25rem; }
        label { display: block; margin-block-end: .25rem; font-weight: 600; }
        input[type=email], input[type=password] {
            display: block; inline-size: 100%; min-block-size: 2.75rem; padding: .5rem .75rem;
            font: inherit; color: inherit; background: #fff; border: 2px solid #57534e; border-radius: .25rem;
        }
        input[aria-invalid=true] { border-color: #b91c1c; }
        input[readonly] { background: #f5f5f4; border-style: dashed; }
        .hint { margin-block: 0 .25rem; color: #57534e; }
        .links { margin-block: 1.5rem 0; padding: 0; list-style: none; }
        .links a { display: inline-block; min-block-size: 2.75rem; padding-block: .625rem; }
        button {
            min-block-size: 2.75rem; min-inline-size: 2.75rem; padding: .5rem 1.25rem;
            font: inherit; font-weight: 600; color: #fafaf9; background: #1c1917;
            border: 2px solid #1c1917; border-radius: .25rem; cursor: pointer;
        }
        :focus-visible { outline: 3px solid; outline-offset: 2px; }
        .summary { margin-block-end: 1.5rem; padding: 1rem 1.25rem; border: 3px solid #b91c1c; border-radius: .25rem; }
        .summary:focus { outline: 3px solid; outline-offset: 2px; }
        .summary ul { margin: 0; padding-inline-start: 1.25rem; }
        .summary a, .error { color: #b91c1c; font-weight: 600; }
        .error { margin-block: 0 .25rem; }
        .status { padding: .75rem 1rem; border-inline-start: 4px solid #15803d; background: #f0fdf4; color: #14532d; }
        .back { margin-block-start: 2rem; padding-block-start: 1.25rem; border-block-start: 1px solid #e7e5e4; }
        .visually-hidden {
            position: absolute !important; inline-size: 1px; block-size: 1px; margin: -1px; padding: 0;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #1c1917; color: #fafaf9; }
            header, .back { border-color: #44403c; }
            input[type=email], input[type=password] { background: #292524; border-color: #a8a29e; }
            input[aria-invalid=true] { border-color: #fca5a5; }
            input[readonly] { background: #1c1917; }
            .hint { color: #d6d3d1; }
            button { color: #1c1917; background: #fafaf9; border-color: #fafaf9; }
            .summary { border-color: #fca5a5; }
            .summary a, .error { color: #fca5a5; }
            .status { border-color: #4ade80; background: #052e16; color: #dcfce7; }
        }
    </style>
</head>
<body>
    <header>{{ $siteName }}</header>
    <main lang="{{ $copyLang }}" dir="{{ $copyDir }}">
        @yield('content')
        <p class="back"><a href="{{ $homePath }}">{{ $t('back', ['site' => $siteName]) }}</a></p>
    </main>
</body>
</html>
