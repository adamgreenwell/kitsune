{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Editable column fixture</title>
        @livewireStyles
    </head>
    <body>
        @livewire('kitsune-browser-editable-column', ['column' => $column, 'response' => $response])
        @livewireScriptConfig
        <script type="module">
            import { Livewire, Alpine } from '/__e2e/livewire-module.js';
            import column from '/js/filament/tables/components/columns/{{ $column === 'custom-select' ? 'select' : $column }}.js';

            Alpine.data('editableColumn', column);
            Livewire.start();
        </script>
    </body>
</html>
