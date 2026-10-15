<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Filament\Products\CreateProduct;
use Kitsune\Commerce\Filament\Products\EditProduct;
use Kitsune\Commerce\Filament\Products\ListProducts;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;
use RuntimeException;

/**
 * Shared commerce rows with the current site's native entry form beside them.
 * Entry keeps its one Resource mapping (ADR-010); this Resource maps Sellable.
 *
 * @extends resource<Sellable>
 *
 * @internal First-party only; no extension contract before v1.2.
 */
final class ProductResource extends Resource
{
    protected static ?string $model = Sellable::class;

    protected static ?string $slug = 'storefront/products';

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return CurrencyResource::canAccess() && app(Context::class)->siteId() !== null;
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return self::canAccess() && ProductType::current() !== null
            && Currencies::supports(Org::query()->whereKey(app(Context::class)->orgId())->value('currency'));
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate() && $record instanceof Sellable
            && (string) $record->org_id === (string) app(Context::class)->orgId();
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // Removal must pass CatalogueWriter's retained-listing check and audit.
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('commerce::catalogue.product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('commerce::catalogue.products');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('listings.entry');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('commerce::catalogue.shared_section'))
                ->description(__('commerce::catalogue.shared_help'))
                ->schema([
                    TextInput::make('sku')->label(__('commerce::catalogue.sku'))->required()->maxLength(64)
                        ->extraInputAttributes(['dir' => 'ltr'])
                        ->rules(fn (?Sellable $record): array => [
                            'regex:/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D',
                            Rule::scopedUnique(Sellable::class, 'sku', $record?->getKey()),
                        ])->helperText(__('commerce::catalogue.sku_help')),
                    TextInput::make('price')->label(fn ($livewire): string => __('commerce::catalogue.price_currency', ['currency' => $livewire->currencyQuote]))
                        ->required()->maxLength(100)->inputMode('decimal')->extraInputAttributes(['dir' => 'ltr'])
                        ->helperText(__('commerce::catalogue.price_help')),
                    Select::make('stock_mode')->label(__('commerce::catalogue.stock_tracking'))->native()
                        ->options(['untracked' => __('commerce::catalogue.untracked'), 'finite' => __('commerce::catalogue.finite')])
                        ->default('untracked')->required()->rule('in:untracked,finite')->live()
                        ->disabled(fn ($livewire): bool => $livewire instanceof EditProduct),
                    TextInput::make('stock')->label(__('commerce::catalogue.stock_quantity'))->inputMode('numeric')
                        ->extraInputAttributes(['dir' => 'ltr'])->maxLength(19)
                        ->visible(fn (Get $get): bool => $get('stock_mode') === 'finite')
                        ->required(fn (Get $get): bool => $get('stock_mode') === 'finite')
                        ->disabled(fn ($livewire): bool => $livewire instanceof EditProduct)
                        ->helperText(fn ($livewire): string => __($livewire instanceof EditProduct
                            ? 'commerce::catalogue.stock_edit_help' : 'commerce::catalogue.stock_help')),
                    TextInput::make('entitlement_name')->label(__('commerce::catalogue.entitlement_name'))
                        ->maxLength(100)->extraInputAttributes(['dir' => 'ltr'])->nullable()
                        ->rules([static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value !== null && (! is_string($value) || ! EntitlementName::isName($value))) {
                                $fail(__('commerce::catalogue.invalid_entitlement'));
                            }
                        }])->helperText(__('commerce::catalogue.entitlement_help')),
                ]),
            Section::make(__('commerce::catalogue.site_section'))
                ->description(__('commerce::catalogue.site_help'))
                ->statePath('content')->model(fn ($livewire) => $livewire->contentRecord())
                ->schema(fn ($livewire): array => self::contentComponents($livewire)),
            Toggle::make('is_available')->label(__('commerce::catalogue.available'))->default(false)
                ->helperText(__('commerce::catalogue.available_help')),
        ]);
    }

    /** @return array<Component> */
    private static function contentComponents(CreateProduct|EditProduct $livewire): array
    {
        // Only actual product pages bind the type; navigation never changes the core entry context.
        $components = EntryResource::form(Schema::make($livewire)->model($livewire->contentRecord()))->getComponents();
        self::allowIncompleteDraftFields($components);

        return $components;
    }

    /** @param array<Component> $components */
    private static function allowIncompleteDraftFields(array $components): void
    {
        foreach ($components as $component) {
            if ($component instanceof FormField) {
                $name = $component->getName();

                if ((str_starts_with($name, 'values.') || str_starts_with($name, 'relations.')) && $component->isRequired()) {
                    $component->required(fn (Get $get): bool => $get('data.content.status', isAbsolute: true) !== 'draft');
                }

                continue;
            }

            foreach ($component->getChildSchemas() as $child) {
                self::allowIncompleteDraftFields($child->getComponents());
            }
        }
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku')->label(__('commerce::catalogue.sku'))->searchable()->sortable(),
            TextColumn::make('price_minor')->label(__('commerce::catalogue.price'))
                ->formatStateUsing(fn (Sellable $record): string => $record->currency.' '.MinorUnits::format($record->price_minor, $record->currency))
                ->sortable(),
            TextColumn::make('stock_display')->label(__('commerce::catalogue.stock'))
                ->state(fn (Sellable $record): string => $record->stock ?? __('commerce::catalogue.untracked')),
            TextColumn::make('site_title')->label(__('commerce::catalogue.site_title'))
                ->state(fn (Sellable $record): string => $record->listings->first()?->entry->title ?? __('commerce::catalogue.no_site_content')),
            TextColumn::make('site_availability')->label(__('commerce::catalogue.availability'))
                ->state(fn (Sellable $record): string => $record->listings->first()?->is_available
                    ? __('commerce::catalogue.available') : __('commerce::catalogue.not_available')),
        ])->recordActions([
            EditAction::make()->visible(fn (Sellable $record): bool => self::canEdit($record))
                ->authorize(fn (Sellable $record): bool => self::canEdit($record)),
            Action::make('removeSiteListing')->label(__('commerce::catalogue.remove_listing'))->color('danger')
                ->requiresConfirmation()->modalDescription(__('commerce::catalogue.remove_listing_help'))
                ->visible(fn (Sellable $record): bool => self::canAccess() && self::retainedListing($record) !== null)
                ->action(function (Sellable $record): void {
                    abort_unless(self::canAccess(), 403);
                    $listing = self::retainedListing($record);
                    abort_unless($listing !== null, 404);

                    try {
                        app(CatalogueWriter::class)->removeListing($listing->getKey());
                    } catch (RuntimeException) {
                        Notification::make()->danger()->title(__('commerce::catalogue.remove_listing_refused'))->send();

                        return;
                    }

                    Notification::make()->success()->title(__('commerce::catalogue.listing_removed'))->send();
                })->after(fn ($livewire) => $livewire->flushCachedTableRecords()),
            Action::make('removeProduct')->label(__('commerce::catalogue.remove_product'))->color('danger')
                ->requiresConfirmation()->modalDescription(__('commerce::catalogue.remove_product_help'))
                ->visible(fn (): bool => self::canAccess())
                ->action(function (Sellable $record): void {
                    abort_unless(self::canAccess(), 403);

                    try {
                        app(CatalogueWriter::class)->removeSellable($record->getKey());
                    } catch (RuntimeException) {
                        Notification::make()->danger()->title(__('commerce::catalogue.remove_product_refused'))->send();

                        return;
                    }

                    Notification::make()->success()->title(__('commerce::catalogue.product_removed'))->send();
                })->after(fn ($livewire) => $livewire->flushCachedTableRecords()),
        ])->recordUrl(fn (Sellable $record): ?string => self::canEdit($record)
            ? self::getUrl('edit', ['record' => $record]) : null)
            ->defaultSort('sku')->emptyStateHeading(__('commerce::catalogue.no_products'))
            ->emptyStateDescription(__('commerce::catalogue.start_help'));
    }

    private static function retainedListing(Sellable $record): ?Listing
    {
        return Listing::query()->withoutGlobalScope('catalogue_coherence')
            ->where('org_id', app(Context::class)->orgId())->where('site_id', app(Context::class)->siteId())
            ->where('sellable_id', $record->getKey())->first();
    }
}
