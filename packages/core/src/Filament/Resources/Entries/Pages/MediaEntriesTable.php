<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Schemas\Components\EmbeddedTable;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Tables\Contracts\HasTable;
use Kitsune\Core\Auth\EntryListAuthorization;
use LogicException;

final class MediaEntriesTable extends EmbeddedTable implements HasEmbeddedView
{
    public function toEmbeddedHtml(): string
    {
        $livewire = $this->getLivewire();

        if (! $livewire instanceof HasTable) {
            throw new LogicException('The media list renderer requires a Livewire table.');
        }

        $table = $livewire->getTable();

        if (! $table->isLoaded()) {
            return $table->toHtml();
        }

        // Filament caches the paginator for this render. Its items are the same records the table then renders.
        return app(EntryListAuthorization::class)->render($table->getRecords(), $table->toHtml(...));
    }
}
