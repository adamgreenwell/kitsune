<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Concerns;

use function Filament\Support\original_request;

use Livewire\Attributes\Locked;

/**
 * Never add {type} to mount().
 *
 * ListRecords::mount(): void and EditRecord::mount(int|string $record): void
 * are signature-locked, so adding a parameter is a PHP fatal error. Livewire's
 * boot{Trait}() hook runs on both initial render and update requests, which is
 * exactly how Filament handles parent records (ADR-012 detail 1).
 */
trait InteractsWithEntryType
{
    #[Locked]
    public string $type = '';

    public function bootInteractsWithEntryType(): void
    {
        $resolved = request()->route()?->parameter('type')
            ?? original_request()->route()?->parameter('type');

        if (is_string($resolved) && $resolved !== '') {
            $this->type = $resolved;
        }
    }
}
