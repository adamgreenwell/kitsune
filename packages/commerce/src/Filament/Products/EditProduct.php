<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament\Products;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use RuntimeException;

/** @internal */
final class EditProduct extends EditRecord
{
    use InteractsWithProductForm;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ProductResource::class;

    private bool $savedSuccessfully = false;

    public function mount(int|string $record): void
    {
        try {
            $record = Sellable::catalogueKey($record);
        } catch (RuntimeException) {
            abort(404);
        }

        $this->captureCurrencyQuote();
        parent::mount($record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['sku' => $data['sku'], 'price' => MinorUnits::format($data['price_minor'], $this->currencyQuote),
            'stock_mode' => $data['stock'] === null ? 'untracked' : 'finite', 'stock' => $data['stock'],
            'entitlement_name' => $data['entitlement_name'] ?? null,
            'is_available' => $this->siteListing()->is_available ?? false, 'content' => $this->contentState()];
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->savedSuccessfully = false;
        $this->productAction(fn () => parent::save($shouldRedirect, $shouldSendSavedNotification));

        if ($this->savedSuccessfully) {
            parent::getSavedNotification()?->send();
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        $this->savedSuccessfully = true;

        return null;
    }

    public function saveFormComponentOnly(Component $component): void
    {
        // One product submission is indivisible; a partial component save must not omit the shared currency quote.
        $this->save(shouldRedirect: false);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->record = app(ProductWriter::class)->updateProduct($record->getKey(), $this->commerceData($data), $this->currencyQuote,
            $this->contentData($data), $this->relationData(), $data['is_available'] ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contentHistory')->label(__('commerce::catalogue.content_history'))
                ->visible(fn (): bool => $this->contentRecord()->exists)
                ->url(fn (): ?string => $this->contentRecord()->exists
                    ? EntryResource::getUrl('edit', ['type' => $this->contentRecord()->type_handle, 'record' => $this->contentRecord()]) : null),
            Action::make('removeListing')->label(__('commerce::catalogue.remove_listing'))->color('danger')
                ->requiresConfirmation()->modalDescription(__('commerce::catalogue.remove_listing_help'))
                ->visible(fn (): bool => $this->siteListing() !== null)
                ->action(function (): void {
                    abort_unless(ProductResource::canAccess(), 403);
                    $listing = $this->siteListing();
                    abort_unless($listing !== null, 404);

                    try {
                        app(CatalogueWriter::class)->removeListing($listing->getKey());
                    } catch (RuntimeException) {
                        Notification::make()->danger()->title(__('commerce::catalogue.remove_listing_refused'))->send();

                        return;
                    }

                    $this->productContent = null;
                    $this->fillForm();
                    Notification::make()->success()->title(__('commerce::catalogue.listing_removed'))->send();
                }),
        ];
    }
}
