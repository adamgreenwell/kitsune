<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

use function Filament\Support\original_request;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Filament\Resources\Entries\Pages\CreateEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Filament\Resources\Entries\Pages\ViewEntry;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

/**
 * One Resource for every entity type, with the type as a path segment.
 *
 * ADR-012, settled by measurement rather than reasoning. Seven admin routes
 * whether an org has three entity types or three hundred, and route:cache
 * stays safe because {type} is a parameter with nothing to go stale.
 */
class EntryResource extends Resource
{
    protected static ?string $model = Entry::class;

    /** ADR-012: the type lives beneath this segment. */
    protected static ?string $slug = 'c';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * ⚠️ NOT optional, and not a preference.
     *
     * Filament auto-registers one navigation item per Resource and calls
     * getUrl() on it while rendering the sidebar. With {type} in the URI and
     * no {type} in the current request, that throws UrlGenerationException
     * and 500s EVERY page outside /c/{type} — the dashboard included.
     *
     * Found by opening a browser during the 2026-09-07 spike, while the PHP
     * suite was seven-of-eight green. Navigation is supplied explicitly by
     * the panel instead.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->maxLength(255)
                ->helperText('Left empty for org-shared entries, which are not publicly addressable.'),
            Select::make('status')
                ->options(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'])
                ->default('draft')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('type_handle')->badge()->label('Type'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            // Record links are exactly what 500s without isPersistent: true.
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('updated_at', 'desc');
    }

    /** @return Builder<Entry|Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Filter by the RESOLVED type's id, not by the handle string.
        //
        // A global type and an org type may share a handle, so filtering on
        // type_handle would mix records from two different schemas under one
        // URL. IdentifyEntryType has already resolved exactly which type this
        // URL means, including precedence, so use its answer.
        if (app()->bound(EntryType::class)) {
            return $query->where('entry_type_id', app(EntryType::class)->getKey());
        }

        $type = request()->route()?->parameter('type')
            ?? original_request()->route()?->parameter('type');

        return is_string($type) && $type !== ''
            ? $query->where('type_handle', $type)
            : $query;
    }

    public static function getPages(): array
    {
        // Hard-coded segments BEFORE wildcards, or /{type}/create is
        // swallowed by /{type}/{record} (ADR-012 detail 1).
        return [
            'index' => ListEntries::route('/{type}'),
            'create' => CreateEntry::route('/{type}/create'),
            'view' => ViewEntry::route('/{type}/{record}'),
            'edit' => EditEntry::route('/{type}/{record}/edit'),
        ];
    }
}
