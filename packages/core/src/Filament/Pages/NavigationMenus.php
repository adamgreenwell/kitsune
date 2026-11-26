<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\SiteMenu;
use Kitsune\Core\Themes\SiteMenuItems;
use Kitsune\Core\Themes\SiteMenus;

final class NavigationMenus extends SiteNavigationPage
{
    protected static ?string $slug = 'navigation-menus';

    public function getTitle(): string
    {
        return __('kitsune::navigation.menus');
    }

    public function table(Table $table): Table
    {
        $site = self::site();

        return $table
            ->query(SiteMenu::query()->where('site_id', $site->id)->where('org_id', $site->org_id))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('kitsune::navigation.name'))->searchable(),
                TextColumn::make('handle')->label(__('kitsune::navigation.handle')),
            ])
            ->headerActions([
                Action::make('createMenu')->label(__('kitsune::navigation.new_menu'))
                    ->authorize(self::canAccess(...))
                    ->schema(self::fields(creating: true))
                    ->action(fn (Action $action, array $data) => self::saveMenu($action, $data)),
            ])
            ->recordActions([
                Action::make('editMenu')->label(__('kitsune::navigation.edit'))
                    ->authorize(self::canAccess(...))
                    ->fillForm(fn (SiteMenu $record): array => $record->only(['name', 'items', 'version']))
                    ->schema(self::fields(creating: false))
                    ->action(fn (Action $action, array $data, SiteMenu $record) => self::saveMenu($action, $data, $record)),
                Action::make('removeMenu')->label(__('kitsune::navigation.remove'))
                    ->authorize(self::canAccess(...))->requiresConfirmation()->color('danger')
                    ->modalDescription(fn (SiteMenu $record): string => __('kitsune::navigation.confirm_menu_removal', ['name' => $record->name]))
                    ->fillForm(fn (SiteMenu $record): array => ['version' => $record->version])
                    ->schema([Hidden::make('version')->required()->rule('integer')])
                    ->action(function (Action $action, SiteMenu $record, array $data): void {
                        try {
                            app(SiteMenus::class)->remove(self::site(), $record, (int) ($data['version'] ?? 0));
                        } catch (ValidationException $failure) {
                            self::showFailure($action, $failure);
                        }
                    }),
            ]);
    }

    /** @return list<Component> */
    private static function fields(bool $creating): array
    {
        return [
            TextInput::make('name')->label(__('kitsune::navigation.name'))->required()->maxLength(120),
            $creating
                ? TextInput::make('handle')->label(__('kitsune::navigation.handle'))->required()->maxLength(64)
                : Hidden::make('version')->rule('integer'),
            Repeater::make('items')->label(__('kitsune::navigation.items'))->default([])
                ->schema([
                    ...self::linkFields(),
                    Repeater::make('children')->label(__('kitsune::navigation.children'))->default([])
                        ->schema(self::linkFields())->maxItems(SiteMenuItems::LIMIT),
                ])
                ->maxItems(SiteMenuItems::LIMIT),
        ];
    }

    /** @return list<Component> */
    private static function linkFields(): array
    {
        return [
            TextInput::make('label')->label(__('kitsune::navigation.label'))->required()->maxLength(120),
            Select::make('target')->label(__('kitsune::navigation.target'))->required()->searchable()
                ->options(['home' => __('kitsune::navigation.home')])
                ->getSearchResultsUsing(function (string $search): array {
                    $options = ['home' => __('kitsune::navigation.home')];
                    foreach (self::localEntries()->where('title', 'like', '%'.trim($search).'%')->orderBy('title')->limit(20)->get() as $entry) {
                        $options['entry:'.$entry->id] = self::targetLabel($entry, follows: false);
                        if ($entry->translation_group !== null) {
                            $options['group:'.$entry->translation_group] = self::targetLabel($entry, follows: true);
                        }
                    }

                    return $options;
                })
                ->getOptionLabelUsing(function (string $value): string {
                    if ($value === 'home') {
                        self::site();

                        return __('kitsune::navigation.home');
                    }

                    [$kind, $key] = array_pad(explode(':', $value, 2), 2, '');
                    $entry = match ($kind) {
                        'entry' => ctype_digit($key) ? self::localEntries()->find((int) $key) : null,
                        'group' => Str::isUuid($key) ? self::localEntries()->where('translation_group', $key)->first() : null,
                        default => null,
                    };

                    return $entry === null ? __('kitsune::navigation.unavailable') : self::targetLabel($entry, $kind === 'group');
                }),
        ];
    }

    private static function targetLabel(Entry $entry, bool $follows): string
    {
        $translation = $follows ? 'follow' : 'pin';

        return __('kitsune::navigation.'.$translation, ['title' => $entry->title ?: $entry->slug ?: (string) $entry->id])
            .($entry->status === 'published' ? '' : ' · '.__('kitsune::navigation.unpublished'));
    }

    /** @param array<string,mixed> $data */
    private static function saveMenu(Action $action, array $data, ?SiteMenu $record = null): void
    {
        $site = self::site();
        try {
            app(SiteMenus::class)->save(
                $site,
                $record === null ? (string) ($data['handle'] ?? '') : $record->handle,
                (string) ($data['name'] ?? ''),
                (array) ($data['items'] ?? []),
                $record === null ? null : (int) ($data['version'] ?? 0),
            );
        } catch (ValidationException $failure) {
            self::showFailure($action, $failure);
        }
    }

    private static function showFailure(Action $action, ValidationException $failure): void
    {
        Notification::make()->title($failure->getMessage())->danger()->send();
        $action->halt();
    }
}
