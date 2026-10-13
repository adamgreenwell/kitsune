<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament\Products;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Filament\CurrencyResource;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use RuntimeException;

/** @internal */
final class ListProducts extends ListRecords
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setupStorefront')->label(__('commerce::catalogue.setup'))->requiresConfirmation()
                ->modalDescription(__('commerce::catalogue.setup_help'))
                ->visible(fn (): bool => ProductType::current() === null)->action(fn () => $this->setupStorefront()),
            Action::make('currency')->label(__('commerce::catalogue.currency_title'))->url(fn (): string => CurrencyResource::getUrl()),
            CreateAction::make()->visible(fn (): bool => ProductResource::canCreate())
                ->authorize(fn (): bool => ProductResource::canCreate()),
        ];
    }

    public function setupStorefront(): void
    {
        abort_unless(ProductResource::canAccess(), 403);
        $definition = app(BlueprintRegistry::class)->get('storefront');
        abort_unless($definition !== null, 404);

        try {
            // Blueprint apply owns its intent/receipt and DDL recovery; never enclose it in an admin transaction.
            BlueprintApplier::apply($definition);
        } catch (RuntimeException) {
            Notification::make()->danger()->title(__('commerce::catalogue.setup_refused'))->send();

            return;
        }

        Notification::make()->success()->title(__('commerce::catalogue.setup_done'))->send();
    }
}
