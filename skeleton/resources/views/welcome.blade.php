{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Deliberately dependency-free: no Vite, no CDN, no webfont. Styles are
    inline because the skeleton must render on a box with no Node toolchain
    and no outbound network (ADR-027).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Kitsune') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #fafaf9; color: #1c1917;
        }
        main { max-width: 34rem; padding: 2.5rem 2rem; }
        h1 { margin: 0 0 .25rem; font-size: 1.5rem; letter-spacing: -.01em; }
        .sub { margin: 0 0 1.75rem; color: #78716c; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: .4rem 1.25rem; margin: 0 0 1.75rem; }
        dt { color: #78716c; }
        dd { margin: 0; font-variant-numeric: tabular-nums; }
        .ok { color: #15803d; font-weight: 600; }
        p.note { margin: 0; padding-top: 1.25rem; border-top: 1px solid #e7e5e4; color: #78716c; font-size: .875rem; }
        a { color: inherit; }
        @media (prefers-color-scheme: dark) {
            body { background: #1c1917; color: #fafaf9; }
            .sub, dt, p.note { color: #a8a29e; }
            .ok { color: #4ade80; }
            p.note { border-top-color: #44403c; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Kitsune</h1>
        <p class="sub">{{ $phase }}</p>

        <dl>
            <dt>Core</dt>
            <dd data-testid="core-version">{{ $version }}</dd>

            <dt>Status</dt>
            <dd class="ok" data-testid="boot-status">Booted</dd>

            <dt>Database</dt>
            <dd data-testid="db-driver">{{ config('database.default') }}</dd>

            <dt>PHP</dt>
            <dd data-testid="php-version">{{ PHP_VERSION }}</dd>
        </dl>

        <p class="note">
            There is no admin panel yet. It arrives with the tenancy kernel and
            the schema engine — see the roadmap.
        </p>
    </main>
</body>
</html>
