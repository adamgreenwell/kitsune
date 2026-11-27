<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\EditableColumns;

use Illuminate\Support\Facades\Route;
use Kitsune\Core\Modules\ModuleServiceProvider;
use Livewire\Livewire;

/** Installed and enabled by the disposable browser suite. */
final class EditableColumnsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kitsune-editable-columns');
        Livewire::component('kitsune-browser-editable-column', EditableColumn::class);

        Route::get('/__e2e/livewire-module.js', static fn () => response()->file(
            base_path('vendor/livewire/livewire/dist/livewire.esm.js'),
            ['Content-Type' => 'text/javascript'],
        ));

        Route::get('/__e2e/editable-columns/{column}/{response}', static function (string $column, string $response) {
            abort_unless(in_array($column, EditableColumn::COLUMNS, true)
                && in_array($response, EditableColumn::RESPONSES, true), 404);

            return view('kitsune-editable-columns::editable-column-page', compact('column', 'response'));
        })->middleware('web');
    }
}
