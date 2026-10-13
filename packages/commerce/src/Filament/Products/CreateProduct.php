<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament\Products;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Filament\ProductResource;

/** @internal */
final class CreateProduct extends CreateRecord
{
    use InteractsWithProductForm;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    private bool $createdSuccessfully = false;

    public function mount(): void
    {
        $this->captureCurrencyQuote();
        parent::mount();
    }

    public function create(bool $another = false): void
    {
        $this->createdSuccessfully = false;
        $this->productAction(fn () => parent::create($another));

        if ($this->createdSuccessfully) {
            parent::getCreatedNotification()?->send();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        // Native success UI must wait for our outermost commit, rather than the nested business save.
        $this->createdSuccessfully = true;

        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ProductWriter::class)->createProduct($this->commerceData($data), $this->currencyQuote,
            $this->contentData($data), $this->relationData(), $data['is_available'] ?? false);
    }
}
