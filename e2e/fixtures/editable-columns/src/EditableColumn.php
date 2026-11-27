<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\E2e\EditableColumns;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class EditableColumn extends Component
{
    public const array COLUMNS = ['checkbox', 'toggle', 'text-input', 'select', 'custom-select'];

    public const array RESPONSES = ['success', 'validation', 'server', 'transport'];

    #[Locked]
    public string $column;

    #[Locked]
    public string $response;

    public bool|string $savedValue;

    public function mount(string $column, string $response): void
    {
        $this->column = $column;
        $this->response = $response;
        $this->savedValue = in_array($column, ['checkbox', 'toggle'], true) ? false : 'before';
    }

    public function updateTableColumnState(string $column, string $row, bool|string $value): array
    {
        abort_unless($column === $this->column && $row === '1', 404);

        if ($this->response === 'validation') {
            return ['error' => 'The fixture refused this value.'];
        }

        $this->savedValue = $value;

        return ['error' => null];
    }

    public function render(): View
    {
        return view('kitsune-editable-columns::editable-column');
    }
}
