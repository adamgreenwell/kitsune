<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Themes\PublicEntryRedirects;

final class NavigationRedirects extends SiteNavigationPage
{
    protected static ?string $slug = 'navigation-redirects';

    public function getTitle(): string
    {
        return __('kitsune::navigation.redirects');
    }

    public function table(Table $table): Table
    {
        $site = self::site();

        return $table
            ->query(SiteEntryRedirect::query()->where('site_id', $site->id)->where('org_id', $site->org_id))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('source_type')->label(__('kitsune::navigation.former_type'))->searchable(),
                TextColumn::make('source_slug')->label(__('kitsune::navigation.former_slug'))->searchable(),
                TextColumn::make('entry.title')->label(__('kitsune::navigation.target'))
                    ->placeholder(__('kitsune::navigation.unavailable')),
            ])
            ->headerActions([
                Action::make('createRedirect')->label(__('kitsune::navigation.new_redirect'))
                    ->authorize(self::canAccess(...))
                    ->schema([
                        TextInput::make('type')->label(__('kitsune::navigation.former_type'))->required()->maxLength(64),
                        TextInput::make('slug')->label(__('kitsune::navigation.former_slug'))->required()->maxLength(255),
                        Select::make('target')->label(__('kitsune::navigation.target'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::localEntries()->published()
                                ->where('title', 'like', '%'.trim($search).'%')->orderBy('title')->limit(20)
                                ->get()->mapWithKeys(fn (Entry $entry): array => [$entry->id => self::entryLabel($entry)])->all())
                            ->getOptionLabelUsing(fn (string $value): ?string => ctype_digit($value)
                                ? self::entryLabel(self::localEntries()->published()->find((int) $value)) : null),
                    ])
                    ->action(function (Action $action, array $data): void {
                        $site = self::site();
                        $entry = self::localEntries()->published()->findOrFail((int) ($data['target'] ?? 0));
                        try {
                            app(PublicEntryRedirects::class)->recordFromAdmin(
                                request(), $site, $entry, (string) ($data['type'] ?? ''), (string) ($data['slug'] ?? ''), 'site.home',
                            );
                        } catch (ValidationException $failure) {
                            Notification::make()->title($failure->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('removeRedirect')->label(__('kitsune::navigation.remove'))
                    ->authorize(self::canAccess(...))->requiresConfirmation()->color('danger')
                    ->action(fn (SiteEntryRedirect $record) => app(PublicEntryRedirects::class)->remove(self::site(), $record)),
            ]);
    }

    private static function entryLabel(?Entry $entry): ?string
    {
        return $entry === null ? null : ($entry->title ?: $entry->slug ?: (string) $entry->id);
    }
}
